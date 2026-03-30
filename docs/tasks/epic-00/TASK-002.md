# [DONE] TASK-002 — Capture Architectural Decision Records

### Create a set of ADRs (Architecture Decision Records) for the most important decisions.

### Minimum ADR List:
- Why monorepo;
- Why PostgreSQL per service;
- Why RabbitMQ and Kafka simultaneously;
- Why Temporal;
- Why outbox/inbox;
- Why webhook ingest is separate from normalizer;
- Why ledger is a separate service.

## Artifacts
`docs/adr/001-monorepo.md`\
`docs/adr/002-postgres-per-service.md`\
`docs/adr/003-rabbitmq-vs-kafka.md`\
`docs/adr/004-why-temporal.md`\
`docs/adr/005-outbox-inbox.md`

## Readiness Criteria
- For each decision, there is a reason, an alternative, and a consequence;
- The documents are short and practical;
- The decisions do not contradict each other.