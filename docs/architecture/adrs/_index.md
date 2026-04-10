# Payment Orchestration Platform — Architecture Decision Records

This document contains the initial set of ADRs for the payment orchestration platform.

Format:

* **Status**
* **Context**
* **Decision**
* **Alternatives considered**
* **Consequences**

## List of ADRs:
1. [ADR-001 — Use a monorepo with independently deployable services](ADR-001-use-monorepo.md)
2. [ADR-002 — Use PostgreSQL with service-owned databases](ADR-002-use-postgresql-with-service-owned-dbs.md)
3. [ADR-003 — Use RabbitMQ for operational asynchronous processing and Kafka for domain event streaming](ADR-003-use-rabbitmq-and-kafka.md)
4. [ADR-004 — Use Temporal as orchestration layer for long-running payment workflows](ADR-004-use-temporal-as-orchestration-layer.md)
5. [ADR-005 — Use outbox and inbox/dedup patterns for reliability under at-least-once delivery](ADR-005-use-outbox-and-inbox-deduplicate-patterns.md)
6. [ADR-006 — Separate webhook ingestion from webhook normalization and business application](ADR-006-separate-webhook-ingestion.md)
7. [ADR-007 — Use a dedicated append-only ledger service instead of mutable balances inside payment service](ADR-007-use-dedicated-append-only-ledger-service.md)
8. [ADR-008 — Prefer asynchronous-first payment lifecycle over synchronous finalization](ADR-008-prefer-asynchronous-first-payment-lifecycle.md)
9. [ADR-009 — Use API key authentication for merchant API access](ADR-009-merchant-api-authentication.md)
10. [ADR-010 — Compensation and rollback strategy for permanent workflow failures](ADR-010-workflow-compensation-strategy.md)
11. [ADR-011 — Provider routing strategy and circuit breaker scope](ADR-011-provider-routing-strategy.md)
12. [ADR-012 — Kafka schema evolution and breaking change strategy](ADR-012-kafka-schema-evolution.md)
13. [ADR-013 — Use Form Request classes for HTTP input validation](ADR-013-use-form-request-classes-for-http-input-validation.md)
14. [ADR-014 — Use single-action controllers](ADR-014-use-single-action-controllers.md)
15. [ADR-015 — Use HTTP status code constants instead of plain integers](ADR-015-use-http-status-code-constants.md)
16. [ADR-016 — Use readonly DTOs at use-case boundaries instead of plain arrays](ADR-016-use-readonly-dtos-at-use-case-boundaries.md)