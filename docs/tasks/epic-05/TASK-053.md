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
## Result

### Files created
- `app/Domain/Payment/Exceptions/PaymentNotFoundException.php` — thrown when a payment cannot be found by ID+merchant_id
- `app/Domain/Refund/Exceptions/RefundAmountExceededException.php` — thrown when cumulative refunds would exceed the payment amount
- `app/Application/Payment/DTO/UpdatePaymentStatusCommand.php` — shared command DTO for all status-transition use cases
- `app/Application/Payment/DTO/UpdatePaymentStatusResult.php` — shared result DTO (serializes to `payment_id` / `status`)
- `app/Application/Payment/MarkPendingProvider.php` — transitions CREATED → PENDING_PROVIDER, emits `payment.pending_provider.v1`
- `app/Application/Payment/MarkAuthorized.php` — transitions PENDING_PROVIDER/REQUIRES_ACTION → AUTHORIZED, emits `payment.authorized.v1`
- `app/Application/Payment/MarkCaptured.php` — transitions AUTHORIZED/PENDING_PROVIDER → CAPTURED, emits `payment.captured.v1`
- `app/Application/Payment/MarkFailed.php` — transitions any non-terminal → FAILED, stores failure_code/failure_reason, emits `payment.failed.v1`
- `app/Application/Payment/MarkRefunding.php` — transitions CAPTURED → REFUNDING, emits `payment.refunding.v1`
- `app/Application/Payment/MarkRefunded.php` — transitions REFUNDING → REFUNDED, emits `payment.refunded.v1`
- `app/Interfaces/Http/Controllers/TransitionPaymentStatusController.php` — single controller, dispatches to the correct use case based on the requested `status`
- `app/Interfaces/Http/Requests/TransitionPaymentStatusRequest.php` — validates `merchant_id`, `status` (allow-list of 6 values), `correlation_id`, and optional `reason`/`failure_code`/`failure_reason`
- `tests/Feature/Payment/TransitionPaymentStatusTest.php` — 25 feature tests covering happy paths, state-machine enforcement, merchant isolation, optimistic locking, and validation

### Files modified
- `routes/api.php` — added `PATCH /v1/payments/{id}/status`
- `app/Application/Refund/InitiateRefund.php` — added pessimistic lock (`lockForUpdate`) on the payment row and cumulative-refund guard inside the transaction
- `app/Interfaces/Http/Controllers/InitiateRefundController.php` — catches `RefundAmountExceededException` and returns 422
- `tests/Feature/Refund/InitiateRefundTest.php` — added 3 tests for the cumulative refund guard
- `docs/payment-domain.postman_collection.json` — added Transition Payment Status requests with happy-path and error examples

### Design decisions
- **Single controller, six use cases**: one `PATCH /payments/{id}/status` endpoint dispatches to the correct use case based on the `status` field, keeping HTTP routing simple while keeping the application layer focused.
- **Merchant isolation in use cases**: each use case fetches the payment by `(id, merchant_id)` and throws `PaymentNotFoundException` on mismatch. The controller converts this to 404, never leaking existence.
- **Optimistic locking**: the existing `Payment::transition()` method handles versioned updates and throws `PaymentConcurrencyException` on conflict; the controller converts this to 409.
- **Cumulative refund check in `InitiateRefund`**: rather than in the `MarkRefunding` use case (which only transitions status and has no refund-amount context), the fix lives where refund records are created. A pessimistic lock on the payment row prevents two concurrent requests from both passing the guard before either commits.
