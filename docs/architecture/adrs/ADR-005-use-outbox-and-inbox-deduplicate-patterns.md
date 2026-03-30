# ADR-005 — Use outbox and inbox/dedup patterns for reliability under at-least-once delivery

**Status:** <span style="color:green">Accepted</span>

## Context

The platform relies on distributed processing with:

* HTTP requests,
* RabbitMQ workers,
* Kafka events,
* Temporal activities and signals,
* provider webhooks,
* merchant callback delivery.

In such a system, duplicate delivery and partial failure are expected. The system must avoid:

* lost events between DB commit and publish,
* duplicated business effects,
* repeated ledger posting,
* repeated state transitions,
* repeated refunds/callbacks.

## Decision

Use the following reliability patterns:

### Outbox pattern

For services that both:

* change local business state,
* and publish messages/events.

The business write and outbox insert happen in the same DB transaction.
A separate publisher sends outbox messages to RabbitMQ or Kafka.

### Inbox / processed-message tracking

For consumers that must tolerate duplicate deliveries.
Consumer records processed message ids or equivalent dedup keys before applying irreversible business effects.

### Idempotency keys

For merchant-initiated create payment and refund requests.

### Provider webhook dedup

Using provider event identifiers and raw storage.

## Alternatives considered

### Alternative A — Best-effort publish after transaction commit

Pros:

* simpler initial implementation.

Cons:

* can lose messages if process crashes between commit and publish,
* unacceptable for critical payment flow events.

### Alternative B — Exactly-once assumptions

Pros:

* sounds attractive conceptually.

Cons:

* unrealistic in most distributed payment systems,
* leads to dangerous design assumptions,
* does not match behavior of external providers and most brokers.

## Consequences

Positive:

* safer distributed processing,
* duplicate-safe business logic,
* much stronger reliability story,
* realistic production architecture.

Negative:

* extra tables and publisher/consumer complexity,
* need for cleanup policies,
* requires careful test coverage.

Implementation note:
From the business perspective, the platform aims for **effectively-once outcomes** built on top of **at-least-once infrastructure**.
