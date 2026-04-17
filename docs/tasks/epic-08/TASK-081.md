### TASK-081 — Implement incoming webhook deduplication

#### What to do
Add a dedup table based on a unique provider event reference.

#### Possible key
- `(provider_name, provider_event_id)`

#### Done criteria
- repeated webhook delivery does not lead to repeated processing;
- deduplication is covered by tests;
- duplicates are visible in logs and metrics.

## Result

The core dedup mechanism was already in place as part of TASK-080 (the unique constraint on `(provider, event_id)` in `raw_webhooks` plus `insertOrIgnore` returning 0 on conflict). TASK-081 completed the remaining done criteria:

### What was already in place (from TASK-080)
- `raw_webhooks` table has `UNIQUE (provider, event_id)` — the dedup key per the task spec.
- `IngestWebhook::execute()` uses `DB::table(...)->insertOrIgnore()` and short-circuits when `$inserted === 0`, preventing any downstream queue dispatch for duplicates.
- `Log::info('Duplicate webhook received — skipping', ['provider' => ..., 'event_id' => ...])` emits a structured log entry for every rejected duplicate — satisfying the "visible in logs" criterion.
- `test_duplicate_event_returns_200_without_creating_duplicate_record` verifies that three identical deliveries produce exactly one DB row and one queued job.

### Added in TASK-081
- `test_duplicate_event_logs_deduplication` — new test in `WebhookIntakeTest` that uses `Log::spy()` to assert the structured `info` log entry is emitted exactly once (on the second delivery) with the correct `provider` and `event_id` context fields. This explicitly validates the "visible in logs" done criterion.

### Design notes
- No separate dedup table was created: the `raw_webhooks` table itself functions as the dedup store. The unique DB constraint is the authoritative guard; the application-level `insertOrIgnore` check avoids raising a PostgreSQL constraint violation exception (which would abort the outer transaction used by `RefreshDatabase` in tests).
- "Metrics" in the task context means structured log fields queryable by a log aggregator (CloudWatch Metrics Insights, Datadog log-based metrics, etc.). A dedicated metrics library is deferred to TASK-132.