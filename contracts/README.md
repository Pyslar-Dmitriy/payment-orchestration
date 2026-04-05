# contracts/

This directory is the single source of truth for all inter-service message schemas and API contracts in the payment orchestration platform.

```
contracts/
  openapi/          # OpenAPI 3.x specs (HTTP APIs)
  asyncapi/         # AsyncAPI specs (Kafka topics, RabbitMQ exchanges)
  json-schemas/
    events/         # JSON Schema (draft-07) for each Kafka event
    fixtures/       # Valid example payloads — validated in CI
```

Any change to a schema in this directory must be reviewed against the breaking vs. non-breaking criteria below before merging.

---

## Kafka topic naming convention

Topics follow the pattern `<domain>.<entity>.v<N>`:

| Topic | Description |
|---|---|
| `payments.lifecycle.v1` | Payment state transitions (initiated → authorized → captured) |
| `refunds.lifecycle.v1` | Refund state transitions |
| `ledger.entries.v1` | Double-entry postings from ledger-service |

When a breaking schema change is required, a new topic version is created: e.g., `payments.lifecycle.v2`. The old topic is **never renamed or deleted** — it continues to receive publishes during the co-existence window and is retained for replay.

---

## Message envelope

Every Kafka message must include the following top-level envelope fields:

| Field | Type | Required | Description |
|---|---|---|---|
| `schema_version` | string | yes | Monotonic integer encoded as a string (e.g., `"1"`). Consumers MUST skip and warn on unknown values. |
| `message_id` | UUID | yes | Globally unique. Used as the idempotency key (inbox/dedup pattern). |
| `correlation_id` | UUID | yes | Propagated across all services in a single business transaction. |
| `causation_id` | UUID | no | `message_id` of the upstream message that caused this one. Omitted at the start of a causal chain. |
| `source_service` | string | yes | Service that produced the message. |
| `occurred_at` | ISO-8601 | yes | When the event occurred (not when it was published). Must include timezone (`Z` or offset). |
| `event_type` | string | yes | Fully-qualified event type identifier (e.g., `payment.initiated`). |
| `payload` | object | yes | Event-specific data. Schema defined per event type. |

---

## Schema evolution policy

### Non-breaking changes — allowed on the existing topic

The following changes may be applied to an existing topic without creating a new version:

- Adding a new **optional** field with a default value.
- Adding a new enum value to a field that consumers skip on unknown values.
- Renaming a field in documentation only (the JSON field name is unchanged).

### Breaking changes — require a new topic version

The following changes are breaking and require a new topic (e.g., `.v2`):

- Removing a field that existing consumers read.
- Renaming a field in the JSON payload.
- Changing the type of an existing field (e.g., `string` → `integer`).
- Making a previously optional field required.
- Changing the semantic meaning of an existing field.

**When in doubt, treat the change as breaking.**

---

## 30-day co-existence policy

When a breaking change produces a new topic version:

1. The new topic (e.g., `payments.lifecycle.v2`) is created and producers begin publishing to it.
2. The old topic (`payments.lifecycle.v1`) **continues to receive publishes for at least 30 days** after the new topic goes live.
3. Consumers migrate to the new topic version incrementally during this window.
4. After all consumers have migrated and the 30-day window has elapsed, publishing to the old topic stops. The topic itself is retained for replay per the configured retention policy.

A co-existence plan must be documented in the PR that introduces the new topic version, naming the affected consumers and their migration timeline.

---

## Consumer requirements

- Consumers MUST handle unknown `schema_version` values gracefully: **skip the message and emit a warning log**. Do not crash or send the message to a DLQ solely because `schema_version` is unrecognised.
- Consumers MUST pin to a specific topic version (e.g., subscribe to `payments.lifecycle.v1`, not a wildcard).
- Consumers MUST deduplicate on `message_id` (inbox pattern) for any message that triggers a side effect.

---

## CI validation

The `contract-check` workflow (`.github/workflows/contract-check.yml`) runs on every PR that touches `contracts/`:

- **Spectral** lints all OpenAPI and AsyncAPI YAML specs.
- **ajv-cli** validates every fixture in `json-schemas/fixtures/` against its corresponding schema in `json-schemas/events/`. A missing fixture or a validation failure fails the build.

Every schema in `json-schemas/events/` requires a corresponding `*.valid.json` fixture in `json-schemas/fixtures/`. Add or update the fixture whenever the schema changes.