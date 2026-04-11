# ADR-017 — Use optimistic locking for concurrent payment status transitions

**Status:** <span style="color:green">Accepted</span>

## Context

The payment lifecycle involves multiple writers that may attempt to transition the same payment simultaneously:

* Temporal workflow activities advancing a payment through its lifecycle,
* webhook signals delivering provider outcomes,
* compensation activities correcting previous failures.

Without concurrency control, two concurrent reads of the same payment row followed by independent writes can silently overwrite each other, producing an inconsistent final state (e.g., a `captured` event permanently lost because a `failed` write happened last).

Two standard strategies exist for this problem: **pessimistic locking** (acquire an exclusive row lock before reading) and **optimistic locking** (detect conflicts at write time via a version counter).

## Decision

Use **optimistic locking** on the `payments` table via an integer `version` column.

On every status transition the `Payment::transition()` method issues a conditional `UPDATE` that includes `WHERE version = :expected_version` and increments the counter atomically:

```sql
UPDATE payments
SET status = ?, version = version + 1, ...
WHERE id = ? AND version = ?
```

If `affected_rows = 0`, the version was already bumped by a concurrent writer; a `PaymentConcurrencyException` is raised and the caller receives `409 Conflict`.

Callers (Temporal activities, HTTP orchestrators) are expected to retry after a short back-off.

## Why not pessimistic locking

Pessimistic locking (`SELECT … FOR UPDATE`) was evaluated and rejected for the following reasons:

* **Lock contention** — each payment transition holds a Postgres row lock for the entire transaction duration, including outbox writes and status-history inserts. Under load this serialises all writers behind a single bottleneck.
* **Deadlock surface** — if two transactions touch the same row in different order (e.g., payment row + outbox row) they can deadlock, requiring complex acquisition ordering.
* **Unnecessary for the access pattern** — Temporal ensures at most one workflow instance per payment, so true conflicts are rare (typically duplicate webhook delivery). Optimistic locking is a better fit for rare conflicts.

Selective pessimistic locking (locking only for specific high-contention transitions) was also considered. It adds complexity without a concrete measured benefit; the simpler uniform approach is preferred.

## Alternatives considered

### Alternative A — No concurrency control

Pros:
* simplest implementation.

Cons:
* lost updates under concurrent webhook delivery,
* impossible to detect a split-brain write at the database level.

### Alternative B — Pessimistic locking (`SELECT … FOR UPDATE`)

Pros:
* guaranteed mutual exclusion,
* no need for callers to handle 409 and retry.

Cons:
* higher latency under even moderate concurrency,
* longer-held locks increase the deadlock risk with outbox writes,
* overkill for a workload dominated by sequential Temporal activity calls.

### Alternative C — Application-level mutex (Redis lock)

Pros:
* works across multiple DB replicas.

Cons:
* requires a separate distributed-lock dependency,
* introduces failure modes if Redis is unavailable,
* harder to reason about than a DB-native mechanism.

## Consequences

Positive:

* zero contention on the happy path — no lock acquisition overhead,
* 409 Conflict is a clean, retryable signal; Temporal activities already handle retries natively,
* the `version` column doubles as a cheap audit signal (any jump > 1 indicates skipped transitions).

Negative:

* callers must handle `409 Conflict` and implement retry logic,
* under sustained high-concurrency bursts (e.g., rapid duplicate webhook storms) retry loops may increase latency.