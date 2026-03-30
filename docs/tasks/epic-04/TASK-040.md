# TASK-040 — Implement Authentication and Merchant Context

### Add a merchant authentication mechanism.

**Authentication mechanism: API key authentication — see ADR-009.**

The chosen mechanism is static API key authentication (Bearer token). Key details:
- Secret API key issued at merchant onboarding, stored as a salted hash (bcrypt/Argon2).
- Merchants send `Authorization: Bearer <key>` on every request over TLS.
- Key format: `pk_live_<32-char-random-hex>` — e.g. `pk_live_a3f7c2...` (see ADR-009 for full format spec). The prefix is not a credential and is safe to appear in logs; the random suffix must never be logged.
- Keys are rotatable: issuing a new key invalidates the old one after a configurable grace period.
- A single key scope for v1 — per-key capabilities and IP allowlisting are deferred.

### Requires implementation of:
- merchant credentials table with salted-hash storage (never store plaintext key);
- key issuance flow (generate, hash and store, return plaintext once to merchant);
- auth middleware that validates the Bearer token and rejects unauthorized requests;
- request binding to `merchant_id` — every authenticated request carries merchant context;
- key rotation endpoint (generate new key, invalidate old);
- a basic role model for the API (single scope in v1).

## Readiness Criteria
- each request knows the `merchant_id`;
- unauthorized requests are rejected with `401`;
- audit contains the merchant context;
- plaintext key is never persisted or logged;
- key rotation does not require a deployment.