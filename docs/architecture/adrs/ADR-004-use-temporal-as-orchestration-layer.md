# ADR-004 — Use Temporal as orchestration layer for long-running payment workflows

**Status:** <span style="color:green">Accepted</span>

## Context

The platform has business flows that are:

* long-running,
* externally dependent,
* stateful,
* asynchronous,
* retry-sensitive,
* prone to partial failure.

Examples:

* payment authorization/capture,
* refund processing,
* waiting for external webhook confirmation,
* future reconciliation and payout processes.

These flows are difficult to model safely with only synchronous code or ad hoc queue choreography.

## Decision

Use **Temporal** as the orchestration engine for long-running business workflows.

Temporal will be responsible for:

* durable workflow state,
* waiting for external signals,
* timeouts,
* retry-aware orchestration,
* coordination of activities,
* explicit workflow visualization and recovery behavior.

Temporal will **not** replace:

* RabbitMQ operational queues,
* Kafka event streaming,
* service-owned domain logic,
* PostgreSQL transactional storage.

## Alternatives considered

### Alternative A — Orchestrate only through ad hoc queue choreography

Pros:

* fewer moving parts,
* simpler stack on paper.

Cons:

* harder to reason about long-running flow state,
* timeout handling becomes custom logic,
* recovery and observability are weaker,
* compensation logic becomes fragmented,
* harder to explain and maintain reliably.

### Alternative B — Keep orchestration fully inside payment-domain service

Pros:

* one less service,
* simpler initial code flow.

Cons:

* mixes orchestration with domain state ownership,
* makes async waiting logic more awkward,
* becomes harder to scale workers independently,
* makes architecture less explicit.

## Consequences

Positive:

* durable orchestration for complex async flows,
* clear model for waits, retries, signals, and timeouts,
* better fit for payment/refund workflows,
* strong learning and portfolio value.

Negative:

* extra platform complexity,
* requires discipline around deterministic workflow code,
* workflow versioning must be handled carefully,
* not every async task should become a workflow.

Architectural rule:
Temporal is for **business process orchestration**, not for generic background jobs or analytics processing.
