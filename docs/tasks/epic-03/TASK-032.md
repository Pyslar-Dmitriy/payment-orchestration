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