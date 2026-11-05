# 🎟️ OmniTix — Polyglot Ticketing Platform

> 💡 **A distributed, high-concurrency ticketing platform that solves the hardest problems in live events: *atomic seat locking*, *cryptographically secure entry*, and *auditable financial ledgers*.**

OmniTix is a **polyglot microservices** monorepo built for flash sales and high-demand events. It combines **Next.js** for the storefront, **Go + Redis** for sub-millisecond seat holds, **Rust** for unforgeable Ed25519 ticket signatures, **Kotlin/Spring Boot** for double-entry financial accounting, **NestJS** for real-time WebSocket fan-out, and **Laravel** for the core domain and admin APIs. 

The architecture demonstrates real-world distributed systems patterns: atomic Redis locks via Lua scripts, event-driven state via Kafka, isolated database schemas, and a fully containerized Kubernetes deployment.

---

## ✨ Features

### ⚡ High-Concurrency Seat Holds (Go + Redis)
- **Atomic Locking** — `SET NX` and Lua scripts guarantee a seat is never double-booked, even under 50k concurrent users.
- **Best Available Algorithm** — Automatically finds contiguous seats closest to the stage, avoiding orphan seats.
- **TTL Expiry** — Keyspace notifications automatically release abandoned carts after 10 minutes.

### 🔐 Cryptographic Ticket Verification (Rust)
- **Ed25519 Signatures** — Tickets are signed using a key-versioned Ed25519 keypair, making them offline-verifiable and unforgeable.
- **JWKS Public Keys** — Public keys are exposed via an HTTP endpoint for third-party scanner integration.
- **QR Canonicalization** — Uses RFC 8785 JSON canonicalization to ensure deterministic payload hashes across all languages.

### 💰 Double-Entry Financial Ledger (Kotlin)
- **Immutable Journal** — Every charge, refund, and payout creates a balanced journal entry (Debits == Credits).
- **Mock Payment Adapter** — Simulates a Stripe-like gateway with configurable failure rates and latency.
- **Reconciliation** — Automated nightly checks guarantee the ledger never drifts out of balance.

### 📡 Real-Time Gateway (NestJS)
- **Kafka Consumer** — Listens to `seat.*` and `ga.*` events to keep thousands of clients in sync.
- **Redis Pub/Sub** — Scales horizontally across multiple pods.
- **Message Batching** — Coalesces rapid seat updates into 50ms bursts to prevent client overload.

### 🎨 Real-Time Storefront (Next.js)
- **Live Seat Map** — SVG-rendered map that updates instantly via WebSockets when seats are held or sold.
- **Guest Checkout** — Look up orders and retrieve QRs via email without creating an account.
- **Scanner PWA** — A web-based scanner interface for gate staff to validate tickets.

---

## 🛠️ Tech Stack

| Layer | Technology |
|-------|-----------|
| **Storefront** | Next.js 14 (App Router), React 18, Tailwind CSS, Zustand |
| **Core API / Admin** | Laravel 11 (PHP 8.3), Sanctum, DomPDF |
| **Inventory Engine** | Go 1.23, gRPC, Redis, Kafka |
| **Crypto Engine** | Rust 1.83, Tonic gRPC, Ed25519-Dalek |
| **Financial Ledger** | Kotlin 2.4, Spring Boot 3.5, gRPC, JPA |
| **Real-Time Gateway** | NestJS 10, Socket.IO, Redis Adapter |
| **Event Bus** | Apache Kafka (KRaft mode) |
| **Databases** | PostgreSQL 18 (Isolated `app` and `ledger` schemas), Redis 7 |
| **Orchestration** | Kubernetes (k3d), Docker, Helm |
| **Observability** | Prometheus, Grafana |

---

## 🏗️ Architecture & Polyglot Persistence

### Why Six Services?

OmniTix is designed to separate concerns by **access pattern and runtime characteristics**:

| Service | Language | Responsibility | Why this language? |
|---------|----------|----------------|-------------------|
| **Inventory** | Go | Atomic seat locks, Redis pool management | Concurrency, raw speed, low memory footprint |
| **Crypto** | Rust | Ed25519 signing, canonicalization | Memory safety, zero-cost abstractions, cryptographic libraries |
| **Ledger** | Kotlin | Double-entry accounting, payouts | Strong typing, JVM ecosystem, Spring Boot maturity |
| **Gateway** | NestJS | WebSocket fan-out, batching | Asynchronous I/O, Socket.IO ecosystem |
| **Core** | Laravel | Relational data, admin, orchestration | Rapid development, Eloquent ORM, queue workers |
| **Storefront** | Next.js | SSR, live UI | SEO, React ecosystem, Edge rendering |

### System Architecture Diagram

```text
┌─────────────────────────────────────────────────────────────────┐
│                   Browser (Next.js Storefront)                  │
└───────┬─────────────────────┬─────────────────────┬─────────────┘
        │ HTTP                │ HTTP                │ WebSocket
        ▼                     ▼                     ▼
┌───────────────┐     ┌───────────────┐     ┌───────────────┐
│ Laravel Core  │     │ Rust Crypto   │     │ NestJS WS     │
│ (Orchestrator)│     │ (Sign/Verify) │     │ (Live Seats)  │
└───────┬───────┘     └───────────────┘     └───────┬───────┘
        │                                           │
        ├─────────────────────┬─────────────────────┤
        ▼                     ▼                     ▼
┌───────────────┐     ┌───────────────┐     ┌───────────────┐
│ Go Inventory  │     │ Kotlin Ledger │     │ Kafka         │
│ (Atomic Holds)│     │ (Double-Entry)│     │ (Event Bus)   │
└───────┬───────┘     └───────┬───────┘     └───────────────┘
        │                     │
        ▼                     ▼
┌───────────────┐     ┌───────────────┐
│ Redis (Locks) │     │ Postgres      │
│               │     │ (app, ledger) │
└───────────────┘     └───────────────┘
```

---

## 📦 Microservice Deep Dive

### 1. Go Inventory Engine (`services/go-inventory`)
- **Ports:** `8080` (HTTP), `50051` (gRPC)
- **Role:** The absolute source of truth for seat holds.
- **Key Tech:** Redis Lua scripts for `HoldGAZone` and atomic `SETNX` for `HoldSeat`.
- **Why it matters:** Laravel is too slow for 50k concurrent lock attempts. Go handles this at the memory level.

### 2. Rust Crypto Engine (`services/rust-crypto`)
- **Ports:** `50054` (HTTP), `50052` (gRPC)
- **Role:** Signs ticket payloads so they cannot be forged.
- **Key Tech:** `ed25519-dalek` for signatures, `serde_json_canonicalizer` for deterministic hashing.
- **Why it matters:** A compromised database shouldn't allow fake tickets to be generated. Offline verification is a hard requirement.

### 3. Kotlin Financial Ledger (`services/kotlin-ledger`)
- **Ports:** `8082` (HTTP), `50053` (gRPC), `8081` (Actuator)
- **Role:** Processes payments and payouts with immutable double-entry accounting.
- **Key Tech:** Spring Data JPA, Flyway migrations, strict schema isolation (the `ledger` schema is unreadable by Laravel).
- **Why it matters:** Ticketing is a financial product. Bugs here cost real money. Kotlin's type system prevents mismatched debits/credits.

### 4. NestJS Real-Time Gateway (`services/nestjs-ws`)
- **Ports:** `3001` (HTTP + WebSocket)
- **Role:** Consumes Kafka events and broadcasts them to browser clients.
- **Key Tech:** `socket.io` with `@socket.io/redis-adapter` for multi-pod fan-out.
- **Why it matters:** Users need to see a seat turn yellow within 100ms of someone else clicking it.

### 5. Laravel Core & Admin (`services/laravel-core`)
- **Ports:** `8000` (HTTP)
- **Role:** Manages relational data (users, events, venues, tickets) and acts as the API Gateway for the frontend.
- **Key Tech:** Eloquent ORM, Sanctum auth, DomPDF for ticket generation, Kafka consumers for async state sync.
- **Why it matters:** Provides the mature tooling needed for admin CRUD operations and background jobs.

### 6. Next.js Storefront (`services/nextjs-storefront`)
- **Ports:** `3000` (HTTP)
- **Role:** The customer-facing UI.
- **Key Tech:** App Router, Server-Side Rendering, Zustand state management, SVG seat rendering.
- **Why it matters:** Fast First Contentful Paint (FCP) and live seat map updates via WebSockets.

---

## 📁 Project Structure

```text
OmniTix/
├── contracts/                   ← Protobuf definitions (buf.yaml)
├── events/                      ← JSON Schemas for Kafka events
├── infra/                       ← Postgres init, Redis config, SeaweedFS
├── k8s/                         ← Kubernetes manifests & Helm charts
├── docker-compose.yml           ← Local infrastructure (Postgres, Redis, Kafka)
├── Makefile                     ← Build/test orchestration
│
└── services/
    ├── go-inventory/            ← Go (Redis Lua locks, BestAvailable)
    ├── rust-crypto/             ← Rust (Ed25519 signing, canonicalization)
    ├── kotlin-ledger/           ← Kotlin (Double-entry ledger, Payouts)
    ├── laravel-core/            ← PHP (Admin API, Order orchestration)
    ├── nestjs-ws/               ← TypeScript (Socket.IO, Kafka consumer)
    └── nextjs-storefront/       ← TypeScript (React, Server Components)
```

---

## 🚀 Quick Start

### Prerequisites
- **Docker Desktop** (with Kubernetes enabled or `k3d` installed)
- **Node.js 22+**, **Go 1.23+**, **Rust 1.83+**, **Java 25+**, **PHP 8.4+**

### 1. Start the Infrastructure
```bash
git clone https://github.com/li-cs-developer/OmniTix.git
cd OmniTix
./scripts/dev-up.sh
```
*(This starts Postgres, Redis, Kafka, and SeaweedFS via Docker Compose).*

### 2. Run the Services (Local Dev)
Open separate terminals for each service:
```bash
# Terminal 1: Go Inventory
cd services/go-inventory && go run ./cmd/server

# Terminal 2: Rust Crypto
cd services/rust-crypto && cargo run

# Terminal 3: Kotlin Ledger
cd services/kotlin-ledger && ./gradlew bootRun

# Terminal 4: NestJS WS
cd services/nestjs-ws && npm run start:dev

# Terminal 5: Laravel Core
cd services/laravel-core && php artisan serve

# Terminal 6: Next.js Storefront
cd services/nextjs-storefront && npm run dev
```

### 3. Seed the Database
```bash
cd services/laravel-core
php artisan migrate
php artisan db:seed
```

Open `http://localhost:3000` to view the storefront!

### 4. Deploy to Kubernetes (Optional)
```bash
k3d cluster create omnitix --port "18080:80@loadbalancer"
# Build and import images
# Apply manifests
kubectl apply -k infra/k8s/
```
Access the cluster at `http://localhost:18080`.

---

## 🔌 API Overview

### Core API (Laravel)
| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/v1/events` | List published events |
| `GET` | `/api/v1/events/{slug}` | Event details + seat map |
| `POST` | `/api/v1/orders/checkout` | Create order, charge ledger, issue ticket |
| `GET` | `/api/v1/seats/states` | Get live seat states for an event |

### Admin API (Laravel - Sanctum Protected)
| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/v1/admin/auth/login` | Get Sanctum bearer token |
| `POST` | `/api/v1/admin/events` | Create a new event |
| `GET` | `/api/v1/admin/dashboard/summary` | Fetch organizer revenue metrics |

### Internal gRPC (Go, Rust, Kotlin)
| Service | Method | Description |
|---------|--------|-------------|
| **Inventory** | `HoldSeat` | Atomically lock a seat in Redis |
| **Crypto** | `SignTicket` | Sign a payload with Ed25519 |
| **Ledger** | `Charge` | Post a double-entry journal transaction |

---

## 🧪 Testing

Each microservice has its own test suite tailored to its language and ecosystem.

```bash
# Rust (Crypto)
cd services/rust-crypto && cargo test

# Kotlin (Ledger)
cd services/kotlin-ledger && ./gradlew test

# Go (Inventory)
cd services/go-inventory && go test ./...

# Laravel (Core)
cd services/laravel-core && php artisan test

# Next.js (Storefront)
cd services/nextjs-storefront && npm run typecheck
```

---

## 🐛 Troubleshooting

**Issue: `localhost:3000` shows an empty event catalog**
- The Next.js app is running, but Laravel cannot reach Postgres. 
- Fix: Check `docker ps` to ensure the `omnitix-postgres` container is healthy.

**Issue: Seat map loads, but clicking a seat returns a 500 error**
- The Go Inventory service is not running, or the Laravel `INVENTORY_HTTP_URL` is misconfigured.
- Fix: Ensure Go is running on port 8080 and check `.env` files.

**Issue: "Could not connect to host.docker.internal"**
- This happens when running Laravel inside Docker on Linux.
- Fix: Update `.env` to use `127.0.0.1` or the Docker bridge gateway IP.

**Issue: Kafka consumer crash-looping (`This server does not host this topic-partition`)**
- Kafka was restarted and lost its in-memory topics.
- Fix: Run the topic creation script: `./scripts/create-topics.sh`.

---

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
