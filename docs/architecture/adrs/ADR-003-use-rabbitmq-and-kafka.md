# ADR-003 — Use RabbitMQ for operational asynchronous processing and Kafka for domain event streaming

**Status:** <span style="color:green">Accepted</span>

## Context

The platform has two different asynchronous communication needs.

### Need 1 — Operational processing

Examples:

* raw webhook processing,
* callback delivery,
* retries with backoff,
* work distribution to background workers,
* dead-letter handling.

### Need 2 — Domain event stream

Examples:

* payment lifecycle events,
* ledger events,
* reporting projections,
* audit trail,
* replayable downstream consumers.

A single broker is unlikely to serve both purposes equally well without tradeoffs.

## Decision

Use **RabbitMQ** for operational asynchronous commands/tasks and **Kafka** for domain event streaming.

### RabbitMQ responsibilities

* work queues,
* delayed retries,
* DLQ,
* webhook processing pipeline,
* merchant callback dispatch,
* operational fan-out where replay is not the main objective.

### Kafka responsibilities

* domain lifecycle events,
* audit-friendly append-only stream,
* projection building,
* replay for downstream consumers,
* decoupled analytics/reporting.

## Alternatives considered

### Alternative A — RabbitMQ only

Pros:

* simpler stack,
* easier initial operations,
* good for worker-based async flows.

Cons:

* weak fit for durable replayable event history,
* less natural for independent projections and long-term event stream semantics,
* conflates work queues with domain events.

### Alternative B — Kafka only

Pros:

* strong event backbone,
* replay support,
* scalable consumer groups.

Cons:

* overkill for simple operational retry queues,
* more awkward for classic delayed retry and DLQ task patterns,
* higher complexity for fire-and-forget operational messaging.

## Consequences

Positive:

* clean separation of async responsibilities,
* easier reasoning about operational flow vs event flow,
* more realistic architecture for interviews and learning.

Negative:

* additional operational complexity,
* more infra to run locally,
* requires discipline in deciding what goes where.

Rule of thumb:

* If the message means **“do this work”**, use RabbitMQ.
* If the message means **“this business fact happened”**, use Kafka.
* 