### TASK-062 — Implement `RefundWorkflow`

#### What to do
Orchestrate the refund flow separately from the payment flow.

#### Workflow logic
- accept a refund request;
- send the refund request to the provider;
- wait for confirmation;
- trigger ledger reversal/refund entries;
- send a merchant callback.

#### Done criteria
- the refund flow is separated from the payment flow;
- the workflow is visible in Temporal UI;
- duplicate requests do not lead to duplicate operations.

## Result

### Files created

**DTOs** (`app/Domain/DTO/`)
- `RefundWorkflowInput.php` — input DTO (refundUuid, paymentUuid, merchantId, amount, currency, providerKey, correlationId)
- `RefundStatusResult.php` — provider query result for refunds: providerStatus, isRefunded, isFailed flags

**Activity interfaces** (`app/Domain/Activity/`)
- `UpdateRefundStatusActivity.php` — new interface with `markPendingProvider`, `markCompleted`, `markFailed`, `markRequiresReconciliation`
- `ProviderCallActivity.php` — extended with `refund(refundUuid, paymentUuid, providerKey, correlationId)` method
- `LedgerPostActivity.php` — extended with `postRefund(refundUuid, correlationId)` method
- `PublishDomainEventActivity.php` — extended with `publishRefundCompleted`, `publishRefundFailed`, `publishRefundRequiresReconciliation`
- `ProviderStatusQueryActivity.php` — extended with `queryRefundStatus(refundUuid, providerKey, correlationId)` returning `RefundStatusResult`

**Workflow** (`app/Domain/Workflow/`)
- `RefundWorkflow.php` — interface with `#[WorkflowInterface]`, `run()`, `onRefundResult()` signal method
- `RefundWorkflowImpl.php` — full workflow implementation

**HTTP** (`app/Interfaces/Http/`)
- `Controllers/StartRefundWorkflowController.php` — `POST /api/workflows/refunds` starts the workflow
- `Requests/StartRefundWorkflowRequest.php` — validates input (refund_uuid UUID, payment_uuid UUID, amount≥1, currency size:3, provider_key max:64, correlation_id UUID)

**Other**
- `routes/api.php` — route registered for `POST /api/workflows/refunds`
- `app/Interfaces/Console/Commands/TemporalWorkerCommand.php` — `RefundWorkflowImpl` registered with worker
- `docs/payment-orchestrator.postman_collection.json` — Postman collection updated with happy path, 409, and 422 examples for refund workflow

**Tests** (22 new tests, all 54 total passing)
- `tests/Unit/Domain/Workflow/RefundWorkflowSignalHandlingTest.php` — 11 tests covering signal buffering, FIFO ordering, deduplication by provider_event_id, and isRefundSuccessStatus
- `tests/Feature/StartRefundWorkflowTest.php` — 11 tests covering 201/409 and all validation rules

### Design decisions

- **workflow_id = refund_uuid**: Temporal's uniqueness guarantee prevents duplicate refund workflows — a second `POST /api/workflows/refunds` for the same `refund_uuid` returns 409.
- **No provider routing**: Unlike the payment workflow, the provider is already known at refund time (passed as `provider_key` in the request). The routing activity is not used.
- **Single signal type**: `provider.refund_result` — refunds have no authorization/capture split, so only one signal is needed. The queue approach is kept for deduplication consistency.
- **Class B failure on timeout query failure**: If the provider status query fails after timeout, we apply Class B compensation (requires_reconciliation) since the provider may have already issued the refund to the customer. This is more conservative than the payment workflow's approach for the same scenario.
- **Ambiguous timeout status → Class B**: Unlike the payment workflow (which uses Class A for unknown status), a refund with unknown status after timeout is treated as Class B — the refund may have been issued, so we flag for reconciliation rather than marking failed.
- **Activity implementations deferred**: All activity stubs remain unimplemented (TASK-063).