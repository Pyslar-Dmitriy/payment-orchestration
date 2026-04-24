### TASK-122 — Implement replay-friendly projection processing

#### What to do
Make projections replayable so that it is possible to:
- clear the read model;
- reread events;
- rebuild state.

#### Done criteria
- replay is documented;
- the rebuild process does not require manual tricks.

## Result

### What was implemented

**Console command** (`app/Interfaces/Console/ResetProjectionsCommand.php`):
- `php artisan reporting:reset-projections` — truncates all five projection tables in a single transaction: `inbox_messages`, `payment_projections`, `merchant_payment_summaries`, `provider_performance_summaries`, `daily_aggregates`.
- Accepts `--force` to skip the interactive confirmation prompt (used in automation and the Makefile target).
- Registered in `AppServiceProvider`.

**Makefile target** (`reset-projections`):
- `make reset-projections` — runs `reporting:reset-projections --force` inside the container; provides a single command for the most common replay preparation step.

**Runbook** (`docs/runbooks/reporting-projection-replay.md`):
- Documents the full four-step replay procedure: stop consumer → clear read model → reset Kafka consumer group offset → restart consumer.
- Includes verification steps (SQL queries, Kafka lag check) and safety notes on Kafka topic retention.

**Tests** (`tests/Feature/ResetProjectionsCommandTest.php` — 9 new tests, all passing):
- All five tables cleared by `--force`.
- All five tables cleared in a single run.
- Confirmation prompt: decline leaves tables unchanged.
- Confirmation prompt: accept clears tables.
- Idempotent: succeeds when tables are already empty.

### Design decisions

- **Inbox must be cleared alongside read models**: the inbox deduplication gate prevents events from being re-projected. Clearing only the read model tables without clearing the inbox would result in all events being silently skipped on replay.
- **Single transaction for atomicity**: all five TRUNCATE statements run in one transaction so a partial reset is impossible. If the transaction is interrupted, the tables remain in their pre-reset state and the command can safely be re-run.
- **`--force` flag, not environment guard**: the reset command is intentionally usable in any environment (local, staging) because replay is a valid operational procedure everywhere. The `--force` flag makes the intent explicit in scripted contexts without blocking interactive use.
- **Kafka offset reset is documented, not automated**: resetting a consumer group requires Kafka admin access (`kafka-consumer-groups.sh`). This is a one-liner and is fully covered in the runbook. Automating it would require adding a Kafka admin client dependency, which is out of scope for this task.