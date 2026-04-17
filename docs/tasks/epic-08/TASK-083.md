### TASK-083 — Publish raw webhook task to RabbitMQ

#### What to do
After storing the raw webhook, publish a message to the `provider.webhook.raw` queue.

#### Done criteria
- the payload is not excessively large;
- the message contains a reference to the raw event ID;
- republishing does not break downstream processing.

## Result

**Files created:**
- `app/Infrastructure/Queue/RabbitMqPublisherContract.php` — interface for the publisher
- `app/Infrastructure/Queue/RabbitMqPublisher.php` — AMQP implementation using `php-amqplib`, mirrors the pattern from `payment-domain`
- `app/Infrastructure/Queue/BrokerTransientException.php` / `BrokerPublishException.php` — typed exceptions for retriable vs. permanent broker failures
- `config/rabbitmq.php` — RabbitMQ connection config driven by env vars
- `tests/Feature/PublishRawWebhookJobTest.php` — 4 feature tests covering happy path, field correctness, idempotent message_id, and missing-record guard

**Files modified:**
- `app/Infrastructure/Queue/PublishRawWebhookJob.php` — replaced stub with real implementation: DB lookup → build slim JSON message → publish to `provider.webhook.raw`
- `app/Providers/AppServiceProvider.php` — binds `RabbitMqPublisherContract` to `RabbitMqPublisher`
- `composer.json` / `composer.lock` — added `php-amqplib/php-amqplib ^3.7`
- `Dockerfile` — added `linux-headers` and `sockets` PHP extension to base image
- `.env.example` — documented `RABBITMQ_*` env vars

**Design decisions:**
- Message body contains only `{raw_event_id, provider, event_id, correlation_id}` — a slim reference, not the raw payload, satisfying the "not excessively large" criterion.
- `message_id` is set to `rawWebhookId` (the UUID primary key) so AMQP brokers and downstream consumers can deduplicate replayed messages automatically — satisfying idempotent republishing.
- The job is dispatched by `IngestWebhook` to the Laravel Redis queue; the job's `handle()` then publishes to RabbitMQ, keeping the HTTP response fast and decoupling the AMQP publish from the request path.