# ADR-012 — Kafka schema evolution and breaking change strategy

**Status:** <span style="color:green">Accepted</span>

## Context

Replay is an explicit design goal of the Kafka event stream (ADR-003, TASK-122). Consumers must be able to replay events published weeks or months ago. If the message schema changes between the time an event was published and the time it is consumed during replay, the consumer must still be able to parse the message.

TASK-031 establishes `.v1` topic suffixes, signaling schema versioning awareness. This ADR defines:

* what constitutes a breaking vs. non-breaking schema change,
* how breaking changes are handled (new topic vs. in-place evolution),
* whether a schema registry is used in v1,
* the co-existence policy for old and new topic versions.

## Decision

### Breaking vs. non-breaking changes

**Non-breaking (additive) changes** — allowed on the existing topic without a new version:

* Adding a new **optional** field with a default value.
* Adding a new enum value to a field that consumers skip on unknown values.
* Renaming a field in documentation only (field name in JSON remains unchanged).

**Breaking changes** — require a new topic version:

* Removing a field that existing consumers read.
* Renaming a field in the JSON payload.
* Changing the type of an existing field (e.g., `string` → `int`).
* Making a previously optional field required.
* Changing the semantic meaning of an existing field.

When in doubt, treat the change as breaking.

### Schema registry — deferred for v1

A Confluent Schema Registry or equivalent is **not used in v1**.

Rationale:
* Adding a schema registry introduces an extra infrastructure dependency and operational overhead before any business value is delivered.
* The event volume and consumer count in v1 is small enough that manual schema governance is manageable.
* JSON Schema validation at the producer and consumer (TASK-032) provides a first line of defense against schema drift.

Schema registry is an explicit post-v1 item. When consumer count or event volume grows to a point where decentralized schema governance becomes a liability, the registry should be introduced.

### Versioning strategy

* Topics follow the naming pattern `<domain>.<entity>.<version>`, e.g., `payments.lifecycle.v1`.
* When a breaking change is needed, a new topic is created: `payments.lifecycle.v2`.
* The old topic (`payments.lifecycle.v1`) continues to be published to during a **co-existence period of at least 30 days** after the new topic goes live.
* Consumers migrate to the new topic version incrementally during the co-existence window.
* After the co-existence period ends and all consumers have migrated, publishing to the old topic stops. The topic itself is retained for replay purposes for as long as the retention policy allows.

### Envelope versioning

All Kafka messages must include a `schema_version` field in the payload envelope:

```json
{
  "schema_version": "1",
  "message_id": "...",
  "correlation_id": "...",
  "causation_id": "...",
  "source_service": "...",
  "occurred_at": "...",
  "event_type": "...",
  "payload": { ... }
}
```

* Consumers check `schema_version` before processing. If the version is unknown, the consumer skips the message and emits a warning log.
* This allows a consumer reading a mixed stream (during a co-existence window) to safely handle both versions if the version field is present and parseable.

### Producer responsibility

Producers are responsible for:

* Publishing only schema-valid messages (validated against the registered JSON Schema in `contracts/`).
* Incrementing `schema_version` when a new topic version is introduced.
* Continuing to publish to the old topic version during the co-existence window.

### Consumer responsibility

Consumers are responsible for:

* Explicitly handling unknown `schema_version` values gracefully (skip + warn, not crash).
* Consuming from a pinned topic version.
* Migrating to a new topic version during the co-existence window on a planned basis.

## Alternatives considered

### Alternative A — Use a schema registry (Confluent or Karapace) from day one

Pros:
* Formal schema contract between producers and consumers.
* Automatic compatibility enforcement on publish.
* Single source of truth for all schema versions.

Cons:
* Additional infrastructure component to run, monitor, and back up.
* Adds latency to every produce call for schema lookup.
* Operational burden before a single consumer is in production.
* For a learning platform, the registry adds complexity without proportionate value in v1.

**Deferred.** Explicitly post-v1.

### Alternative B — In-place evolution with no versioning (just add fields, never remove)

Pros:
* Simplest possible model.
* No new topics ever.

Cons:
* Consumers can never rely on a stable schema.
* Breaking changes are impossible to handle cleanly — the team must never remove or rename a field.
* Over time, message payloads accumulate deprecated fields with no mechanism for cleanup.
* Replay of very old messages becomes harder as semantic meaning of fields shifts.

**Rejected.** The prohibition on breaking changes is operationally unrealistic over a project lifetime.

### Alternative C — Envelope-only versioning without topic versioning

Use a single topic forever; route consumers by `schema_version` field in the envelope.

Pros:
* No need to manage multiple topics.

Cons:
* A consumer replaying from offset 0 must handle all historical schema versions.
* Increases consumer logic complexity.
* Harder to set different retention policies for old vs. new schema messages.

**Rejected** as the sole strategy. Topic versioning is retained as the primary breaking-change mechanism; envelope versioning complements it for co-existence window handling.

## Consequences

Positive:
* Clear definition of what requires a new topic version eliminates ambiguity on the team.
* 30-day co-existence window gives consumers adequate migration time.
* Envelope `schema_version` field allows safe mixed-version consumption during migration.
* Deferred schema registry keeps v1 infrastructure lean.

Negative:
* Without a registry, schema compatibility is enforced by convention and CI JSON Schema validation, not by a runtime gate. A misconfigured producer could publish an invalid message.
* The team must actively monitor which consumers have migrated during a co-existence window — there is no automated tracking.
* Adding the registry later will require retrofitting schema registration into existing producers.

Operational note:
The `contracts/` directory in the monorepo is the single source of truth for message schemas in v1. Any change to a published schema must be reviewed against the breaking vs. non-breaking criteria in this ADR before merging.