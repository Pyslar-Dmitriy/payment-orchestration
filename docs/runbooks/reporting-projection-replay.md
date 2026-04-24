# Replay Runbook — Reporting Projection Service

## Overview

The `reporting-projection` service builds read models by consuming events from two Kafka topics:

- `payments.lifecycle.v1`
- `refunds.lifecycle.v1`

Because each event is deduplicated by `message_id` (inbox table) and all read model writes are idempotent, the entire state can be discarded and rebuilt from scratch by replaying events from the beginning of each topic. This runbook describes when and how to do that.

---

## When to replay

- A bug was found in a projector and the read models contain incorrect data.
- A new read model column or table was added that requires backfilling from historical events.
- The projection database was corrupted or accidentally truncated (replay rebuilds it from the log).
- A significant schema migration changed how events are interpreted.

---

## How replay works

1. The read model tables and the inbox are cleared.
2. The Kafka consumer group offset is reset so the consumer starts from offset 0 on both topics.
3. The consumer is restarted. Because `KAFKA_AUTO_OFFSET_RESET=earliest` is set and there are no committed offsets for the group, it reads every event from the beginning.
4. The inbox deduplication ensures that if the consumer is interrupted and restarted mid-replay, no event is double-counted.

Replay is safe and non-destructive to the rest of the platform — the consumer group is independent of all upstream services and does not affect the main payment flow.

---

## Step-by-step procedure

### 1. Stop the consumer

```bash
docker compose stop reporting-projection
```

Or, if running as a separate container/worker, stop that process specifically.

### 2. Clear the read model

```bash
make reset-projections
```

This runs `php artisan reporting:reset-projections --force` inside the container, which truncates the following tables in a single transaction:

| Table | Purpose |
|---|---|
| `inbox_messages` | Deduplication keys — must be cleared so events are reprocessed |
| `payment_projections` | Searchable per-payment read model |
| `merchant_payment_summaries` | Per-merchant counts and volumes |
| `provider_performance_summaries` | Per-provider attempt/capture/failure counts |
| `daily_aggregates` | Per-(date, currency) payment and refund aggregates |

### 3. Reset the Kafka consumer group offset

Delete the consumer group so that the next consumer start picks up from `earliest`:

```bash
docker compose exec kafka kafka-consumer-groups.sh \
  --bootstrap-server localhost:9092 \
  --group reporting-projection \
  --delete
```

You can verify the group is gone:

```bash
docker compose exec kafka kafka-consumer-groups.sh \
  --bootstrap-server localhost:9092 \
  --list
```

`reporting-projection` should no longer appear in the list.

### 4. Restart the consumer

```bash
docker compose start reporting-projection
```

Or restart the worker process that runs `php artisan reporting:consume-events`.

The consumer will log `Kafka event consumer started` and begin reading from offset 0 on both topics. Monitor progress with:

```bash
make logs SERVICE=reporting-projection
```

---

## Verification

Once the consumer has processed all historical events, verify that the read models are populated:

```sql
-- Check payment projections count
SELECT COUNT(*) FROM payment_projections;

-- Check merchant summaries
SELECT merchant_id, total_count, captured_count FROM merchant_payment_summaries;

-- Check daily aggregates
SELECT date, currency, payments_initiated, payments_captured FROM daily_aggregates ORDER BY date DESC LIMIT 10;
```

To confirm the consumer is caught up, check the Kafka consumer group lag:

```bash
docker compose exec kafka kafka-consumer-groups.sh \
  --bootstrap-server localhost:9092 \
  --group reporting-projection \
  --describe
```

When `LAG` is `0` for all partitions on both topics, replay is complete.

---

## Safety notes

- The `reporting:reset-projections` command is **irreversible** — cleared data can only be recovered by replaying from Kafka. Ensure Kafka topic retention covers the full history you need before running.
- Replay duration depends on the number of events in the topics. For large topics, replay may take several minutes to hours.
- During replay, the read model is incomplete — do not rely on it for queries until the consumer lag reaches zero.
- The `--force` flag on the reset command skips the confirmation prompt. Only use it in scripted contexts (Makefile, CI) where the intent is already confirmed.