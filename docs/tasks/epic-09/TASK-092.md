### TASK-092 — Signal the Temporal workflow with a normalized event

#### What to do
After normalization, find the correct workflow by `workflow_id = payment_id` and send the appropriate signal.

Use the signal contract defined in TASK-061:
- `provider.authorization_result` for authorization confirmations.
- `provider.capture_result` for capture confirmations.

Include in the signal payload: `provider_event_id`, `provider_status`, `provider_reference`, `correlation_id`.

#### Error handling

Differentiate between error types when the signal delivery fails:

| Error type | Action |
|---|---|
| Transient error (network timeout, Temporal unavailable) | Retry with backoff (standard retry policy) |
| `WorkflowNotFound` | Do NOT retry. Emit `WARNING` log + publish `WebhookSignalUndeliverable` Kafka event. See TASK-094. |
| `WorkflowAlreadyClosed` | Do NOT retry. Same handling as `WorkflowNotFound`. See TASK-094. |

TASK-094 handles the implementation of the dead-workflow signal path in detail.

#### Done criteria
- the signal contains enough data for the workflow to make a state transition decision;
- duplicate signals (same `provider_event_id`) do not break the workflow — the workflow deduplicates them;
- transient signal failures are retried with backoff;
- dead-workflow signal failures are handled per TASK-094 (warn + publish event, no retry).