# TASK-033 — Define schema evolution and compatibility strategy for Kafka topics

## Context

ADR-012 defines the platform's strategy for Kafka schema evolution. This task ensures the strategy is embedded in the contracts, tooling, and documentation so it is consistently applied when schemas change.

## What to implement

### 1. Update `contracts/` schema files with `schema_version` envelope

All Kafka message schema files in `contracts/` must include the `schema_version` field in the required envelope fields alongside `message_id`, `correlation_id`, `causation_id`, `source_service`, `occurred_at`, and `event_type`.

Update TASK-031 schemas and TASK-032 JSON Schema validation to enforce this field.

### 2. Document the evolution policy in `contracts/README.md`

Create or update `contracts/README.md` to include:

- The full breaking vs. non-breaking change criteria from ADR-012.
- The topic versioning naming convention: `<domain>.<entity>.v<N>`.
- The 30-day co-existence policy: when a new topic version is created, the old topic continues receiving publishes for at least 30 days while consumers migrate.
- The requirement that consumers handle unknown `schema_version` values gracefully (skip + warn, not crash).

### 3. Add a schema evolution checklist to the PR template (optional for v1)

If a GitHub PR template exists, add a checklist item:
> - [ ] If Kafka schema changed, classified as breaking or non-breaking per ADR-012. If breaking, new topic version created and co-existence plan documented.

### 4. CI JSON Schema validation

Ensure TASK-032's JSON Schema validation covers the `schema_version` field as a required string. The CI pipeline should fail on any Kafka message that does not include this field.

## Done criteria

- All Kafka message contract schemas in `contracts/` include `schema_version` as a required field.
- `contracts/README.md` documents the breaking/non-breaking change criteria and the co-existence policy.
- JSON Schema validation (TASK-032) enforces `schema_version` presence.
- The topic naming convention (`.v1`, `.v2`) is documented and applied consistently.