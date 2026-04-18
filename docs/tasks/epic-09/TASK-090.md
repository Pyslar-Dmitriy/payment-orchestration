### TASK-090 — Implement the raw webhook queue consumer

#### What to do
Create a worker that consumes `provider.webhook.raw`.

#### Logic
- read raw event ID;
- load raw payload;
- normalize it;
- recheck processing safety;
- signal the workflow;
- publish the normalized event.

#### Done criteria
- the worker is retry-safe;
- it does not perform irreversible actions without dedup protection.

## Result

**Files created:**
- `app/Infrastructure/Queue/RabbitMqConsumerContract.php` — consumer interface (`consume(string $queue, callable $callback): void`)
- `app/Infrastructure/Queue/RabbitMqConsumer.php` — AMQP implementation using `php-amqplib`; declares the queue durable, sets `prefetch_count=1`, loops until no consumers remain; wraps connection failures in `BrokerTransientException`
- `app/Infrastructure/Queue/BrokerTransientException.php` — signals transient failures to the caller
- `app/Infrastructure/Persistence/InboxMessage.php` — Eloquent model for `inbox_messages`
- `app/Application/ProcessRawWebhook.php` — use case; checks inbox before doing anything, inserts inbox row after processing; stubs for TASK-091/092/093 are marked with comments
- `app/Interfaces/Console/ConsumeRawWebhookCommand.php` — `webhook:consume` Artisan command; dispatches each AMQP message to `ProcessRawWebhook`; maps `QueryException` → nack+requeue, `BrokerTransientException` → nack+requeue, all other throwables → nack+discard, malformed JSON or missing required fields → nack+discard
- `config/rabbitmq.php` — env-driven RabbitMQ config
- `tests/Feature/ProcessRawWebhookTest.php` — 6 feature tests covering happy path, idempotency, and payload variations

**Files modified:**
- `composer.json` — added `php-amqplib/php-amqplib: ^3.7`
- `app/Providers/AppServiceProvider.php` — binds `RabbitMqConsumerContract` → `RabbitMqConsumer`
- `.env.example` — added `RABBITMQ_*` environment variables
- `tests/Feature/ExampleTest.php` — fixed pre-existing broken route (`/` → `/api/health`)

**Design decisions:**
- Inbox dedup uses the AMQP `message_id` property, which webhook-ingest sets to the `raw_webhook_id`. This is the natural idempotency key for this message.
- `ProcessRawWebhook` leaves loading the raw payload, normalization, Temporal signalling, and Kafka publishing as stubs (comments pointing to TASK-091/092/093), because those steps depend on future tasks and there is no cross-service DB access.
- Error categorisation is in the command layer, not the use case, so `ProcessRawWebhook` stays broker-agnostic.