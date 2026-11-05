# OmniTix Architecture

> Full roadmap: Phases 0 → 12. See `PLAN.md`.

## Service Boundaries

| Service | Tech | Owns | Talks to |
|---|---|---|---|
| `laravel-core` | PHP 8.4 / Laravel 13 | users, organizers, venues, events, orders, tickets | Postgres (`app`), Kafka, Go (HTTP), Rust (HTTP), Kotlin (HTTP) |
| `go-inventory` | Go 1.23+ | Redis seat & GA holds | Redis, Kafka, Postgres (read-only via Laravel, no direct writes) |
| `rust-crypto` | Rust 1.83+ (edition 2021) | Ed25519 signing & verification | (stateless) |
| `kotlin-ledger` | Kotlin 2.4 / Spring Boot 3.5 / JVM 25 | double-entry ledger | Postgres (`ledger`), Kafka |
| `nestjs-ws` | NestJS 10 | WebSocket fan-out to browsers | Kafka, Laravel (HTTP, for snapshot) |
| `nextjs-storefront` | Next.js 14 | SSR catalog + seat map + checkout | Laravel only (Go/Rust/Kotlin reached transitively) |

## Data Flow — Seat Purchase

1. User clicks Seat 12B in Next.js.
2. Next.js → **Laravel** `POST /api/v1/seats/hold` → **Laravel proxies to Go** `POST /api/v1/seats/hold`.
3. Go executes `SET event:{id}:seat:{seat_id} user:{uid} EX 600 NX` in Redis, creates a `HoldToken` (ULID).
4. Go publishes `seat.held` to Kafka (partition key: `event_occurrence_id`).
5. NestJS consumes `seat.held`, broadcasts over WebSocket → all viewers see Seat 12B as held.
6. User pays; Next.js → **Laravel** `POST /api/v1/orders/checkout`.
7. **Laravel** (synchronously, in one request):
   a. Verifies every hold is still alive via Go `GET /api/v1/seats/states`.
   b. Creates Order + OrderItems in Postgres (`app` schema).
   c. Calls Kotlin `POST /api/v1/charge` → Kotlin posts double-entry journal, publishes `payment.completed`.
   d. Calls Go `POST /api/v1/seats/confirm` per seat (best-effort).
   e. Creates Ticket rows (status `pending`).
   f. Calls Rust `POST /api/v1/sign` per ticket → receives `qr_payload`/`signature`/`key_id`.
   g. Updates tickets to `issued`, marks Order `fulfilled`.
   h. Dispatches `SendTicketEmailJob`.
8. Next.js receives the `{order, tickets}` response including signed QR fields and displays it.
9. Go publishes `seat.sold` → NestJS broadcasts final state.
10. Laravel's Kafka consumer receives `payment.completed` → idempotently stores `payment_id`/`provider_ref` on Order.

## Data Flow — Hold Expiry (user abandons)

1. User closes tab. Redis TTL keeps counting.
2. At T=600s, Redis expires the key.
3. Redis fires keyspace notification → Go subscriber catches it.
4. Go publishes `seat.expired` to Kafka.
5. NestJS broadcasts → Seat 12B turns green for everyone.
6. Laravel's Kafka consumer decrements `event_occurrences.capacity_held`.

## Rules of the Road

- **No cross-service database access.** Each service owns its data.
- **Sync calls** = gRPC in design; **HTTP is the actual call path in v1** (gRPC is present and tested but Laravel uses HTTP gateways to keep dev simple).
- **Async events** = Kafka (schemas in `events/`).
- **All timestamps** stored in UTC.
- **All money** stored as integer cents (`BIGINT`).
- **Schema isolation:** Laravel owns `app`; Kotlin owns `ledger`; `reporting` is read-only views.

## Host Port Map (Fedora dev)

| Service | Container Port | Host Port | Notes |
|---|---|---|---|
| Postgres | 5432 | **5433** | Avoids clash with native host Postgres |
| Redis | 6379 | **6380** | Avoids clash with any host Redis |
| Kafka | 9092 (internal) / 29092 (external) | **29092** | KRaft mode, no Zookeeper |
| SeaweedFS | 8333 (S3) / 9333 (UI) | **9000 / 9001** | S3-compatible object storage |
| Laravel | 8000 | 8000 | |
| Go Inventory | 8080 (HTTP) / 50051 (gRPC) | 8080 / 50051 | |
| Rust Crypto | 50054 (HTTP) / 50052 (gRPC) | 50054 / 50052 | |
| Kotlin Ledger | 8082 (HTTP) / 50053 (gRPC) / 8081 (actuator) | 8082 / 50053 / 8081 | |
| NestJS WS | 3001 | 3001 | |
| Next.js | 3000 | 3000 | |

> **Note**: Compose brings up Postgres, Redis, Kafka, SeaweedFS, and Laravel. Go, Rust, Kotlin, NestJS, and Next.js are run on the host (their `Makefile`/`gradlew bootRun`/`cargo run`/`npm run dev`). Laravel reaches them via `host.docker.internal` (mapped in `docker-compose.yml`).