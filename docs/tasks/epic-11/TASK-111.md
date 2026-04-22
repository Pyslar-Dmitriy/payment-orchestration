### TASK-111 — Implement callback dispatch to RabbitMQ

#### What to do
After a successful payment or refund, create a callback task and enqueue it.

#### Done criteria
- dispatch is asynchronous;
- payload is versioned;
- delivery ID is traceable.

## Result

**New endpoint** in `merchant-callback-delivery`: `POST /api/v1/callbacks/dispatch` (protected by `X-Internal-Secret`).

**Files created/modified in `merchant-callback-delivery`:**
- `app/Infrastructure/RabbitMq/CallbackQueuePublisherInterface.php` — publisher contract
- `app/Infrastructure/RabbitMq/RabbitMqCallbackPublisher.php` — AMQP publisher using `php-amqplib`
- `app/Infrastructure/RabbitMq/FakeCallbackPublisher.php` — in-memory publisher for tests
- `app/Application/DispatchCallback/DispatchCallbackCommand.php` — command DTO
- `app/Application/DispatchCallback/DispatchCallbackHandler.php` — queries active subscriptions, creates `CallbackDelivery` per subscription in a DB transaction, signs the payload (HMAC-SHA256), publishes `MerchantCallbackDispatchPayload` to `merchant.callback.dispatch`
- `app/Interfaces/Http/Requests/DispatchCallbackRequest.php` — validates all required fields
- `app/Interfaces/Http/Controllers/DispatchCallbackController.php`
- `app/Http/Middleware/InternalServiceMiddleware.php` — `X-Internal-Secret` guard
- `config/rabbitmq.php`, `config/services.php` — RabbitMQ and secret config
- `database/migrations/2026_04_21_000023_…` — fixes `payment_id` column from `char(26)` → `uuid`
- `composer.json` — added `php-amqplib/php-amqplib`
- `tests/Feature/DispatchCallbackTest.php` — 18 tests (auth, validation, happy path, multi-subscription, inactive/event-type/merchant filtering, refund events, delivery ID traceability)

**Files modified in `payment-orchestrator`:**
- `MerchantCallbackActivity` interface and `MerchantCallbackActivityImpl` — enriched with `merchantId`, `amountValue`, `amountCurrency`, `eventType`; removed ambiguous `status` param; added optional `refundId`
- `PaymentWorkflowImpl` — passes full context on `payment.captured` and `payment.failed`
- `RefundWorkflowImpl` — passes full context on `refund.completed` and `refund.failed`, including `refundId`

**Design decisions:**
- Payload is "versioned" via `message_type: merchant_callback_dispatch` per RabbitMQ contract (no `schema_version` per ADR-012)
- Delivery ID (`callback_id` in the message = `CallbackDelivery.id`) is the stable trace key across all retry attempts
- One `CallbackDelivery` record per subscription per dispatch call; subscription_id FK is preserved in the DB record for traceability
- `payment_id` column corrected from `char(26)` (ULID) to `uuid` to match the UUID-based payment domain