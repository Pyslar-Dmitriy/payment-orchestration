### TASK-052 — Implement the 'create payment' use case

#### What to do
Create an application use case that:
- accepts the command;
- creates the payment;
- creates a payment attempt;
- writes payment history;
- writes an outbox event;
- returns a DTO.

#### Done criteria
- the whole operation is transactional;
- the outbox record is saved in the same transaction;
- duplicate requests are handled correctly at the upper layer.

## Result

**Files created/modified:**

- `apps/payment-domain/app/Application/Payment/InitiatePayment.php` — rewrote to add: idempotency check (returns existing payment with `created: false` on duplicate key), `PaymentAttempt` creation (attempt_number=1, status=pending) inside the transaction, enriched DTO with `attempt_id` and `created` flag, `provider_id` wired through.
- `apps/payment-domain/app/Interfaces/Http/Controllers/InitiatePaymentController.php` — strips `created` from response body and returns 201 for new payments, 200 for idempotent replays.
- `apps/payment-domain/app/Interfaces/Http/Requests/InitiatePaymentRequest.php` — added `provider_id` as a required string field (max 100 chars).
- `apps/merchant-api/app/Infrastructure/PaymentDomain/PaymentDomainClient.php` — updated docblock to include `idempotency_key` and `provider_id` in payload type.
- `apps/merchant-api/app/Interfaces/Http/Controllers/InitiatePaymentController.php` — passes `provider_id` (from config `services.payment_domain.default_provider`) and `idempotency_key` (from header, or a generated UUID if absent) to the payment-domain client.
- `apps/merchant-api/config/services.php` — added `default_provider` key (`DEFAULT_PAYMENT_PROVIDER` env var, defaults to `'mock'`).
- `apps/payment-domain/tests/Feature/Payment/InitiatePaymentTest.php` — updated all fixtures with `provider_id`, added tests for attempt creation, idempotent replay (200), no extra writes on duplicate.
- `apps/payment-domain/tests/Feature/Payment/ShowPaymentTest.php` — added `provider_id` to fixture.
- `apps/payment-domain/docs/payment-domain.postman_collection.json` — added `provider_id` to request bodies, updated 201 response to include `attempt_id`, added 200 idempotent replay example.

**Design decisions:**

- `provider_id` is required at initiation because a payment attempt without a known provider has no meaning. The merchant-api supplies the default provider from config until TASK-073 implements routing strategy.
- Idempotency is enforced at the use-case layer (not only the DB unique constraint) to return a clean 200 rather than a 500/conflict on duplicate keys.
- The `created` flag is stripped from the response body before it reaches the caller — it is internal signaling between use-case and controller only.