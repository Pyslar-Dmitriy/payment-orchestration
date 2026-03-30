# TASK-030 — Describe OpenAPI for Merchant API

### Prepare an OpenAPI spec for:
- `POST /payments`
- `GET /payments/{id}`
- `POST /refunds`
- `GET /refunds/{id}`

### Describe
- request/response payloads;
- error format;
- idempotency header;
- auth;
- correlation ID headers.

## Artifacts
- `contracts/openapi/merchant-api.yaml`

## Readiness Criteria
- The spec is valid;
- it can be used as a contract between the client and the service;
- versioning is provided.