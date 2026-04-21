### TASK-110 — Design callback subscriptions and delivery history model

#### Tables
- `merchant_callback_subscriptions`
- `merchant_callback_deliveries`
- `merchant_callback_attempts`

#### Data to store
- merchant ID;
- callback URL;
- secret/signature config;
- event types;
- delivery status;
- retry count.

#### Done criteria
- delivery history can be traced;
- it is possible to understand why a callback failed.

## Result

**Migrations created** (in `apps/merchant-callback-delivery/database/migrations/`):
- `2026_04_21_000020` — creates `merchant_callback_subscriptions` (merchant_id, callback_url, signing_secret, signing_algorithm, event_types JSON, is_active; unique on `(merchant_id, callback_url)`)
- `2026_04_21_000021` — renames `callback_deliveries` → `merchant_callback_deliveries`; adds nullable `subscription_id` FK with `nullOnDelete` so delivery history survives subscription deletion; makes `payment_id` nullable to support non-payment event types
- `2026_04_21_000022` — creates `merchant_callback_attempts` (append-only, one row per HTTP call: attempt_number, http_status_code, response_body, response_headers, failure_reason, duration_ms)

**Domain models** (in `app/Domain/Callback/`):
- `CallbackSubscription` — HasUuids, event_types cast to array, is_active cast to bool, `deliveries()` HasMany
- `CallbackDelivery` — HasUuids, status cast to `DeliveryStatus` enum, `subscription()` BelongsTo, `attempts()` HasMany ordered by attempt_number
- `CallbackAttempt` — append-only (UPDATED_AT = null), failure_reason cast to `FailureReason` enum
- `DeliveryStatus` enum: pending | delivered | failed | dlq
- `FailureReason` enum: timeout | connection_error | non_2xx | invalid_response | tls_error

**Tests**: `tests/Feature/CallbackModelTest.php` — 14 tests covering subscription uniqueness, delivery–subscription FK lifecycle, attempt history tracing, failure_reason auditing, append-only constraint on attempts. All pass.