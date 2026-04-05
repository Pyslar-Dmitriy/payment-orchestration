# TASK-021 — Prepare migrations and separate databases/schemas for services

### Allocate a separate database or schema ownership model for each service.

### What to consider
- payment-domain DB;
- provider DB;
- webhooks DB;
- ledger DB;
- reporting DB;
- callback DB.

## Readiness Criteria
- Each service writes only to its own database;
- No cross-service joins;
- Migrations are run in isolation.
