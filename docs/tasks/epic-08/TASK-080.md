### TASK-080 — Implement the webhook intake endpoint

#### What to do
Add an HTTP endpoint:
- `POST /webhooks/{provider}`

#### Logic
- resolve provider route;
- basic payload validation;
- signature verification;
- store raw event;
- deduplicate;
- publish a task to RabbitMQ;
- return `200` quickly.

#### Done criteria
- the endpoint does not execute heavy domain logic;
- it responds quickly;
- it stores raw payload before any downstream processing.

## Result

### Files created
- `config/webhooks.php` — provider registry; each entry configures `signing_secret`, `signature_header`, and `event_id_header`. Adding a new provider requires only a new key here.
- `app/Domain/Webhook/SignatureVerifier.php` — pure HMAC-SHA256 verifier.
- `app/Infrastructure/Persistence/RawWebhook.php` — Eloquent model for the `raw_webhooks` table.
- `app/Infrastructure/Queue/PublishRawWebhookJob.php` — placeholder queue job (logs only); TASK-083 will replace the `handle()` body with AMQP publish to `provider.webhook.raw`.
- `app/Application/Exceptions/InvalidWebhookSignatureException.php`
- `app/Application/Exceptions/MissingEventIdException.php`
- `app/Application/IngestWebhook.php` — use case: validates event-ID header, verifies HMAC if a secret is configured, stores raw payload via `insertOrIgnore` (PostgreSQL `ON CONFLICT DO NOTHING`), dispatches job.
- `app/Interfaces/Http/Controllers/WebhookIntakeController.php` — thin controller; maps exceptions to 401/422, returns `{"status":"received"}` on success.
- `tests/Feature/WebhookIntakeTest.php` — 11 feature tests covering happy path, signature flows, deduplication, and all error cases.
- `docs/webhook-ingest.postman_collection.json` — Postman collection with all request/response examples.

### Files modified
- `routes/api.php` — added `POST /webhooks/{provider}`.
- `.env.example` — added `WEBHOOK_SIGNING_SECRET_MOCK`.
- `tests/Feature/ExampleTest.php` — fixed scaffold test to hit `/api/health` (the service has no web routes).

### Design decisions
- **`insertOrIgnore` instead of catching `UniqueConstraintViolationException`**: PostgreSQL marks the entire connection transaction as aborted after a constraint violation, even when the exception is caught in application code. `ON CONFLICT DO NOTHING` avoids raising the exception entirely, which is safe under the outer transaction that `RefreshDatabase` wraps tests in.
- **RabbitMQ publish stubbed via Laravel queue job**: TASK-083 will fill in the actual AMQP client. The stub keeps the endpoint's flow complete and the job's `handle()` contains a comment pointing to TASK-083.
- **Signature verification is opt-in per provider**: an empty `signing_secret` disables signature checking, which allows the `mock` provider to be tested without secrets in dev.