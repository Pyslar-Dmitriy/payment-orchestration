### TASK-064 — Implement signal handling from webhook normalizer

#### What to do
Allow the normalizer to find the correct workflow and signal it with a normalized event.

#### Done criteria
- the signal locates the workflow by payment reference;
- the workflow handles the signal exactly once from a business perspective;
- duplicate signals are safe.

## Result

**Implemented 2026-04-15.**

### New signal endpoints

Two new HTTP endpoints added to `payment-orchestrator`, protected by `InternalServiceMiddleware` (`X-Internal-Secret` header):

| Method | Path | Workflow | Signals |
|---|---|---|---|
| `POST` | `/api/signals/payments/{workflowId}` | `PaymentWorkflow` | `provider.authorization_result`, `provider.capture_result` |
| `POST` | `/api/signals/refunds/{workflowId}` | `RefundWorkflow` | `provider.refund_result` |

Both routes enforce `->whereUuid('workflowId')` — non-UUID paths return 404 automatically.

### Workflow location by payment reference

The Temporal `workflowId` for payments equals `payment_uuid` and for refunds equals `refund_uuid` (established in TASK-061/062). The signal endpoint uses `WorkflowClientInterface::newRunningWorkflowStub()` to locate the correct running workflow by that ID — no additional lookup needed.

### Exactly-once / duplicate-safe semantics

Deduplication is handled inside the workflows themselves via `processedEventIds` keyed on `provider_event_id` (implemented in TASK-061/062 and tested in `PaymentWorkflowSignalHandlingTest` / `RefundWorkflowSignalHandlingTest`). The HTTP endpoint forwards every inbound signal to Temporal; the workflow discards duplicates during `consumeNextSignal()`.

### New files

| File | Purpose |
|---|---|
| `app/Http/Middleware/InternalServiceMiddleware.php` | Validates `X-Internal-Secret` header; reads `config('services.internal.secret')` |
| `app/Interfaces/Http/Requests/SignalPaymentWorkflowRequest.php` | Validates `signal_name` (enum), `provider_event_id`, `provider_status`, `provider_reference` (nullable), `correlation_id` |
| `app/Interfaces/Http/Requests/SignalRefundWorkflowRequest.php` | Same for refund signal (`provider.refund_result` only) |
| `app/Interfaces/Http/Controllers/SignalPaymentWorkflowController.php` | Routes validated signal to typed `PaymentWorkflow` stub; 404 on `WorkflowNotFoundException` or gRPC NOT_FOUND |
| `app/Interfaces/Http/Controllers/SignalRefundWorkflowController.php` | Same for refund |
| `tests/Feature/SignalPaymentWorkflowTest.php` | 11 feature tests |
| `tests/Feature/SignalRefundWorkflowTest.php` | 11 feature tests |

### Modified files

- `config/services.php` — added `internal.secret` key (`INTERNAL_SERVICE_SECRET` env var)
- `routes/api.php` — added signal routes behind `InternalServiceMiddleware`
- `docs/payment-orchestrator.postman_collection.json` — added "Signals" folder with 3 requests (2 payment signals, 1 refund signal)

### Tests

payment-orchestrator: **92 passed, 2 pre-existing HealthTest failures** (database unreachable in test env, existed before this task).