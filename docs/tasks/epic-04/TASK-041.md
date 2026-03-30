# TASK-041 — Implement POST /payments

### Create an endpoint for creating a payment intent.

### Request fields:
- `amount`;
- `currency`
- `external_order_id`
- `customer reference`
- `payment method token/reference`
- `metadata`

### Logic:
- validation;
- idempotency check;
- command generation in the payment-domain;
- return status created/pending.

## Readiness Criteria
- a repeated request with the same key does not create a duplicate;
- response is stable;
- correlation ID is passed on.