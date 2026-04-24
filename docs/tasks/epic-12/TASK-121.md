### TASK-121 — Implement the Reporting Projection Service

#### What to do
Create a service that consumes Kafka topics and builds read models.

#### Minimum read models
- merchant payment summary;
- provider performance summary;
- daily aggregates;
- searchable payment read model.

#### Done criteria
- the service is idempotent;
- it can survive replay;
- lag does not affect the main payment flow.

## Result

### What was implemented

**Migrations** (3 new, added to existing skeleton):
- `2026_04_23_000020_create_merchant_payment_summaries_table.php`
- `2026_04_23_000021_create_provider_performance_summaries_table.php`
- `2026_04_23_000022_create_daily_aggregates_table.php`

**Models** (`app/Infrastructure/Persistence/`):
- `InboxMessage` — inbox deduplication by `message_id` (Kafka envelope UUID)
- `PaymentProjection` — searchable read model keyed on `payment_id`
- `MerchantPaymentSummary` — per-merchant counts and volumes
- `ProviderPerformanceSummary` — per-provider attempt/capture/failure counts
- `DailyAggregate` — per-(date, currency) payment and refund counts

**Application** (`app/Application/`):
- `ProjectPaymentEvent` — processes all payment lifecycle events from `payments.lifecycle.v1`; updates all four read models in a single DB transaction that also inserts the inbox entry
- `ProjectRefundEvent` — processes refund lifecycle events from `refunds.lifecycle.v1`; updates `daily_aggregates` for `refund.succeeded`

**Infrastructure** (`app/Infrastructure/Kafka/KafkaConsumer.php`):
- Thin wrapper around `longlang/phpkafka` Consumer; subscribes to both topics with a single consumer instance (library supports `setTopic(array)`)

**Console command** (`app/Interfaces/Console/ConsumeKafkaEventsCommand.php`):
- `php artisan reporting:consume-events`; supports `--max-messages` for testing; graceful SIGTERM/SIGINT shutdown via pcntl; permanent errors are acked (discarded), transient DB/Kafka errors are re-thrown so the offset is not committed

**Config** (`config/kafka.php`) + `.env`/`.env.example` updated with Kafka variables.

**Dockerfile** updated to install `bcmath` extension required by `longlang/phpkafka`.

**Tests** (38 total, all passing):
- `ProjectPaymentEventTest` — inbox deduplication, all event types updating payment_projections, merchant_payment_summaries, provider_performance_summaries, and daily_aggregates; idempotency on replay
- `ProjectRefundEventTest` — inbox deduplication, refund.succeeded daily aggregate increments, non-succeeded events skipped, idempotency on replay

### Design decisions

- **Merchant and provider summaries are recomputed from `payment_projections`** rather than maintained with increments. This guarantees they are always consistent with the searchable read model, even after out-of-order events, and makes them trivially replay-safe without requiring inbox tracking.
- **Daily aggregates use atomic `insertOrIgnore` + raw `UPDATE col = col + N`**. Since each Kafka message is deduplicated by inbox before any write, each event contributes exactly once to the aggregate.
- **Single consumer for both topics** — `longlang/phpkafka` Consumer supports `setTopic(array)`, so one process subscribes to `payments.lifecycle.v1` and `refunds.lifecycle.v1` simultaneously. `ConsumeMessage::getTopic()` is used to dispatch to the correct projector.
- **`--max-messages` flag** on the command enables deterministic termination in CI and integration tests without needing a real Kafka broker.