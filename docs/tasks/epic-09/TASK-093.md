### TASK-093 — Publish a Kafka event for normalized webhook processing

#### What to do
Publish an event to `provider.webhooks.normalized.v1`.

#### Done criteria
- the event is useful for audit and analytics;
- the format is versioned;
- correlation and payment references are included.

## Result

Implemented the full outbox-based Kafka publishing pipeline for the webhook-normalizer:

**Key files created:**
- `app/Infrastructure/Outbox/OutboxEvent.php` — Eloquent model for `outbox_events`
- `app/Infrastructure/Outbox/KafkaEnvelopeBuilder.php` — builds the standard `KafkaEnvelope` (schema_version, message_id, correlation_id, source_service=webhook-normalizer, etc.)
- `app/Infrastructure/Outbox/OutboxPublisherService.php` — relay: reads pending outbox rows (SELECT … FOR UPDATE SKIP LOCKED) and publishes to `provider.webhooks.normalized.v1`
- `app/Infrastructure/Outbox/Publisher/KafkaPublisher.php` — `longlang/phpkafka` producer
- `app/Infrastructure/Outbox/Publisher/FakeBroker/FakeBrokerPublisher.php` — in-memory publisher for tests
- `app/Interfaces/Console/PublishOutboxEventsCommand.php` — `outbox:publish [--once]` Artisan command
- `config/outbox.php` — `KAFKA_BROKERS`, `KAFKA_CLIENT_ID`, `OUTBOX_BATCH_SIZE`, `OUTBOX_MAX_RETRIES`
- `database/migrations/2026_04_20_000020_add_publisher_columns_to_outbox_events_table.php` — adds `retry_count`, `last_error`, `failed_permanently`
- `contracts/json-schemas/events/webhook-signal-received.v1.json` + valid fixture

**Key files modified:**
- `app/Application/ProcessRawWebhook.php` — writes `provider.webhook_signal_received.v1` outbox row atomically inside the same DB transaction as the inbox insert (only when normalization succeeds)
- `app/Providers/AppServiceProvider.php` — binds `BrokerPublisherInterface` to `KafkaPublisher` (prod) or `FakeBrokerPublisher` (testing); wires `OutboxPublisherService`
- `bootstrap/app.php` — registers `ConsumeRawWebhookCommand` and `PublishOutboxEventsCommand`
- `Dockerfile` — adds `bcmath` extension required by `longlang/phpkafka`

**Design decisions:**
- The outbox write is inside the same `DB::transaction` as the inbox commit — both are atomic. If the transaction fails, the message is requeued (the signal to Temporal may already have been sent, which is the accepted at-least-once tradeoff).
- The Kafka event is only written when normalization succeeds (`$normalizedEvent !== null`). Unmappable webhooks (unknown provider) produce no Kafka event.
- The `signal_type` is derived from `eventType` by replacing `.` with `_` (e.g., `payment.captured` → `payment_captured`), matching the `WebhookSignalPayload` enum in the kafka.yaml contract.
- The outbox row `id` (UUID) doubles as `signal_id` and `message_id` in the Kafka envelope, providing stable idempotency keys for consumers.
- 51 tests pass (9 new tests covering outbox write in ProcessRawWebhook and the full relay command lifecycle).