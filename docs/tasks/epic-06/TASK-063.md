### TASK-063 — Implement activities for provider, ledger, and notifications

#### Activity list
- select provider
- send provider auth/capture
- post ledger entries
- request merchant callback
- publish orchestration audit event

#### Done criteria
- activities do not contain workflow state logic;
- all external calls are moved into activities;
- retry policy is defined explicitly.

## Result

**Implemented 2026-04-15.**

### payment-orchestrator — new activity implementations

All 7 activity interfaces now have concrete `*Impl` classes under `app/Infrastructure/Activity/`:

| Implementation | Interface | Downstream service |
|---|---|---|
| `UpdatePaymentStatusActivityImpl` | `UpdatePaymentStatusActivity` | payment-domain `/api/internal/v1/payments/{id}/status` |
| `UpdateRefundStatusActivityImpl` | `UpdateRefundStatusActivity` | payment-domain `/api/internal/v1/refunds/{id}/status` |
| `ProviderCallActivityImpl` | `ProviderCallActivity` | provider-gateway (TASK-071) |
| `ProviderStatusQueryActivityImpl` | `ProviderStatusQueryActivity` | provider-gateway (TASK-071) |
| `LedgerPostActivityImpl` | `LedgerPostActivity` | ledger-service (EPIC-10) |
| `MerchantCallbackActivityImpl` | `MerchantCallbackActivity` | merchant-callback-delivery (EPIC-11) |
| `PublishDomainEventActivityImpl` | `PublishDomainEventActivity` | payment-domain event bus bridge (EPIC-12) |

`PaymentDomainClient` (`app/Infrastructure/Http/PaymentDomainClient.php`) is a shared HTTP helper extracted for the two domain-update activities. It attaches `X-Internal-Secret` on every request.

All 7 implementations are registered in `AppServiceProvider` and wired to the Temporal worker in `TemporalWorkerCommand`. Retry policies are defined in the workflows' `initActivities()` — activities are pure HTTP delegates with no workflow state.

### payment-domain — new internal endpoint

A separate route group protected by `InternalServiceMiddleware` (`X-Internal-Secret` header check) at `/api/internal/v1/`:

- `PATCH /api/internal/v1/payments/{id}/status` — no merchant_id scoping; accepts `pending_provider`, `authorized`, `captured`, `failed`, `requires_reconciliation`
- `PATCH /api/internal/v1/refunds/{id}/status` — accepts `pending_provider`, `succeeded`, `failed`, `requires_reconciliation`

Supporting additions:
- `RefundStateMachine` with transitions: `PENDING → {PENDING_PROVIDER, FAILED}`, `PENDING_PROVIDER → {SUCCEEDED, FAILED, REQUIRES_RECONCILIATION}`
- `RefundStatus` extended with `PENDING_PROVIDER` and `REQUIRES_RECONCILIATION` cases
- `PaymentStateMachine` extended: `AUTHORIZED` can now transition to `REQUIRES_RECONCILIATION` (ADR-010 Class B failure path)
- Migration `2026_04_14_000000_add_failure_reason_to_refunds_table` adds nullable `failure_reason` column to `refunds`
- `EventRouter` extended with routes for all new refund and reconciliation event types

### Tests

- `UpdatePaymentStatusActivityImplTest` — 8 unit tests (Http::fake)
- `UpdateRefundStatusActivityImplTest` — 8 unit tests (Http::fake)
- `InternalTransitionPaymentStatusTest` — 14 feature tests
- `InternalTransitionRefundStatusTest` — 12 feature tests

payment-orchestrator: **67 passed, 2 pre-existing HealthTest failures** (database unreachable in test env, existed before this task).
payment-domain: all tests pass.