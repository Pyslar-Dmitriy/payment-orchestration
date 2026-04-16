### TASK-071 — Implement `MockProvider`

#### What to do
Create a controllable mock provider that can:
- return success;
- return timeout;
- send delayed webhook;
- send duplicate webhook;
- send out-of-order statuses.

#### Done criteria
- scenarios are configurable via config or test flags;
- the mock provider is suitable for integration and load tests.

## Result

### Files created

- `app/Infrastructure/Provider/Mock/MockScenario.php` — PHP 8.1 backed enum with 7 values: `success`, `timeout`, `hard_failure`, `async_webhook`, `delayed_webhook`, `duplicate_webhook`, `out_of_order`.
- `app/Infrastructure/Provider/Mock/MockProviderAdapter.php` — full `ProviderAdapterInterface` implementation. Reads `config('mock_provider.scenario')` on every call so tests can switch scenarios mid-test via `config()->set()`. Dispatches `DeliverMockWebhookJob` for all async scenarios.
- `app/Infrastructure/Provider/Mock/Jobs/DeliverMockWebhookJob.php` — queued job that POSTs the mock webhook payload to `MOCK_PROVIDER_WEBHOOK_URL`. No-op when the URL is not configured; safe for unit tests without a real webhook endpoint.
- `config/mock_provider.php` — exposes `scenario`, `webhook_url`, and `webhook_delay_seconds`, all driven by env vars.

### Files modified

- `app/Providers/AppServiceProvider.php` — registers `MockProviderAdapter` with `ProviderRegistry` at boot; fills the placeholder left in TASK-070.
- `.env.example` — added `MOCK_PROVIDER_SCENARIO`, `MOCK_PROVIDER_WEBHOOK_URL`, `MOCK_PROVIDER_WEBHOOK_DELAY_SECONDS`.

### Tests added

- `tests/Unit/Infrastructure/Provider/Mock/MockProviderAdapterTest.php` — 31 unit tests covering all 7 scenarios for `authorize()`, `capture()`, `refund()`, `queryPaymentStatus()`, `queryRefundStatus()`, `parseWebhook()`, and `mapStatus()`. Job dispatch counts and properties are asserted with `Queue::fake()`.
- `tests/Feature/Http/MockProviderIntegrationTest.php` — 12 feature tests exercising the full HTTP layer with `provider_key=mock`, including all scenarios for authorize and the refund/status endpoints.

### Design decisions

**Scenario switching via config**: The adapter calls `config('mock_provider.scenario')` on every invocation rather than reading once at construction. This means tests can switch scenarios with a single `config()->set(...)` call without rebuilding or re-registering the adapter.

**Webhook delivery is optional**: `DeliverMockWebhookJob` is a no-op when `MOCK_PROVIDER_WEBHOOK_URL` is not set. This keeps unit and feature tests clean without requiring Http::fake() for every async scenario test.

**Out-of-order simulation**: The adapter dispatches `CAPTURED` immediately and `AUTHORIZED` with a 1-second delay, so the capture event arrives before authorization — the canonical out-of-order scenario for the deduplication and ordering tests in EPIC-16.

**Duplicate webhook simulation**: Both jobs in the `duplicate_webhook` scenario use the same deterministic event ID (`mock-evt-{paymentUuid}-payment-captured-dup`), enabling the webhook-ingest deduplication tests to assert that only one event is processed.