### TASK-070 — Design the provider abstraction layer

#### What to do
Introduce a provider interface with a unified contract for:
- authorize
- capture
- refund
- parse webhook
- map status

#### Done criteria
- adding a new provider is easy;
- business logic is not scattered across adapters;
- payment-domain and orchestrator do not know PSP API details.

## Result

### Files created

**Domain layer** (`apps/provider-gateway/app/Domain/Provider/`):
- `ProviderAdapterInterface` — unified PSP contract with 8 methods: `providerKey`, `authorize`, `capture`, `refund`, `queryPaymentStatus`, `queryRefundStatus`, `parseWebhook`, `mapStatus`.
- `ProviderRegistryInterface` — lookup contract for resolving an adapter by key.
- DTOs: `AuthorizeRequest/Response`, `CaptureRequest/Response`, `RefundRequest/Response`, `StatusQueryRequest/Response`, `RefundStatusQueryRequest/Response`, `ParsedWebhookEvent`.
- Exceptions: `ProviderNotFoundException`, `ProviderHardFailureException`, `ProviderTransientException`.

**Infrastructure layer**: `ProviderRegistry` — in-process map keyed by `providerKey()`. Registered as a singleton in `AppServiceProvider`.

**Application layer** (`app/Application/Provider/`):
- `AuthorizeAndCaptureHandler` — calls `authorize()`, then `capture()` unless the PSP captured atomically or the response is async.
- `RefundHandler`, `QueryPaymentStatusHandler`, `QueryRefundStatusHandler`.

**HTTP layer**: 4 controllers + 2 Form Request classes + updated `routes/api.php`.

Routes added:
- `POST /api/v1/provider/authorize`
- `POST /api/v1/provider/refund`
- `GET /api/v1/provider/payments/{paymentUuid}/status`
- `GET /api/v1/provider/refunds/{refundUuid}/status`

### Design decisions

**Authorize + capture separation**: the interface has separate `authorize` and `capture` methods. The `AuthorizeAndCaptureHandler` calls them in sequence when necessary, but skips `capture` when the PSP returns `isCaptured = true` (atomic) or `isAsync = true`.

**Optional fields on HTTP endpoints**: `amount`, `currency`, `country` are accepted as optional request fields. The orchestrator currently sends only `payment_uuid` + `provider_key` + `correlation_id` (TASK-063). Real PSP adapters can access the full context once the orchestrator is extended to pass it.

**`provider_reference` on status queries**: accepted as an optional query parameter. Without it, adapters that require the PSP reference to query status must look it up from the audit log (TASK-072). Mock adapters (TASK-071) can operate without it.

**40 tests pass** across 5 test files (4 Feature, 1 Unit). The single pre-existing failure in `ExampleTest` is unrelated scaffolding from TASK-011.