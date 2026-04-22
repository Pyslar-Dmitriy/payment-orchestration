### TASK-112 — Implement delivery worker with retry/backoff/DLQ

#### Queues
- `merchant.callback.dispatch`
- `merchant.callback.retry.5s`
- `merchant.callback.retry.30s`
- `merchant.callback.retry.5m`
- `merchant.callback.dlq`

#### Logic
- send HTTP callback;
- sign the request;
- retry only temporary errors;
- permanent failure -> DLQ.

#### Done criteria
- callback delivery is reproducible and observable;
- retries are bounded;
- DLQ can be analyzed and replayed manually.

## Result

**Files created:**
- `app/Application/DeliverCallback/DeliverCallbackCommand.php` — DTO parsed from the RabbitMQ dispatch message
- `app/Application/DeliverCallback/DeliverCallbackHandler.php` — core delivery logic: inbox dedup → HTTP send → record attempt → route to retry/DLQ
- `app/Infrastructure/Http/HttpAttemptResult.php` — value object carrying HTTP outcome, status, headers, failure reason, and `isPermanentFailure` flag
- `app/Infrastructure/Http/HttpCallbackSenderInterface.php` — HTTP sender interface
- `app/Infrastructure/Http/GuzzleHttpCallbackSender.php` — Laravel HTTP client implementation; classifies 4xx (non-429) and TLS errors as permanent, 5xx/429/timeout/connection errors as temporary
- `app/Infrastructure/Http/FakeHttpCallbackSender.php` — configurable test double
- `app/Infrastructure/RabbitMq/CallbackRetryRouterInterface.php` — retry/DLQ routing interface
- `app/Infrastructure/RabbitMq/RabbitMqCallbackRetryRouter.php` — publishes to TTL+DLX retry queues (5s/30s/5m) and DLQ; retry queues use `x-message-ttl` + `x-dead-letter-routing-key` so RabbitMQ automatically re-routes expired messages back to the dispatch queue
- `app/Infrastructure/RabbitMq/FakeCallbackRetryRouter.php` — in-memory test double
- `app/Interfaces/Console/ConsumeCallbackWorkerCommand.php` — `php artisan callback:work`; connects to RabbitMQ, declares dispatch queue, sets prefetch=1, processes messages one at a time; ACKs on success, NACKs without requeue on malformed JSON, NACKs with requeue on unexpected errors
- `tests/Feature/DeliverCallbackHandlerTest.php` — 17 tests covering: success, temporary failure retry, max-attempts→DLQ, permanent failure→DLQ, attempt recording, inbox idempotency, retry message structure

**Files modified:**
- `config/rabbitmq.php` — added `retry_5s`, `retry_30s`, `retry_5m`, `dlq` queue name config
- `app/Providers/AppServiceProvider.php` — registered `HttpCallbackSenderInterface`, `CallbackRetryRouterInterface`, and the console command

**Retry schedule:**
- Attempt 1 fails → `merchant.callback.retry.5s` (5 s TTL, re-routes to dispatch)
- Attempt 2 fails → `merchant.callback.retry.5s`
- Attempt 3 fails → `merchant.callback.retry.30s` (30 s TTL)
- Attempt 4+ fails → `merchant.callback.retry.5m` (5 min TTL)
- Permanent failure or max attempts (default 5) → `merchant.callback.dlq` (no TTL, for manual replay)