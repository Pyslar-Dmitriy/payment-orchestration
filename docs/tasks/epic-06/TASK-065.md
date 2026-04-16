# TASK-065 — Implement compensation handling for permanent post-side-effect workflow failures

## Context

ADR-010 defines the platform's compensation strategy for cases where a workflow step fails permanently after an irreversible external action has already occurred (e.g., provider capture confirmed, but ledger posting fails permanently).

The strategy requires:
1. A `requires_reconciliation` payment status (defined in TASK-056).
2. The workflow detecting the permanent failure class and transitioning to `requires_reconciliation` rather than `failed`.
3. A `PaymentRequiresReconciliation` domain event published to Kafka.
4. An operator runbook for resolving the inconsistency.

This task implements items 2–4 in the `PaymentWorkflow` (and equivalently in `RefundWorkflow`).

## What to implement

### In `PaymentWorkflow`

- After the ledger posting activity exhausts all retries and raises a permanent failure:
  - Call the payment-domain activity to transition status to `requires_reconciliation`, passing `failed_step = "ledger_post"` and the last known provider status.
  - Call the Kafka publishing activity to emit `PaymentRequiresReconciliation` with the required fields (see below).
  - Complete the workflow with a `WorkflowResult::requiresReconciliation()` result code, not an unhandled exception.
- The `failed` path (Class A — no external side effect yet) remains unchanged: mark `failed`, publish `PaymentFailed`, trigger callback.

### In `RefundWorkflow`

- Apply the same pattern for permanent ledger reversal failures post-refund confirmation.
- Emit `RefundRequiresReconciliation` event.

### `PaymentRequiresReconciliation` event schema

```json
{
  "schema_version": "1",
  "event_type": "PaymentRequiresReconciliation",
  "message_id": "<uuid>",
  "correlation_id": "<uuid>",
  "causation_id": "<uuid>",
  "source_service": "payment-orchestrator",
  "occurred_at": "<ISO8601>",
  "payload": {
    "payment_id": "<uuid>",
    "failed_step": "ledger_post",
    "last_known_provider_status": "captured",
    "failure_reason": "<string — Temporal activity failure summary>"
  }
}
```

### Structured log requirement

When emitting this event, the workflow must also log at `ERROR` level with `alert: true`:
```json
{
  "level": "error",
  "alert": true,
  "message": "Payment requires reconciliation — manual intervention needed",
  "payment_id": "...",
  "correlation_id": "...",
  "failed_step": "ledger_post"
}
```

### Runbook (to be added to `docs/runbooks/`)

Document the steps an operator follows:
1. Identify payments in `requires_reconciliation` via Kafka consumer or direct DB query.
2. Check what failed step is recorded.
3. For `ledger_post`: manually invoke the ledger posting endpoint with the correct idempotency key. Confirm the entry was created. Transition the payment to `captured` via the reconciliation command.
4. For `ledger_refund`: manually invoke the ledger reversal endpoint. Confirm. Transition to `refunded`.
5. Verify the Kafka event for the final status is published.

## Done criteria

- `PaymentWorkflow` transitions to `requires_reconciliation` (not `failed`) when the ledger activity fails permanently after a confirmed provider capture.
- `RefundWorkflow` applies the same pattern for refund-path ledger failures.
- `PaymentRequiresReconciliation` (and `RefundRequiresReconciliation`) events are published to Kafka.
- The `ERROR` alert log is emitted.
- A runbook exists in `docs/runbooks/reconciliation-runbook.md`.
- Unit/integration tests cover the compensation path (mock a permanently failing ledger activity after a successful provider response).

## Result

### What was implemented

The compensation handling path (ADR-010 Class B/C) was already structurally present from prior tasks. This task completed the remaining three requirements:

**1. `ERROR` alert log in both workflow implementations**

Added `Log::error('... requires reconciliation — manual intervention needed', ['alert' => true, ...])` to `handleClassBFailure` in:
- `app/Domain/Workflow/PaymentWorkflowImpl.php` — includes `payment_id`, `correlation_id`, `failed_step`
- `app/Domain/Workflow/RefundWorkflowImpl.php` — includes `refund_id`, `correlation_id`, `failed_step`

The log fires synchronously before the activity yields, so it is emitted even if the status-transition activity itself subsequently fails.

**2. Compensation path tests**

Two new test files, each covering:
- `handleClassBFailure` calls `markRequiresReconciliation` with correct arguments
- `handleClassBFailure` calls `publishPaymentRequiresReconciliation` / `publishRefundRequiresReconciliation` with correct arguments
- `handleClassBFailure` emits `error` log with `alert: true` and the correct context fields
- `handleClassBFailure` never calls `markFailed` (verifies wrong class is not triggered)
- `proceedToCaptureAndLedger` / `proceedToLedgerAndCallback` triggers Class B (not Class A) when ledger throws `ActivityFailure` after confirmed capture/refund
- Happy path through the same methods does not trigger Class B compensation

Files:
- `tests/Unit/Domain/Workflow/PaymentWorkflowCompensationTest.php`
- `tests/Unit/Domain/Workflow/RefundWorkflowCompensationTest.php`

Also fixed a pre-existing autoloader issue in the container where `temporal/sdk` was missing from the PSR-4 map — this also unblocked 6 previously-failing feature tests.

**3. Operator runbook**

`docs/runbooks/reconciliation-runbook.md` — covers how to identify affected records (via Kafka events, DB query, or structured log search), and step-by-step resolution procedures for each `failed_step` value (`ledger_post`, `ledger_post_refund`, `provider_status_query`). Includes idempotency key formats, safety notes, and escalation guidance.

### No deviations from spec

The task spec called for `refund_id` in the runbook/events; the workflow code uses `refundUuid` internally and the failed step string is `ledger_post_refund` (existing from prior task — more descriptive than the spec's `ledger_refund`). The runbook documents the actual string values from the code.