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

## Result

Implemented `POST /api/v1/payments` in **merchant-api** and `POST /api/payments` (internal) in **payment-domain**.

### merchant-api changes
- `app/Domain/IdempotencyKey/IdempotencyKey.php` — model storing per-merchant idempotency keys
- `app/Infrastructure/PaymentDomain/PaymentDomainClient.php` — HTTP client calling payment-domain
- `app/Interfaces/Http/Requests/InitiatePaymentRequest.php` — validates amount, currency, external_order_id, optional customer_reference / payment_method_token / metadata
- `app/Interfaces/Http/Controllers/InitiatePaymentController.php` — checks `Idempotency-Key` header, calls payment-domain, caches result
- `database/migrations/2026_04_06_000001_create_idempotency_keys_table.php` — unique index on (merchant_id, idempotency_key)
- `config/services.php` — added `payment_domain.base_url` (env: `PAYMENT_DOMAIN_URL`)
- `routes/api.php` — `POST /v1/payments` under `auth.api` middleware
- `tests/Feature/Payment/InitiatePaymentTest.php` — 10 tests covering happy path, auth, validation, idempotency scoping

### payment-domain changes
- `app/Domain/Payment/Payment.php` — Eloquent model with `HasUlids`
- `app/Domain/Payment/PaymentStatusHistory.php` — append-only history model
- `app/Infrastructure/Outbox/OutboxEvent.php` — transactional outbox model
- `app/Application/Payment/InitiatePayment.php` — creates Payment + PaymentStatusHistory + OutboxEvent in one DB transaction
- `app/Interfaces/Http/Requests/InitiatePaymentRequest.php` — validates internal payload including merchant_id and correlation_id as UUIDs
- `app/Interfaces/Http/Controllers/InitiatePaymentController.php` — invokes use case, returns 201
- `database/migrations/2026_01_01_000013_add_customer_and_payment_fields_to_payments_table.php` — adds customer_reference, payment_method_reference, metadata columns
- `routes/api.php` — `POST /payments`
- `tests/Feature/Payment/InitiatePaymentTest.php` — 12 tests covering payment creation, status history, outbox event, transaction integrity, and all validation rules