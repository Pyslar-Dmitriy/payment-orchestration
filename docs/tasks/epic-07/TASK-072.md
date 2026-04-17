### TASK-072 — Implement provider request/response audit logging

#### What to do
Store the history of communication with the provider:
- request payload;
- response payload;
- status code;
- latency;
- timestamps;
- correlation/payment references.

#### Done criteria
- it is possible to reconstruct what was sent to the provider and what came back;
- logs are useful for debugging errors.

## Result

### Files created
- `database/migrations/2026_01_01_000013_create_provider_audit_logs_table.php` — append-only `provider_audit_logs` table with UUID PK, `provider_key`, `operation`, `payment_uuid`, `refund_uuid`, `correlation_id`, `request_payload` (JSON), `response_payload` (JSON, null on failure), `outcome` (success/hard_failure/transient_failure/exception), `error_code`, `error_message`, `duration_ms`, `requested_at`, `responded_at`, `created_at`; no `updated_at`.
- `app/Infrastructure/Provider/Audit/ProviderAuditLoggerInterface.php` — logger contract.
- `app/Infrastructure/Provider/Audit/ProviderAuditLogger.php` — Eloquent-backed implementation.
- `app/Infrastructure/Provider/Audit/ProviderAuditLog.php` — append-only Eloquent model (no `updated_at`, JSON casts for payloads).
- `app/Infrastructure/Provider/Audit/AuditingProviderAdapter.php` — transparent decorator wrapping any `ProviderAdapterInterface`; captures start/end timestamps around each call, records request and response payloads, classifies exceptions into outcomes.
- `tests/Feature/Http/ProviderAuditLogTest.php` — feature tests covering audit record creation for success, hard failure, transient failure, all five audited operations, and timestamp/duration fields.
- `tests/Unit/Infrastructure/Provider/Audit/AuditingProviderAdapterTest.php` — unit tests for the decorator using an in-memory logger spy (no DB).

### Files modified
- `app/Providers/AppServiceProvider.php` — binds `ProviderAuditLoggerInterface → ProviderAuditLogger` as singleton; wraps each registered adapter with `AuditingProviderAdapter` via a private `audited()` helper.

### Design decisions
- **Decorator pattern**: no changes to handlers, adapters, or controllers. Any future adapter added with `$registry->register($this->audited(...))` is automatically audited.
- **`parseWebhook` and `mapStatus` are not audited**: they are pure in-process transformations with no provider I/O.
- **Interface for the logger**: `ProviderAuditLoggerInterface` keeps the decorator testable without a database.
- **Append-only model**: no `updated_at`, matching the outbox and ledger conventions in this codebase.