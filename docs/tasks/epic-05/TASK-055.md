### TASK-055 — Add optimistic locking or selective pessimistic locking

#### What to do
Choose a concurrent update control strategy and implement it.

#### Done criteria
- race conditions between status updates are reproducible in tests;
- the selected strategy actually prevents them;
- the decision is documented in an ADR.

## Result

**Strategy chosen:** optimistic locking via a `version` integer column on the `payments` table.

The mechanism was already implemented in `Payment::transition()` (TASK-051/053): a conditional `UPDATE … WHERE version = :expected` returns 0 rows on conflict, throwing `PaymentConcurrencyException`. The controller maps that to `409 Conflict`.

**What this task added:**

- `docs/architecture/adrs/ADR-017-optimistic-locking-for-payment-status-transitions.md` — documents the choice, rejection of pessimistic locking, and tradeoffs.
- `docs/architecture/adrs/_index.md` — added ADR-017 entry.
- `tests/Feature/Payment/TransitionPaymentStatusTest.php` — three new tests covering the concurrency path at the HTTP boundary:
  - `test_returns_409_when_payment_was_modified_concurrently` — uses `DB::listen` to bump the DB version between the use-case SELECT and the transactional UPDATE, reproducing the actual race window and asserting 409.
  - `test_concurrent_conflict_does_not_write_outbox_event` — same race simulation; asserts no side effects.
  - `test_sequential_updates_succeed_without_conflict` — three sequential transitions all succeed, version increments to 3.

Domain-level race tests (`PaymentTransitionTest::test_concurrency_conflict_throws_exception` and `test_concurrency_conflict_does_not_create_history_record`) were already present from TASK-051 and remain the canonical coverage for the `Payment::transition()` behaviour.