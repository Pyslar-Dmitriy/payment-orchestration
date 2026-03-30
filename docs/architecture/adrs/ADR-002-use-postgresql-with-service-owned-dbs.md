# ADR-002 — Use PostgreSQL with service-owned databases

**Status:** <span style="color:green">Accepted</span>

## Context

The platform needs a durable transactional store for:

* payment lifecycle state,
* provider interaction records,
* raw webhooks,
* deduplication keys,
* ledger entries,
* callback delivery history,
* reporting projections.

The system is microservice-oriented. A decision is needed on whether services should:

* share one database,
* share one schema,
* or own separate databases/schemas.

## Decision

Use **PostgreSQL** as the primary relational database technology and apply **service ownership over data**.

Each service will own its own database or schema boundary and write only to its own storage.

No service may directly query another service’s transactional tables.
Cross-service communication must happen through:

* HTTP API,
* RabbitMQ messages,
* Kafka events,
* Temporal orchestration signals/activities,
* projections/read models.

## Alternatives considered

### Alternative A — One shared database for all services

Pros:

* easier joins,
* simpler early setup,
* easier ad hoc queries.

Cons:

* destroys service ownership,
* increases coupling,
* makes schema changes dangerous,
* encourages hidden cross-service dependencies,
* weakens architectural credibility.

### Alternative B — NoSQL/event store as primary storage everywhere

Pros:

* flexible schema in some domains,
* potentially strong fit for append-only or event-heavy workloads.

Cons:

* unnecessary complexity for this project,
* weak fit for relational constraints and transactional updates in core payment state,
* complicates learning path without enough benefit in v1.

## Consequences

Positive:

* clear ownership boundaries,
* strong transactional semantics inside a service,
* good fit for outbox/inbox tables,
* excellent fit for ledger and status history,
* realistic production pattern.

Negative:

* requires explicit duplication of read concerns,
* requires event/projection strategy for cross-service reads,
* local environment is a little more complex.

Domain note:
PostgreSQL is especially suitable here because the platform needs correctness, constraints, indexing, deduplication, append-only history, and audit-friendly records.
