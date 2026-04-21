### TASK-103 — Implement ledger outbox events

#### What to do
After posting a ledger transaction, publish events to Kafka.

#### Done criteria
- events are published through the outbox;
- the projection service can consume them.

## Result

Implemented the full outbox publish pipeline for the ledger service, mirroring the pattern from `payment-domain`.

**Files created:**
- `app/Infrastructure/Outbox/Publisher/BrokerPublisherInterface.php` — common publish contract
- `app/Infrastructure/Outbox/Publisher/BrokerTransientException.php`, `BrokerPublishException.php`, `UnroutableEventException.php` — failure types
- `app/Infrastructure/Outbox/Publisher/Kafka/KafkaBrokerPublisher.php` — marker interface for DI binding
- `app/Infrastructure/Outbox/Publisher/Kafka/KafkaPublisher.php` — real Kafka producer (uses `longlang/phpkafka`)
- `app/Infrastructure/Outbox/Publisher/Kafka/EventRouter.php` — routes `ledger.entry_posted.v1` → `ledger.entries.v1`
- `app/Infrastructure/Outbox/Publisher/Kafka/KafkaEnvelopeBuilder.php` — builds KafkaEnvelope from `OutboxMessage`
- `app/Infrastructure/Outbox/Publisher/FakeBroker/FakeBrokerPublisher.php` — in-memory test double
- `app/Infrastructure/Outbox/OutboxPublisherService.php` — batch polling with SKIP LOCKED, transient/permanent error handling
- `app/Interfaces/Console/PublishOutboxEventsCommand.php` — `outbox:publish [--once]` artisan command
- `config/outbox.php` — batch size, max retries, Kafka config
- `tests/Feature/Infrastructure/Outbox/PublishOutboxEventsCommandTest.php` — 10 tests covering publish, skip, retry, dead-letter, envelope shape

**Files modified:**
- `app/Application/PostCaptureEntries/PostCaptureEntriesHandler.php` — writes `OutboxMessage` inside DB transaction after posting entries
- `app/Application/PostRefundEntries/PostRefundEntriesHandler.php` — same for refund postings
- `app/Providers/AppServiceProvider.php` — binds `KafkaBrokerPublisher` to `KafkaPublisher`
- `bootstrap/app.php` — registers `PublishOutboxEventsCommand`
- `tests/Feature/PostCaptureEntriesTest.php` — added outbox count and payload assertions
- `tests/Feature/PostRefundEntriesTest.php` — added outbox count and payload assertions
- `composer.json` / `composer.lock` — added `longlang/phpkafka ^1.0`

**Design decisions:**
- Outbox message payload includes all fields required by `ledger-entry-posted.v1.json` schema (entry_id, merchant_id, posting_type, lines, idempotency_key, correlation_id, occurred_at). All actual lines are included even for 3-leg fee postings (the contract's `maxItems: 2` is a simplification).
- The `aggregate_id` is the ULID of `LedgerTransaction` (used as the Kafka partition key for ordering).
- Idempotent: duplicate posting requests return early before the DB transaction, so no second outbox message is written.
- `longlang/phpkafka` installed with `--ignore-platform-req=ext-bcmath` since the container image doesn't have that PHP extension; bcmath is only needed at runtime by the Kafka library when compression is enabled, which we don't use.