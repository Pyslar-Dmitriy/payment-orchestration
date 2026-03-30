### TASK-061 — Implement `PaymentWorkflow`

#### Workflow logic
- start by `payment_uuid`;
- select a provider via the routing activity (see ADR-011 and TASK-073);
- call the provider authorize/capture activity;
- move into waiting for a webhook signal;
- on signal, evaluate the result;
- call the ledger activity;
- trigger merchant callback delivery;
- complete the workflow.
- On permanent downstream failure after an external side effect: apply the compensation path (see ADR-010 and TASK-065).

#### Timeout policy

The workflow sets a **webhook signal wait timeout of 30 minutes** after the provider request is submitted.

When the timeout fires with no signal received:
1. Query the provider for the current payment status (a `ProviderStatusQuery` activity, distinct from the initial authorize call).
2. If the provider confirms capture or authorization: treat as if the signal arrived and continue the normal flow.
3. If the provider returns `failed` or an unknown status: transition payment to `failed` (Class A — no captured funds). Publish `PaymentFailed` to Kafka. Trigger merchant callback. Complete workflow.
4. If the provider query itself fails: apply the compensation path (`requires_reconciliation`) as a Class B failure if prior authorization already succeeded, otherwise `failed`.

This avoids marking a payment `failed` when the webhook was simply delayed but the provider had already confirmed the transaction.

#### Signal contract

The workflow listens for **two distinct signal types**:

| Signal name | When sent | Payload fields |
|---|---|---|
| `provider.authorization_result` | Provider confirms authorization (async auth flow) | `provider_event_id`, `provider_status`, `provider_reference`, `correlation_id` |
| `provider.capture_result` | Provider confirms capture (async capture flow or direct-capture webhook) | `provider_event_id`, `provider_status`, `provider_reference`, `correlation_id` |

The workflow uses separate `selector` / `waitCondition` handlers for each signal type. Both signals carry a `provider_status` field mapped to the internal status vocabulary (see TASK-091). The workflow evaluates this field to determine the next state transition.

For PSPs that send a single combined auth+capture webhook: both signal types may arrive as one, or only `provider.capture_result` is used — this is handled by the normalization layer (TASK-091).

#### Multi-signal handling

The workflow handles signals sequentially using a signal queue / channel. Signals arriving while the workflow is processing a prior signal are buffered and processed in order. Duplicate signals (same `provider_event_id`) are deduplicated by the workflow — if a signal's `provider_event_id` matches one already processed, it is skipped with a debug log.

#### What to consider
- `workflow_id = payment_uuid` — guaranteed unique per payment;
- retry policy for each activity (defined in TASK-142);
- deterministic workflow code — no random/time calls outside of Temporal APIs;
- all time-based decisions use Temporal's `workflow.Now()` not `time.Now()`.

#### Done criteria
- the workflow can be started from payment-domain;
- it survives worker restarts correctly;
- a webhook signal continues the workflow correctly;
- the 30-minute timeout triggers a provider status query, not an immediate `failed` transition;
- duplicate signals with the same `provider_event_id` do not cause duplicate state transitions;
- the compensation path (ADR-010) is triggered when the ledger activity permanently fails after a confirmed provider capture.