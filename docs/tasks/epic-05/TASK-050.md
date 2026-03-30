# TASK-050 — Design a Payment Data Model

### Tables:
- `payments`
- `payment_attempts`
- `payment_status_history`
- `outbox_messages`
- `processed_inbox_messages`

### Consider:
- provider reference;
- merchant reference;
- external order id;
- idempotency reference;
- failure code/reason;
- version for optimistic locking.

## Readiness Criteria
- The model covers the entire payment lifecycle;
- There is no need to break the design after adding a webhook flow.