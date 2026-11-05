# OmniTix Event Schemas

Kafka event contracts for asynchronous inter-service communication.

## Conventions

### Topic Names
```
omnitix.<aggregate>.<event>
```

- **Aggregate** — the domain entity (`seat`, `ga`, `payment`, `ticket`, `refund`, `payout`)
- **Event** — past-tense verb (`held`, `expired`, `sold`, `completed`, `failed`, `issued`, `scanned`, `settled`)

### Envelope
Every message has the same envelope (see `_envelope.schema.json`). The `payload` field is event-specific.

### Schema Versioning
`envelope.version` starts at `1`. Bump when the payload schema makes a **breaking change**.
Backward-compatible additions (new optional fields) do not require a version bump.

### Partition Keys
See the table below. Producers MUST set the partition key matching their event type.
Consumers MUST NOT assume global ordering — only per-partition ordering.

| Topic | Partition Key |
|---|---|
| `omnitix.seat.held` | `event_occurrence_id` |
| `omnitix.seat.expired` | `event_occurrence_id` |
| `omnitix.seat.sold` | `event_occurrence_id` |
| `omnitix.ga.held` | `event_occurrence_id` |
| `omnitix.ga.expired` | `event_occurrence_id` |
| `omnitix.ga.sold` | `event_occurrence_id` |
| `omnitix.payment.completed` | `order_id` |
| `omnitix.payment.failed` | `order_id` |
| `omnitix.refund.issued` | `order_id` |
| `omnitix.ticket.issued` | `order_id` |
| `omnitix.ticket.scanned` | `ticket_id` |
| `omnitix.payout.settled` | `organizer_id` |

### Consumer Groups
Each service that consumes events uses its own consumer group. Naming:

| Consumer | Group ID |
|---|---|
| NestJS realtime gateway | `omnitix-realtime-ws` |
| Laravel order fulfilment worker | `omnitix-laravel-fulfilment` |
| Laravel ticket signing worker | `omnitix-laravel-signing` |
| Kotlin ledger (audit log) | `omnitix-ledger-audit` |
| Analytics (Phase 10) | `omnitix-analytics` |

### Idempotency
Consumers MUST be idempotent. Every event has a unique `envelope.id`. Store
processed event IDs (at least the last N hours) and skip duplicates.

### Retention
- Event topics: **7 days** (dev), **30 days** (prod)
- Compacted topics: none in v1 (only event streams, no state topics)
```