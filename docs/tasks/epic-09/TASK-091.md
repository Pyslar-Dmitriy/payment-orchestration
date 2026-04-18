### TASK-091 — Implement mapping from external statuses to internal statuses

#### What to do
Create a mapping layer:
- provider payload → internal event DTO

#### Examples
- provider `approved` → internal `authorized`
- provider `settled` → internal `captured`
- provider `declined` → internal `failed`

#### Done criteria
- mapping is centralized;
- it can be changed without rewriting the workflow;
- it is covered by tests for different provider payloads.

#### Notes from TASK-090 review
- `ConsumeRawWebhookCommand` logs the raw `message->body` on malformed-message discard. Once real provider payloads flow through, this could expose embedded tokens or sensitive references. Restrict the log to safe fields (e.g. `message_id`, body length) before shipping normalization.
- The inbox insert and the normalization side-effects are not wrapped in a single DB transaction. Wrap them when the real payload-loading logic lands here so a crash between steps cannot leave the inbox row inserted without the downstream work being done.

## Result

**Files created:**
- `app/Domain/Normalizer/NormalizedWebhookEvent.php` — DTO carrying `provider`, `providerEventId`, `providerReference`, `eventType`, `internalStatus`, `rawStatus`, and `rawPayload`
- `app/Domain/Normalizer/ProviderNormalizerInterface.php` — contract that each provider normalizer must implement (`provider(): string`, `normalize(array): NormalizedWebhookEvent`)
- `app/Domain/Normalizer/UnmappableWebhookException.php` — thrown when a payload is missing required fields or the status string is not recognised
- `app/Domain/Normalizer/ProviderNormalizerRegistry.php` — central dispatcher; maps provider key → normalizer; adding a new provider requires only registering a new `ProviderNormalizerInterface` implementation — no workflow code changes
- `app/Infrastructure/Normalizer/MockProviderNormalizer.php` — maps the mock PSP payload (`event_id`, `payment_reference`, `event_type`, `status`) to `NormalizedWebhookEvent`; status map: `AUTHORIZED→authorized`, `CAPTURED→captured`, `FAILED→failed`, `REFUNDED→refunded`, `PENDING→pending`; case-insensitive input
- `tests/Unit/Infrastructure/Normalizer/MockProviderNormalizerTest.php` — 18 unit tests covering all statuses, case-insensitive input, DTO field propagation, missing-field and unknown-status error cases
- `tests/Unit/Domain/Normalizer/ProviderNormalizerRegistryTest.php` — 4 unit tests covering dispatch, unknown-provider error, empty registry, and multi-provider routing

**Files modified:**
- `app/Application/ProcessRawWebhook.php` — now accepts `ProviderNormalizerRegistry` via constructor injection; calls `tryNormalize()` after inbox dedup check; inbox insert wrapped in a `DB::transaction()`; `$normalizedEvent` is passed forward (ready for TASK-092/093 signal/publish calls)
- `app/Providers/AppServiceProvider.php` — registers `ProviderNormalizerRegistry` as a singleton with `MockProviderNormalizer` wired in
- `app/Interfaces/Console/ConsumeRawWebhookCommand.php` — fixed security note from TASK-090: replaced `'body' => $message->body` with `'body_length' => strlen($message->body)` in the malformed-message discard log

**Design decisions:**
- Normalization is split between domain (`ProviderNormalizerInterface`, `ProviderNormalizerRegistry`, `NormalizedWebhookEvent`) and infrastructure (`MockProviderNormalizer`). Adding future providers (Stripe, Adyen) requires only a new infrastructure class + registration in `AppServiceProvider` — no domain or workflow changes.
- The RabbitMQ message from webhook-ingest currently contains metadata (`provider`, `event_id`) but not the raw provider body. `tryNormalize()` therefore operates on the queue-message fields; a warning is logged when normalization fails. TASK-092 will fetch the full raw body via webhook-ingest's internal HTTP API and pass it through the registry for a fully mapped `NormalizedWebhookEvent`.
- `UnmappableWebhookException` is treated as a non-fatal warning in `ProcessRawWebhook` (logged, inbox row still inserted) since failing the message entirely would send it to DLQ without the ability to recover if the schema simply had an unrecognised status.