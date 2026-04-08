# TASK-045 — Add rate limiting and error contract

### Add a rate limit on merchant requests and a unified error response.

## Readiness Criteria
- rate limit configurable;
- errors have a stable structure;
- failure reasons are logged.

## Result

### Files created / modified

- `app/Providers/AppServiceProvider.php` — registers named rate limiter `'api'` in `boot()`: keyed by `sha1(bearerToken)`, falls back to IP for keyless requests; limit driven by `config('services.rate_limit.per_minute', 60)`
- `bootstrap/app.php` — added `ThrottleRequestsException` handler: returns `{'message': 'Too many requests.', 'retry_after': N}` 429 JSON response and logs a `warning` with `method`, `path`, `retry_after` context
- `routes/api.php` — added `'throttle:api'` to the authenticated route group (`['auth.api', 'throttle:api']`)
- `config/services.php` — added `rate_limit.per_minute` key driven by `RATE_LIMIT_PER_MINUTE` env var
- `.env.example` — added `RATE_LIMIT_PER_MINUTE=60`
- `tests/Feature/RateLimitTest.php` — 5 tests: requests within limit succeed, 429 on exceeded limit, 429 response shape (`message` + `retry_after`), per-key isolation (second API key unaffected), warning logged on violation
- `docs/merchant-api.postman_collection.json` — added `429 Rate limit exceeded` example response to Initiate Payment

### Design decisions
- **Rate limit key is `sha1(bearerToken)`, not `merchant_id`**: Laravel's `$middlewarePriority` places `ThrottleRequests` before custom auth middleware (which doesn't implement `AuthenticatesRequests`), so `$request->attributes->get('merchant')` is null when the limiter fires. Using the bearer token avoids the middleware ordering dependency and effectively limits per API key — a reasonable and common approach.
- **429 shape**: `{'message': 'Too many requests.', 'retry_after': N}` mirrors the stable `{'message': '...'}` contract used across all other error responses, with `retry_after` (integer seconds) added for client retry logic.
- **Logging**: Failure reason (rate limit hit) logged at `warning` level with `method`, `path`, and `retry_after` context. `correlation_id` is in the shared log context (set by `CorrelationIdMiddleware`, which is global and runs first). `merchant_id` is NOT present — because `ThrottleRequests` has higher middleware priority than custom `AuthenticateApiKey`, auth runs after throttle, so `merchant_id` is not yet set in the log context when the 429 fires.