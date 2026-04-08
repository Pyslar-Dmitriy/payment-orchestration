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

## Result

### Files created / modified

**payment-domain**
- `database/migrations/2026_04_08_000001_create_refunds_table.php` — new `refunds` table (ULID pk, payment_id, merchant_id, amount, currency, status, correlation_id)
- `app/Domain/Refund/Refund.php` — Eloquent model with `HasUlids`
- `app/Application/Refund/InitiateRefund.php` — use case: creates refund record + outbox event (`refund.initiated.v1`) in one DB transaction
- `app/Interfaces/Http/Requests/InitiateRefundRequest.php` — validates payment_id (string, max 26), merchant_id (uuid), amount (integer, min 1), correlation_id (uuid)
- `app/Interfaces/Http/Controllers/InitiateRefundController.php` — looks up payment, enforces `captured` status guard and amount guard, delegates to use case
- `routes/api.php` — added `POST /api/v1/refunds`
- `tests/Feature/Refund/InitiateRefundTest.php` — 14 tests covering happy path, not found, merchant isolation, status guard, amount guard, validation, and transaction atomicity

**merchant-api**
- `app/Interfaces/Http/Requests/InitiateRefundRequest.php` — validates payment_id (string, max 26) and amount (integer, min 1)
- `app/Infrastructure/PaymentDomain/PaymentDomainClient.php` — added `initiateRefund()`: returns null on 404, throws `RequestException` on 422/5xx
- `app/Interfaces/Http/Controllers/InitiateRefundController.php` — idempotency check, calls payment-domain, passes through 422 error bodies, stores idempotency key on success
- `routes/api.php` — added `POST /api/v1/refunds` under `auth.api` middleware
- `tests/Feature/Refund/InitiateRefundTest.php` — 13 tests covering happy path, auth, validation, 404 and 422 pass-through, idempotency (replay, per-merchant scope, no-key)
- `docs/merchant-api.postman_collection.json` — added `Authenticated — Refunds` folder with Initiate Refund (happy path + replay + missing fields + no auth) and `refund_id` collection variable

### Design decisions
- Business rule validation (status guard, amount guard) lives in the **payment-domain** controller, not the merchant-api. The merchant-api passes 422 error bodies through as-is.
- Response shape `{refund_id, payment_id, status, amount, currency, correlation_id}` mirrors the POST /payments shape.
- Idempotency uses the same `idempotency_keys` table as payments — scoped by `(merchant_id, idempotency_key)`.
- The 422 pass-through from payment-domain is handled via catching `RequestException` and checking `$e->response->status() === 422` — consistent with how the existing client handles 404.