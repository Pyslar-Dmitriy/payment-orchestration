### TASK-053 — Implement payment status update use cases

#### What to do
Create separate use cases for:
- mark pending provider
- mark authorized
- mark captured
- mark failed
- mark refunding
- mark refunded

#### Important
Each use case must:
- validate the current status;
- write status history;
- write an outbox event.

#### Done criteria
- arbitrary status jumps are impossible;
- status changes only through the application layer;
- an event is always publishable through the outbox.

#### Known gap to fix here (from TASK-043 review)
`POST /refunds` (payment-domain) currently guards only that `amount ≤ payment.amount`. It does **not** track cumulative refunded amounts, so two concurrent partial refunds of 3000 each against a 5000 payment can both pass the guard and together exceed the original amount.

When implementing the `mark refunding` / `mark refunded` use cases, enforce the cumulative check:
- Sum all existing refunds for the payment (`SELECT SUM(amount) FROM refunds WHERE payment_id = ?`) inside the same DB transaction as the new refund insert.
- Reject if `existing_refunded + new_amount > payment.amount`.
- This check should use a pessimistic lock (`SELECT ... FOR UPDATE` on the payment row) to prevent a race between concurrent refund requests.