# TASK-043 — Implement POST /refunds

### Add a refund API with idempotency and validation.

### Checks:
- payment exists;
- payment belongs to the merchant;
- status allows refund;
- the refund amount is valid;
- request is idempotent.

## Readiness Criteria
- duplicate refund requests do not create two transactions;
- the response format is consistent with POST /payments.