# TASK-046 — Review and harden the merchant-api → payment-domain communication

### Background

`POST /api/v1/payments` in merchant-api currently calls payment-domain over a synchronous HTTP request (`PaymentDomainClient`) with no resilience primitives. This was an acceptable starting point but has several weak points that need to be addressed before the flow can be considered production-ready.

### Weak points

#### 1. No timeout
`PaymentDomainClient` creates an `Http::` client with no explicit timeout. If payment-domain is slow (GC pause, migration lock, DB saturation), the merchant request will hang indefinitely, eventually exhausting php-fpm workers and making merchant-api unresponsive to all traffic — not just payment creation.

**Fix**: set a short connect timeout (e.g. 2 s) and a request timeout (e.g. 5 s). Return `503` to the merchant if exceeded.

#### 2. No retry logic
Transient network blips or a payment-domain rolling restart will produce a hard failure with no retry. For a synchronous command this is acceptable only if the caller (merchant) handles retry via the idempotency key — but currently there is nothing preventing a merchant from not sending the key, in which case a transient failure is just a lost payment attempt.

**Fix**: one automatic retry on connection-level errors (not on 4xx). Idempotency key on the payment-domain side (or the outbox dedup) prevents duplicate creation on retry.

#### 3. No circuit breaker
If payment-domain goes down, every merchant request will wait for the full timeout before failing. Under load this creates a thundering-herd effect as requests pile up waiting for the timeout window.

**Fix**: add a circuit breaker (e.g. via a simple in-process counter or a library like `ganesha`). After N consecutive failures, open the circuit and return `503` immediately for a cooldown period.

#### 4. Synchronous HTTP couples merchant-api availability to payment-domain availability
If payment-domain is down, `POST /payments` returns 5xx. The merchant has no payment — but merchant-api itself is otherwise healthy. This is a tight availability coupling that violates the async-first principle from ADR-008.

**Long-term consideration**: evaluate whether payment creation should be async (merchant-api enqueues a command to RabbitMQ, payment-domain consumes it, merchant polls `GET /payments/{id}` or receives a callback). This is a larger design change and should be decided in the context of EPIC-05/EPIC-06 work, but the synchronous path should at minimum be hardened with the fixes above.

#### 6. payment-domain endpoints are unauthenticated
`POST /api/v1/payments`, `GET /api/v1/payments/{id}`, and `POST /api/v1/refunds` in payment-domain have no authentication or caller-identity check. They are only protected by Docker network isolation (payment-domain is not exposed on a public port). Any service inside the private network can call them directly, bypassing merchant-api entirely — including the merchant_id scoping that prevents cross-merchant data access.

**Fix**: add a shared-secret or mTLS check so payment-domain only accepts requests from merchant-api. A simple approach: require an `X-Internal-Secret` header (shared via Docker secrets / env vars) and reject anything that doesn't match. A more robust approach: mTLS between services (fits with a future Kubernetes/service-mesh setup from EPIC-20).

#### 5. No structured error mapping
`PaymentDomainClient` does not currently map payment-domain HTTP error responses (422, 409, 500) to distinct merchant-facing error codes. A validation error inside payment-domain surfaces as a generic 500 to the merchant.

**Fix**: map known payment-domain response codes to explicit merchant-facing responses with stable error codes (per the error contract in TASK-045).

### Readiness criteria

- `PaymentDomainClient` has explicit connect and request timeouts.
- A single retry is attempted on connection-level errors.
- A circuit breaker opens after 5 consecutive failures and closes after a cooldown.
- payment-domain 422/409/500 responses are mapped to distinct merchant-facing error shapes.
- Tests cover timeout, retry, and circuit-open scenarios using Http::fake().

### Related tasks

- TASK-045 — Rate limiting and error contract (defines the merchant-facing error shape)
- TASK-051 — Payment Aggregate (payment-domain idempotency on creation)
- EPIC-06 — Temporal orchestrator (long-term async alternative)
## Result

### Files created
- `app/Infrastructure/PaymentDomain/CircuitBreaker.php` — Cache-backed circuit breaker (threshold/cooldown configurable via `services.payment_domain.circuit_breaker.*`). Tracks consecutive failures; opens after N failures and resets after a successful call.
- `app/Infrastructure/PaymentDomain/Exceptions/PaymentDomainCircuitOpenException.php`
- `app/Infrastructure/PaymentDomain/Exceptions/PaymentDomainTimeoutException.php`
- `app/Infrastructure/PaymentDomain/Exceptions/PaymentDomainValidationException.php` — carries the raw upstream payload for transparent 422 forwarding.
- `app/Infrastructure/PaymentDomain/Exceptions/PaymentDomainConflictException.php`
- `app/Infrastructure/PaymentDomain/Exceptions/PaymentDomainUnavailableException.php`
- `tests/Feature/PaymentDomain/PaymentDomainResilienceTest.php` — 9 feature tests covering timeout, retry, circuit open/reset, and all error-code mappings.

### Files modified
- `app/Infrastructure/PaymentDomain/PaymentDomainClient.php` — Added `CircuitBreaker` injection and a private `send()` method with: connect/request timeouts, `retry(times: 2, when: ConnectionException, throw: false)`, circuit-breaker checks, and response-to-exception mapping.
- `app/Providers/AppServiceProvider.php` — Registered `CircuitBreaker` singleton and five `renderable()` handlers mapping domain exceptions to stable merchant-facing error shapes.
- `app/Interfaces/Http/Controllers/InitiateRefundController.php` — Removed the old manual `RequestException` catch block; error mapping is now handled globally.
- `config/services.php` — Added `connect_timeout`, `timeout`, and `circuit_breaker` config keys under `payment_domain`.
- `.env.example` — Added `PAYMENT_DOMAIN_CONNECT_TIMEOUT`, `PAYMENT_DOMAIN_TIMEOUT`, `PAYMENT_DOMAIN_CB_THRESHOLD`, `PAYMENT_DOMAIN_CB_COOLDOWN`.

### Design decisions
- **`retry(throw: false)`**: Critical — prevents Laravel's retry middleware from calling `$response->throw()` on non-2xx responses, which would mask 404/422/409/500 before we can inspect and map them.
- **`catch (ConnectionException)`**: The `retry()` helper re-throws `ConnectionException` after exhausted retries (it does not return `false`); catching it explicitly in `send()` converts it to `PaymentDomainTimeoutException`.
- **Exception rendering in `AppServiceProvider::boot()`** (not `bootstrap/app.php`): `bootstrap/` is not in the Docker volume mounts, so exception renderers must live in a service provider that is under `app/`.
- **Single `Http::fake()` closure in resilience tests**: Laravel's `Http::fake()` merges stubs rather than replacing them. Tests that switch between fail and success modes use a single closure with a mutable `$failMode` flag to avoid stale throwing stubs firing on later requests.
