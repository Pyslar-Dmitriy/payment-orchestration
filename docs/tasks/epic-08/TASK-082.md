### TASK-082 — Store raw payload and processing state

#### Tables
- `webhook_events_raw`
- `webhook_processing_attempts`
- `webhook_dedup`

#### Data to store
- provider name;
- headers;
- raw body;
- signature status;
- received_at;
- current processing state.

#### Done criteria
- any webhook can be replayed and analyzed;
- the data is suitable for replay.

## Result

Restructured webhook storage into three purpose-specific tables via migration `2026_01_01_000012_restructure_webhook_storage`:

- **`webhook_events_raw`** — renamed from `raw_webhooks`; replaced `created_at` with `received_at`, dropped `enqueued_at`, added `processing_state` (default `received`). Unique constraint removed (moved to dedup table). Stores immutable raw event data suitable for replay.
- **`webhook_dedup`** — new table with `UNIQUE(provider, event_id)` constraint. Separates deduplication concern from raw event storage, allowing replay without blocking by re-inserting into dedup.
- **`webhook_processing_attempts`** — new table recording each processing attempt (`raw_event_id`, `state`, `attempt_number`, `error_message`). Enables full processing history for analysis.

**Flow change in `IngestWebhook`**: dedup gate moves to `webhook_dedup.insertOrIgnore`; raw event stored with `processing_state = received`; after dispatch, state updated to `enqueued` and an attempt record inserted.

**New models**: `WebhookEventRaw`, `WebhookDedup`, `WebhookProcessingAttempt` (in `app/Infrastructure/Persistence/`). Removed `RawWebhook` (replaced by `WebhookEventRaw`).

**Tests**: 3 new tests covering `processing_state`, `webhook_processing_attempts`, and `webhook_dedup` entries. All 18 tests pass.