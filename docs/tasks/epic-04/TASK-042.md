# TASK-042 — Implement GET /payments/{id}

### Send the current payment status to the merchant.

### Include to the response:
- `payment id`;
- `status`
- `amount`
- `currency`
- `timestamps`
- `provider reference`
- `last known failure reason`

## Readiness Criteria
- The request is fast;
- The response does not depend on internal tables of other services;
- The merchant sees only their own payments.