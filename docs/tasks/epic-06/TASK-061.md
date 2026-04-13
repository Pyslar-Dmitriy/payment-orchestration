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
## Result

### Files created

**DTOs** (`app/Domain/DTO/`)
- `PaymentWorkflowInput.php` — input DTO for the workflow (paymentUuid, merchantId, amount, currency, country, correlationId)
- `ProviderCallResult.php` — activity result: providerReference, providerStatus, isAsync flag
- `ProviderStatusResult.php` — provider query result: providerStatus, isCaptured, isFailed flags

**Activity interfaces** (`app/Domain/Activity/`)
- `ProviderRoutingActivity.php` — `selectProvider(uuid, currency, country, excludedProviders[])` — selects provider with fallback support
- `ProviderCallActivity.php` — `authorizeAndCapture(uuid, providerKey, correlationId)` — submits to provider
- `ProviderStatusQueryActivity.php` — `queryStatus(uuid, providerKey, correlationId)` — timeout recovery query
- `UpdatePaymentStatusActivity.php` — `markPendingProvider`, `markAuthorized`, `markCaptured`, `markFailed`, `markRequiresReconciliation`
- `LedgerPostActivity.php` — `postCapture(uuid, correlationId)`
- `MerchantCallbackActivity.php` — `triggerCallback(uuid, status, correlationId)`
- `PublishDomainEventActivity.php` — `publishPaymentCaptured`, `publishPaymentFailed`, `publishPaymentRequiresReconciliation`

**Workflow** (`app/Domain/Workflow/`)
- `PaymentWorkflow.php` — interface with `#[WorkflowInterface]`, `run()`, `onAuthorizationResult()`, `onCaptureResult()` signal methods
- `PaymentWorkflowImpl.php` — full workflow implementation

**HTTP** (`app/Interfaces/Http/`)
- `Controllers/StartPaymentWorkflowController.php` — `POST /api/workflows/payments` starts the workflow
- `Requests/StartPaymentWorkflowRequest.php` — validates input (uuid, amount≥1, currency size:3, country size:2)

**Other**
- `routes/api.php` — route registered for `POST /api/workflows/payments`
- `app/Interfaces/Console/Commands/TemporalWorkerCommand.php` — `PaymentWorkflowImpl` registered with worker
- `docs/payment-orchestrator.postman_collection.json` — Postman collection with happy path, 409, and 422 examples

**Tests** (32 total, all passing)
- `tests/Unit/Domain/Workflow/PaymentWorkflowSignalHandlingTest.php` — 18 tests covering signal buffering, FIFO ordering, deduplication by provider_event_id, and isProviderSuccessStatus
- `tests/Unit/Domain/DTO/ProviderCallResultTest.php` — 2 tests
- `tests/Unit/Domain/DTO/ProviderStatusResultTest.php` — 3 tests
- `tests/Feature/StartPaymentWorkflowTest.php` — 9 tests covering 201/409 and all validation rules

### Design decisions

- **Signal deduplication** is performed at consume time (not at enqueue time). Both signal handlers blindly append to `$signalQueue`; `consumeNextSignal()` checks `$processedEventIds` and skips duplicates. This avoids needing Temporal context inside signal handlers.
- **30-minute timeout loop**: the workflow uses `Workflow::now()` for elapsed-time calculation in a while loop, re-calling `awaitWithTimeout` with the remaining seconds each iteration. This correctly handles the case where an auth signal arrives but capture is delayed beyond the original timeout.
- **Provider fallback**: on `ActivityFailure` from the provider call, the workflow calls `selectProvider` again with the failed provider excluded. One fallback attempt only.
- **Class B failure (requires_reconciliation)**: no merchant callback is triggered — per ADR-010 this is operator-intervention territory only. The `PaymentRequiresReconciliation` Kafka event serves as the operator alert.
- **`authorizationReceived` flag**: set when a successful `provider.authorization_result` signal is processed. Used in the timeout-query-failure branch to decide Class A vs Class B (if auth was confirmed and provider query fails, funds may be captured — Class B).
- **Activity implementations deferred**: all 7 activity interfaces are stubs; actual HTTP clients to payment-domain, ledger-service, etc. are implemented in TASK-063. Worker registration of activity implementations is also deferred to TASK-063.
