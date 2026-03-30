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