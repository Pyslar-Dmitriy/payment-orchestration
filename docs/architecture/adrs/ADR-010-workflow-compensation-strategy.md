# ADR-010 — Compensation and rollback strategy for permanent workflow failures

**Status:** <span style="color:green">Accepted</span>

## Context

The `PaymentWorkflow` and `RefundWorkflow` in Temporal coordinate activities across external providers, payment-domain, and ledger-service. Some of these activities have external side effects that cannot be rolled back programmatically:

* A provider authorization or capture call has already succeeded.
* Funds have moved at the PSP level.
* A partial sequence of domain state changes may have been persisted.

Temporal's retry mechanism handles transient failures. This ADR is about what happens when retries are exhausted and a step fails **permanently** — specifically when external side effects have already occurred on prior steps.

### The core problem

The workflow is not an ACID transaction. Activities are not wrapped in a 2PC. Once an external provider confirms a capture, there is no platform-side rollback that undoes the financial event. Any compensation strategy must be explicit, auditable, and manually actionable where automation is not possible.

### Failure modes requiring a decision

**Class A — No external side effect yet**
The workflow fails before any provider call succeeds. No external side effect has occurred.

Resolution: mark the payment `failed`, publish a domain event, complete the workflow. No compensation needed.

**Class B — Provider call succeeded, internal steps failed**
Example: payment is `captured` at the PSP, but ledger posting fails permanently after all retries.

Resolution: the payment cannot be marked `failed` (it is financially captured). It requires manual or automated reconciliation before the ledger record can be trusted.

**Class C — Refund fails after partial processing**
Example: provider confirms refund, but ledger reversal fails permanently.

Resolution: the same class B pattern — side effect occurred, platform state is inconsistent, reconciliation required.

## Decision

Apply the following strategy, differentiated by failure class:

### Class A failures — pre-side-effect permanent failure

1. The workflow marks the payment status as `failed` via the payment-domain activity.
2. The workflow publishes a `PaymentFailed` domain event via the Kafka activity.
3. Merchant callback delivery is triggered with the `failed` outcome.
4. Workflow completes normally.

### Class B and Class C failures — post-side-effect permanent failure

When a downstream step fails permanently after an irreversible external action has succeeded:

1. The workflow **does not** mark the payment `failed`. Marking it `failed` would misrepresent the actual financial state.
2. The workflow transitions the payment to a special status: **`requires_reconciliation`**.
3. The workflow publishes a `PaymentRequiresReconciliation` domain event to Kafka, including:
   * `payment_id`
   * `correlation_id`
   * `failed_step` — the activity that failed (e.g., `ledger_post`, `ledger_refund`)
   * `last_known_provider_status` — the confirmed provider state at the point of failure
   * `failure_reason` — Temporal activity failure details
4. The event is consumed by an operator alert channel (initially: structured log at `ERROR` level with `alert: true` field, later a dedicated consumer for PagerDuty/Slack).
5. The workflow completes with a non-successful result code (not an unhandled error — a deliberate terminal state).

### Compensation for Class B and C

Compensation is **manual-first for v1**:

* The `requires_reconciliation` status signals that the payment is in an inconsistent state.
* A runbook (TASK-143) documents how an operator identifies the inconsistency, applies the missing step manually or via a repair script, and transitions the payment out of `requires_reconciliation` into the correct terminal state (`captured` or `refunded`).
* The ledger-service must accept an idempotent retry of the posting operation — the `idempotency_key` on ledger transactions prevents double-posting if the repair script reruns.

Automated compensation (a dedicated `ReconciliationWorkflow`) is a **future scope item** and is not part of v1.

### What is not in scope

* Automatic reversal of a provider capture. This requires a separate refund or void flow and cannot be done by the platform in an automated way without explicit merchant intent.
* Cross-service sagas. The platform does not implement a saga coordinator pattern in v1.
* Compensating transaction queues. Not implemented; the `requires_reconciliation` status and Kafka event are the signal mechanism.

## Alternatives considered

### Alternative A — Mark as `failed` regardless of external state

Pros:
* Simpler state machine.
* No new status required.

Cons:
* Misrepresents the financial state. The provider has money; the ledger does not reflect it.
* Creates a false signal to the merchant (payment failed, but provider captured funds).
* Audit and reconciliation become much harder.

**Rejected.** Financial correctness requires that `failed` only means no external side effect occurred.

### Alternative B — Leave the workflow in a perpetual retry loop

Do not exhaust retries; keep retrying ledger posting indefinitely.

Pros:
* Eventually consistent if the ledger recovers.

Cons:
* Temporal workflow runs must eventually terminate.
* An indefinitely blocked workflow consumes Temporal resources and is operationally opaque.
* Masks the real problem: the ledger may have a bug or schema issue, not just a transient outage.

**Rejected.** Workflows must have a finite lifecycle. Bounded retries with explicit terminal handling are preferable.

### Alternative C — Publish to a DLQ and complete normally

Complete the workflow in a success-like state and push the failed step to a RabbitMQ DLQ for retry.

Pros:
* Leverages existing retry infrastructure.

Cons:
* The payment status remains misleadingly in a non-terminal state until the DLQ is processed.
* RabbitMQ DLQ is designed for operational task retry, not for financial reconciliation events.
* Mixing compensation with the task queue adds ambiguity to the DLQ purpose.

**Rejected** as a primary strategy. A Kafka domain event + `requires_reconciliation` status is a cleaner and more auditable signal than a DLQ entry.

## Consequences

Positive:
* `failed` status is unambiguous: it means no external side effect occurred.
* `requires_reconciliation` is the explicit signal for human intervention.
* The Kafka event gives operators and future consumers a durable, replayable record of what happened.
* The idempotent ledger makes repair scripts safe to rerun.

Negative:
* Adds `requires_reconciliation` to the payment state machine — implementors must handle this in the aggregate.
* Manual runbook dependency — the platform does not self-heal Class B failures in v1.
* Operators must monitor for `requires_reconciliation` events; missing this signal leads to financial inconsistency going undetected.

Operational note:
The `requires_reconciliation` status must never appear in normal payment flow. Any occurrence is an exceptional event and must be treated as an incident. Monitoring and alerting for this status is a requirement, not a nice-to-have.