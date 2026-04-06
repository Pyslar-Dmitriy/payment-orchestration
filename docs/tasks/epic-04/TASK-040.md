# TASK-040 — Implement Authentication and Merchant Context

### Add a merchant authentication mechanism.

**Authentication mechanism: API key authentication — see ADR-009.**

The chosen mechanism is static API key authentication (Bearer token). Key details:
- Secret API key issued at merchant onboarding, stored as a salted hash (bcrypt/Argon2).
- Merchants send `Authorization: Bearer <key>` on every request over TLS.
- Key format: `pk_live_<32-char-random-hex>` — e.g. `pk_live_a3f7c2...` (see ADR-009 for full format spec). The prefix is not a credential and is safe to appear in logs; the random suffix must never be logged.
- Keys are rotatable: issuing a new key invalidates the old one after a configurable grace period.
- A single key scope for v1 — per-key capabilities and IP allowlisting are deferred.

### Requires implementation of:
- merchant credentials table with salted-hash storage (never store plaintext key);
- key issuance flow (generate, hash and store, return plaintext once to merchant);
- auth middleware that validates the Bearer token and rejects unauthorized requests;
- request binding to `merchant_id` — every authenticated request carries merchant context;
- key rotation endpoint (generate new key, invalidate old);
- a basic role model for the API (single scope in v1).

## Readiness Criteria
- each request knows the `merchant_id`;
- unauthorized requests are rejected with `401`;
- audit contains the merchant context;
- plaintext key is never persisted or logged;
- key rotation does not require a deployment.

## Result

Implemented API key authentication for the merchant-api service. All readiness criteria met. 16 feature tests pass.

### Files created

| File | Purpose |
|---|---|
| `app/Domain/Merchant/Merchant.php` | Eloquent model; owns `api_keys` relation; overrides `newFactory()` to resolve flat factory namespace |
| `app/Domain/Merchant/ApiKey.php` | Eloquent model; key generation (`generatePlaintext`), SHA-256 hashing (`hashKey`), prefix extraction, and `findByPlaintext` for O(1) authenticated lookup; overrides `newFactory()` |
| `app/Application/Merchant/IssueApiKey.php` | Use case: generates key, persists only the SHA-256 hash, returns plaintext once |
| `app/Application/Merchant/RotateApiKey.php` | Use case: issues new key, expires old key (immediate or after configurable grace period) |
| `app/Http/Middleware/AuthenticateApiKey.php` | Extracts Bearer token, looks up by hash, rejects with 401 on missing/invalid/expired key, binds `merchant` and `api_key` to `$request->attributes`, shares `merchant_id` in `Log::shareContext` |
| `app/Interfaces/Http/Controllers/CreateMerchantController.php` | Single-action: `POST /api/v1/merchants` — creates merchant + issues first key |
| `app/Interfaces/Http/Controllers/ShowMerchantController.php` | Single-action: `GET /api/v1/merchants/me` — returns merchant context from `$request->attributes` |
| `app/Interfaces/Http/Controllers/RotateApiKeyController.php` | Single-action: `POST /api/v1/api-keys/rotate` — rotates key, optional `grace_minutes` body param |
| `app/Interfaces/Http/Controllers/HealthController.php` | Multi-method exception per ADR-014: `GET /health` (liveness) and `GET /ready` (DB ping) |
| `app/Interfaces/Http/Requests/CreateMerchantRequest.php` | FormRequest for `POST /v1/merchants`; validates `name`, `email` (unique), optional `callback_url` |
| `app/Interfaces/Http/Requests/RotateApiKeyRequest.php` | FormRequest for `POST /v1/api-keys/rotate`; validates optional `grace_minutes` (0–1440) |
| `database/factories/MerchantFactory.php` | Test factory for Merchant |
| `database/factories/ApiKeyFactory.php` | Test factory for ApiKey; `withPlaintext($key)` and `expired()` states |
| `tests/Feature/Auth/ApiKeyAuthTest.php` | 16 feature tests covering 401 enforcement, merchant context binding, `last_used_at` tracking, key issuance, rotation with and without grace period |
| `docker-entrypoint.sh` | Container startup script; regenerates `config:cache`, `route:cache`, `event:cache` at runtime before handing off to `php-fpm` |
| `docs/merchant-api.postman_collection.json` | Postman collection for manual testing; auto-saves `api_key` variable from Create Merchant response |

### Files modified

| File | Change |
|---|---|
| `routes/api.php` | `/v1` prefix group; single-action controller shorthand (`Controller::class`); health routes removed (moved to `bootstrap/app.php`) |
| `bootstrap/app.php` | Registered `auth.api` → `AuthenticateApiKey`; registered `web:` and `api:` routing; health/ready routes registered via `then:` callback with no middleware group to avoid session middleware |
| `config/auth.php` | Removed unused `User` model reference; set provider model to `Merchant::class` |
| `.env.example` | Added `KEY_ROTATION_GRACE_MINUTES=0` |
| `Dockerfile` | Removed build-time `artisan cache` commands (moved to entrypoint); added `ENTRYPOINT` |
| `infra/docker/nginx/nginx.conf` | `SCRIPT_FILENAME` uses `/var/www/html/public` (php-fpm container path, not nginx mount path) for all 9 service blocks |

### Files deleted

| File | Reason |
|---|---|
| `app/Http/Controllers/Controller.php` | Unused Laravel scaffold; no base controller needed in this project |
| `app/Models/User.php` | Unused Laravel scaffold |
| `database/factories/UserFactory.php` | Unused Laravel scaffold |
| `app/Interfaces/Http/Controllers/MerchantController.php` | Replaced by two single-action controllers per ADR-014 |
| `app/Interfaces/Http/Controllers/ApiKeyController.php` | Replaced by single-action controller per ADR-014 |
| `tests/Feature/ExampleTest.php` | Default scaffold test; `GET /` has no route in this API-only service |

### Key design decisions

- **SHA-256 for lookup**: The `key_hash` column uses deterministic SHA-256 (not bcrypt) to allow O(1) database lookup. Security is derived from the key's 128-bit random entropy (`bin2hex(random_bytes(16))`), making brute-force infeasible. This is the same model used by Stripe and Laravel Sanctum.
- **Prefix is safe to log**: `pk_live_` (8 chars) is stored as `key_prefix` for display; the random suffix never appears in logs.
- **Grace period is env-configurable**: `KEY_ROTATION_GRACE_MINUTES` drives rotation without a deployment.
- **Merchant context via `$request->attributes`**: Downstream controllers read `$request->attributes->get('merchant')` — no global state, no Laravel auth guard plumbing needed.
- **Single-action controllers** (ADR-014): one class per endpoint, `__invoke` only, named after the HTTP action. `HealthController` is the documented exception (infrastructure probes, no business logic).
- **FormRequest classes** (ADR-013): validation rules live in `app/Interfaces/Http/Requests/`, co-located with controllers, independently testable.
- **Health routes outside api/web groups**: health/ready are registered via `withRouting(then: ...)` with `Route::middleware([])` so they carry no session or API middleware. This avoids the session driver (`database`) being invoked on routes that need no state.
- **Entrypoint cache regeneration**: `config:cache`, `route:cache`, and `event:cache` run at container startup (not build time) so the caches reflect the runtime environment (`DB_HOST`, `APP_KEY`, etc.) rather than the sandboxed build environment.