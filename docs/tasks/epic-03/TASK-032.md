# TASK-032 — Implement JSON Schema Validation for Events

### Create a JSON Schema for core domain events and add validation to CI.

### Examples:
- payment-created.v1.json
- payment-authorized.v1.json
- payment-captured.v1.json
- refund-requested.v1.json
- ledger-entry-posted.v1.json

## Readiness Criteria
- The event cannot be published in an unknown format;
- CI validates schemas;
- Contracts can be versioned without breaking consumers.

## Result

5 JSON Schema files (`contracts/json-schemas/events/`):
- `payment-created.v1.json` — envelope + payment.initiated payload, status: const "initiated"
- `payment-authorized.v1.json` — envelope + payment.authorized payload, status: const "authorized"
- `payment-captured.v1.json` — envelope + payment.captured payload, status: const "captured"
- `refund-requested.v1.json` — envelope + refund.requested payload with refund_id, status: const "requested"
- `ledger-entry-posted.v1.json` — envelope + double-entry lines structure with direction enum

All schemas use draft-07, additionalProperties: false, and const on event_type / status — so an unknown format is rejected by the validator, satisfying the first readiness criterion.

5 valid fixture files (`contracts/json-schemas/fixtures/`) for CI validation — the fixture chain tells a coherent payment lifecycle story (same correlation_id, each causation_id pointing to the previous message_id).

CI changes (`.github/workflows/contract-check.yml`):
- Added validate-json-schemas job: installs ajv-cli + ajv-formats, iterates *.v1.json schemas, validates each matching fixture, exits non-zero on any failure or missing fixture
- Fixed the Spectral glob from *.{yaml,yml,json} → *.{yaml,yml} so the new JSON Schema files aren't accidentally linted as OpenAPI specs

Versioning: the *.v1.json naming convention + schema_version: enum in each schema means a breaking change requires a new *.v2.json file (and a new enum value), leaving consumers of v1 unaffected.
