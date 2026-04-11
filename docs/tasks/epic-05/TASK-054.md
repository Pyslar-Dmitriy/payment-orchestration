### TASK-054 — Implement the outbox publisher

#### What to do
Create a mechanism that reads `outbox_messages` and publishes:
- domain events to Kafka;
- operational messages to RabbitMQ where needed.

#### What to consider
- retries;
- mark as sent;
- dead-letter or error state;
- safe reprocessing.

#### Done criteria
- no event is lost between DB commit and broker publish;
- rerunning the publisher does not break consistency.

## Result

Implemented a polling outbox publisher for the `payment-domain` service.

### Key files created

**Infrastructure layer (`app/Infrastructure/Outbox/Publisher/`):**
- `BrokerPublisherInterface.php` — common publish contract
- `KafkaBrokerPublisher.php` / `RabbitMqBrokerPublisher.php` — marker interfaces used as distinct container binding keys
- `KafkaPublisher.php` — real Kafka implementation using `longlang/phpkafka`
- `RabbitMqPublisher.php` — real RabbitMQ implementation using `php-amqplib`
- `FakeBrokerPublisher.php` — in-memory test double with assertion helpers (`assertPublished`, `assertNothingPublished`, etc.)
- `EventRouter.php` — static map of `event_type → (broker, topic)`; throws `UnroutableEventException` for unknown event types (dead-lettered immediately)
- `KafkaEnvelopeBuilder.php` — builds the Kafka wire-format envelope (schema_version, message_id, correlation_id, source_service, etc.)
- `BrokerTransientException.php`, `BrokerPublishException.php`, `UnroutableEventException.php` — failure types with clear retry semantics

**Outbox service (`app/Infrastructure/Outbox/`):**
- `OutboxPublisherService.php` — core polling loop; uses `SELECT … FOR UPDATE SKIP LOCKED` to prevent double-publishing under concurrent runners; handles per-event error routing

**Artisan command (`app/Interfaces/Console/`):**
- `PublishOutboxEventsCommand.php` — `outbox:publish --once` command

**Config / migrations:**
- `config/outbox.php` — broker config
- Migration: adds `retry_count`, `last_error`, `failed_permanently` + partial index for fast polling

### Key files modified
- `app/Infrastructure/Outbox/OutboxEvent.php` — new fillable/cast fields
- `app/Providers/AppServiceProvider.php` — binds `KafkaBrokerPublisher` and `RabbitMqBrokerPublisher` interfaces
- `bootstrap/app.php` — registers `PublishOutboxEventsCommand`
- `composer.json` — added `longlang/phpkafka:^1.0` and `php-amqplib/php-amqplib:^3.7`

### Design decisions

**Marker interfaces instead of concrete types in constructor:** `OutboxPublisherService` takes `KafkaBrokerPublisher` and `RabbitMqBrokerPublisher` (interfaces, not concrete classes). This allows tests to substitute fakes via `$this->app->instance()` without breaking PHP's strict type checking.

**Test setup via `setUp()`:** Publisher fakes are bound in each test class's `setUp()` rather than environment-checked in `AppServiceProvider`. The Docker container runs with `APP_ENV=local` in its `.env` file, making environment-conditional bindings unreliable. Explicit test setup is more reliable and conventional.

**SKIP LOCKED via raw SQL:** Laravel's `lockForUpdate()` does not add `SKIP LOCKED`. A raw `SELECT … FOR UPDATE SKIP LOCKED` query is used to claim batches, ensuring two concurrent publisher processes never pick the same row.

**Composer `--ignore-platform-req`:** The `php-amqplib` package requires `ext-sockets` which is not compiled into the Docker PHP image. The package was installed with `--ignore-platform-req=ext-sockets` — `AMQPStreamConnection` (used in `RabbitMqPublisher`) uses PHP streams, not the `sockets` extension.

### Tests added (171 total, all pass)
- `tests/Unit/Infrastructure/Outbox/EventRouterTest.php`
- `tests/Unit/Infrastructure/Outbox/KafkaEnvelopeBuilderTest.php`
- `tests/Feature/Infrastructure/Outbox/PublishOutboxEventsCommandTest.php`