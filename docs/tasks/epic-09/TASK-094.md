# TASK-094 — Handle signals delivered to dead, completed, or timed-out Temporal workflows

## Context

The `webhook-normalizer` signals a Temporal workflow by `workflow_id = payment_id` (TASK-092). If the workflow has already completed, failed, or timed out when the signal arrives, Temporal will return an error (workflow not found or already closed). This case must be explicitly handled.

It is a realistic scenario: a webhook arrives late from the provider after the Temporal workflow has already terminated (e.g., due to a timeout marking the payment `failed`, or a prior webhook already completing the workflow). The late webhook may carry information that is relevant (e.g., a success signal for a payment marked `failed` due to timeout) or may be genuinely stale.

See also: GAP-005 in `docs/architecture/gaps.md`.

## Decision (to implement)

When the signal delivery call returns a "workflow not found" or "workflow already closed" error:

1. **Do not silently swallow the error.**
2. Log at `WARNING` level including `payment_id`, `correlation_id`, `signal_type`, and `provider_event_id`.
3. Publish a `WebhookSignalUndeliverable` event to Kafka topic `provider.webhooks.normalized.v1` (or a dedicated `provider.webhooks.undeliverable.v1` topic) with:
   - `payment_id`
   - `correlation_id`
   - `normalized_status` — what the webhook was carrying
   - `reason` — `workflow_not_found` or `workflow_already_closed`
   - `provider_event_id`
   - `occurred_at`
4. The event provides a durable record for operator investigation. A future reconciliation consumer or manual review process can determine whether the late webhook should change the payment state.

### What NOT to do

* Do not automatically start a new workflow for the payment — the payment already reached a terminal state from the platform's perspective. Starting a new workflow could create a duplicate flow.
* Do not retry the signal indefinitely — if the workflow is closed, retrying will not help.
* Do not silently discard the signal — a late success webhook for a `failed` payment is an exceptional financial event that requires human review.

## Done criteria

- Signal delivery in `webhook-normalizer` wraps the Temporal signal call in explicit error handling.
- "Workflow not found" and "Workflow already closed" are caught distinctly from transient errors (which should still be retried per TASK-092).
- `WARNING` log is emitted with all required fields.
- `WebhookSignalUndeliverable` event is published to Kafka on undeliverable signal.
- Unit test covers: signal succeeds, signal fails with transient error (should retry), signal fails with workflow-closed error (should warn + publish event).
## Result

### Files modified

**webhook-normalizer:**
- `app/Domain/Signal/DeadWorkflowException.php` — added `$deadReason` constructor property (`workflow_not_found` | `workflow_already_closed`) and `getDeadReason()` accessor; constructor promotion with default `workflow_not_found`
- `app/Infrastructure/Signal/HttpTemporalSignalDispatcher.php` — on HTTP 404, reads `reason` from the JSON response body and passes it to `DeadWorkflowException`; defaults to `workflow_not_found` if the field is absent
- `app/Application/ProcessRawWebhook.php` — `dispatchSignal` now returns `?string` (the dead reason or `null` on success); WARNING log extended with `signal_type`, `provider_event_id`, and `reason`; the same DB transaction that writes inbox + normal outbox also inserts a `webhook.signal.undeliverable.v1` outbox event (with `payment_id`, `correlation_id`, `normalized_status`, `reason`, `provider_event_id`, `occurred_at`) when a dead workflow is detected
- `tests/Feature/ProcessRawWebhookTest.php` — added 6 new tests covering: warning log fields, undeliverable event written on dead workflow, undeliverable event payload fields, event not written on success, event not written on transient error; updated existing dead-workflow test to use explicit `reason` argument

**payment-orchestrator:**
- `app/Interfaces/Http/Controllers/SignalPaymentWorkflowController.php` — 404 responses now include a `reason` field: `workflow_not_found` for `WorkflowNotFoundException`, `workflow_already_closed` for `ServiceClientException` gRPC code 5
- `tests/Feature/SignalPaymentWorkflowTest.php` — updated `test_returns_404_when_workflow_not_found_exception_is_thrown` to also assert `reason: workflow_not_found`; renamed and updated the `ServiceClientException` test to assert `reason: workflow_already_closed`

### Design decisions

- Used a single `$deadReason` string instead of two `DeadWorkflowException` subclasses — simpler and sufficient for the task.
- Undeliverable event written to the same `outbox_events` table/topic (`provider.webhooks.normalized.v1`) as normal events rather than a dedicated topic; the `event_type` field (`webhook.signal.undeliverable.v1`) provides the distinction. A dedicated topic can be added in a future task without touching the publish path.
- Transient errors (`RuntimeException`) still propagate unchanged — no behavior change for retried signals.
