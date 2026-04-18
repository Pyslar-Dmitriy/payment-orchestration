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

#### Notes from TASK-090 review
- The inbox insert, normalization (TASK-091), and the Temporal signal dispatch must all succeed atomically or the message will be requeued and reprocessed. Ensure the inbox row is only committed after the signal is successfully dispatched (or accepted for retry), so a crash between steps does not produce a consumed-but-unsignalled message.
## Result

**Implemented 2026-04-18.**

### New files

| File | Purpose |
|---|---|
| `app/Domain/Signal/TemporalSignalDispatcherInterface.php` | Contract for dispatching normalized events as Temporal signals |
| `app/Domain/Signal/DeadWorkflowException.php` | Non-retryable exception thrown when workflow is not found or already closed |
| `app/Infrastructure/Signal/HttpTemporalSignalDispatcher.php` | HTTP implementation — calls `POST /api/signals/payments/{paymentId}` on payment-orchestrator |

### Modified files

| File | Change |
|---|---|
| `app/Domain/Normalizer/NormalizedWebhookEvent.php` | Added `paymentId` field (our internal payment UUID) |
| `app/Infrastructure/Normalizer/MockProviderNormalizer.php` | Extracts `paymentId` by stripping `mock-` prefix from `payment_reference` and validating as UUID |
| `app/Application/ProcessRawWebhook.php` | Integrated signal dispatch: signal is sent before the inbox row is committed so a crash cannot produce a consumed-but-unsignalled message |
| `app/Providers/AppServiceProvider.php` | Bound `TemporalSignalDispatcherInterface` → `HttpTemporalSignalDispatcher` |
| `config/services.php` | Added `payment_orchestrator.base_url` and `payment_orchestrator.internal_secret` |
| `.env` | Added `PAYMENT_ORCHESTRATOR_BASE_URL` and `INTERNAL_SERVICE_SECRET` |
| `tests/Unit/Infrastructure/Normalizer/MockProviderNormalizerTest.php` | Updated `paymentRef` helper to use `mock-{uuid}` format; added `paymentId` propagation test and two new error-case tests |
| `tests/Unit/Domain/Normalizer/ProviderNormalizerRegistryTest.php` | Updated fixtures to `mock-{uuid}` format; added `paymentId` to inline stub normalizer |
| `tests/Feature/ProcessRawWebhookTest.php` | Rewrote to mock `TemporalSignalDispatcherInterface`; added signal dispatch, dead-workflow, transient-error, and no-signal scenarios |

### Design decisions

- **`paymentId` on `NormalizedWebhookEvent`**: the signal URL needs our internal payment UUID (= Temporal workflow ID). Mock provider embeds it in `payment_reference` as `mock-{uuid}`. Each normalizer extracts it provider-specifically.
- **Signal before inbox commit**: if signal dispatch throws a transient `RuntimeException`, the exception propagates without committing the inbox row — the RabbitMQ consumer will nack and requeue. On dead-workflow (`DeadWorkflowException`), the warning is logged and execution continues to commit the inbox row, preventing infinite requeue loops.
- **Event-type-to-signal mapping in dispatcher**: `payment.authorized` → `provider.authorization_result`, `payment.captured` → `provider.capture_result`. Unmapped event types are silently skipped (no HTTP call, inbox committed normally).
- **HTTP 404 = DeadWorkflowException**: payment-orchestrator returns 404 for both `WorkflowNotFoundException` and gRPC NOT_FOUND. Any other non-2xx is a transient `RuntimeException`.
- **`WebhookSignalUndeliverable` Kafka event**: TASK-094 deferred — the `dispatchSignal` method has a comment marking the TASK-094 hook.

### Tests

37 passed (66 assertions).
