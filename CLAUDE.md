# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

A personal learning project: a production-style **payment orchestration platform** built with PHP/Laravel microservices. The focus is on realistic distributed systems patterns — not a full PSP, but an orchestration layer between merchants and external payment providers.

This is currently in the planning/scaffolding phase (EPIC-00 complete, EPIC-01 through EPIC-20 pending). No application code exists yet — only docs, ADRs, and diagrams.

## Planned commands (not yet implemented)

Once EPIC-01/02 are implemented, the standard development workflow will be:

```bash
# Start full local environment
docker compose up

# Per-service (from apps/<service-name>/)
php artisan test                  # Run all tests
php artisan test --filter=<name>  # Run single test
./vendor/bin/phpstan analyse      # Static analysis
./vendor/bin/pint                 # Code style (Laravel Pint)
```

A root `Makefile` is planned (TASK-010) to wrap common cross-service commands.

## Monorepo structure (planned — per TASK-010, TASK-011)

```
apps/
  merchant-api/           # Public HTTP API for merchants
  payment-domain/         # Authoritative payment state & aggregates
  payment-orchestrator/   # Temporal workers for long-running workflows
  provider-gateway/       # External PSP integration abstraction
  webhook-ingest/         # Fast ingress: signature verify, store, enqueue
  webhook-normalizer/     # Translate raw provider events → internal signals
  ledger-service/         # Append-only financial records
  merchant-callback-delivery/  # Async signed callbacks + retry/DLQ
  reporting-projection/   # Kafka consumer building read models
packages/                 # Shared Laravel packages (contracts, helpers)
contracts/                # OpenAPI specs, Kafka/RabbitMQ message schemas
infra/                    # Docker Compose, Kubernetes manifests
docs/                     # ADRs, diagrams, task definitions
tests/                    # E2E and contract tests
```

Each service follows the same internal layout (TASK-011):
```
Domain/         # Aggregates, entities, value objects, domain events
Application/    # Use cases / commands / handlers
Infrastructure/ # DB, queue adapters, provider clients
Interfaces/     # HTTP controllers, console commands, queue consumers
```

## Key architectural decisions

**RabbitMQ vs Kafka** — RabbitMQ handles operational async work (webhook processing, callback delivery queues, DLQ/retry). Kafka is the domain event bus for projections, analytics, and audit. Never use Kafka for task queues.

**Temporal** — All long-running payment and refund workflows run in Temporal (`payment-orchestrator` service). Temporal is the single source of workflow state and handles waiting for async webhook signals. Do not implement long-running flow logic outside of Temporal activities/workflows.

**Outbox pattern** — All write services (payment-domain, ledger-service) must publish messages via outbox in the same DB transaction. No direct queue publishes from domain logic.

**Inbox/dedup** — All consumers that process messages with side effects must track processed message IDs to guarantee idempotent processing.

**Service-owned databases** — Each service has its own PostgreSQL database/schema. No cross-service DB joins. Service boundaries are enforced through APIs and messages only.

**Ledger immutability** — The ledger service is append-only. No balance mutation; only double-entry postings. Financial correctness depends on this constraint.

**Webhook ingest is intentionally thin** — `webhook-ingest` only verifies signature, stores raw payload, deduplicates by `(provider, event_id)`, and enqueues a reference. All normalization and business logic happens in `webhook-normalizer`.

## Payment state machine

Valid status transitions are enforced strictly by the Payment Domain service. Status history is always preserved. No direct status overwrites — only transitions through valid state machine paths.

## Observability conventions

All services must propagate `correlation_id` and `causation_id` through HTTP headers and message metadata. Structured logs must include `payment_id` and `correlation_id`. No sensitive values (tokens, raw card references) in logs.

## ADRs

The key design decisions are documented in `docs/architecture/adrs/`. Read these before proposing architectural changes:
- ADR-001: Monorepo structure
- ADR-002: PostgreSQL with service-owned databases
- ADR-003: RabbitMQ (operational) + Kafka (streaming)
- ADR-004: Temporal for orchestration
- ADR-005: Outbox + inbox/dedup patterns
- ADR-006: Separate webhook ingest from normalization
- ADR-007: Dedicated append-only ledger
- ADR-008: Async-first payment lifecycle

## Hard constraints — never violate
- Never publish to a queue directly from domain logic (always outbox)
- Never read another service's database
- Never add long-running logic outside Temporal workflows/activities
- Never mutate ledger entries — append only
- Never log payment_id + raw card data together

## Working with tasks
Before implementing a task: read the task file in docs/tasks/.                                                                                                                                                                                                                                                   
After completing a task: update its checkbox in docs/tasks/epics.md and write a result summary in the bottom of the task file.                                                                                                                                                                                                                                             
Do not implement work from a task not listed in the current epic.
