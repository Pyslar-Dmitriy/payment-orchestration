# ADR-009 — Use API key authentication for merchant API access

**Status:** <span style="color:green">Accepted</span>

## Context

Merchants need to authenticate against the public API to create payments, query status, and request refunds. The choice of authentication mechanism affects:

* key rotation and revocation,
* replay protection,
* merchant onboarding complexity,
* audit trail,
* implementation overhead for v1.

Candidates evaluated:

* Static API keys (bearer tokens)
* HMAC-signed requests
* JWT with short expiry
* OAuth 2.0 client credentials

## Decision

Use **API key authentication** with the following model:

* Each merchant is issued a **secret API key** at onboarding, stored as a salted hash (bcrypt or Argon2) in the database.
* Merchants include the key as a `Bearer` token in the `Authorization` header on every request.
* All communication happens over **TLS** — the API is never exposed over plain HTTP.
* Keys can be **rotated**: a new key is issued, the old key is invalidated after a configurable grace period.
* Keys are scoped to a merchant and carry the merchant context, so every authenticated request is bound to a `merchant_id` without a separate lookup.
* Key rotation and revocation are synchronous operations on the `merchant-api`'s database.

A `key_id` prefix is included in the key value (e.g., `pk_live_<random>`) to make keys identifiable in logs without exposing the secret itself. The prefix is never the credential — it is only for identification.

### What is explicitly deferred for v1

* Per-key capability scopes (read-only vs. write-enabled keys) — single scope in v1.
* IP allowlisting — considered a future hardening concern.
* Short-lived JWT or OAuth flows — deferred until a merchant portal or delegation model is needed.
* HMAC request signing — deferred; adds friction to merchant SDK without a concrete replay attack risk to mitigate at this stage.

## Alternatives considered

### Alternative A — HMAC-signed requests

Each request is signed with a shared secret using HMAC-SHA256 over the request body and timestamp.

Pros:
* Provides request integrity and replay protection (timestamp window).
* Secret never travels over the wire.

Cons:
* Significantly higher merchant SDK complexity.
* Timestamp skew requires clock synchronization between merchant and platform.
* Adds implementation overhead on both sides without a concrete threat to mitigate in v1.
* Still requires the shared secret to be stored and rotated.

### Alternative B — JWT with short expiry

Merchants authenticate with a credential, receive a short-lived JWT, and include it in subsequent requests.

Pros:
* Stateless verification on the API.
* Token expiry limits the blast radius of a leaked token.

Cons:
* Requires a token refresh flow, adding SDK complexity.
* Adds an extra auth endpoint and flow to implement.
* For machine-to-machine server-side integrations (the primary use case), there is no user session that benefits from short expiry — the secret key to obtain the JWT is just as sensitive as the API key itself.

### Alternative C — OAuth 2.0 client credentials

Merchants are issued a `client_id` and `client_secret`, exchange them for an access token via the `/oauth/token` endpoint.

Pros:
* Industry standard for machine-to-machine authorization.
* Well-supported by tooling.
* Naturally supports scopes.

Cons:
* Highest implementation complexity of all options.
* Requires a full OAuth server or library integration.
* No meaningful security advantage over API keys for a server-side integration where the secret is already securely stored.
* Premature for a v1 with a single merchant scope.

## Consequences

Positive:
* Minimal friction for merchant integration — one `Authorization: Bearer <key>` header per request.
* Simple implementation: hashed key lookup, middleware binding to merchant context.
* Straightforward key rotation and revocation model.
* No dependency on a token server or external auth provider.
* TASK-040 can implement this without a separate auth service.

Negative:
* The key has long lifetime by default — revocation must be immediate and reliable when a key is compromised.
* No request integrity protection at the application layer (relies on TLS).
* Extending to per-key scopes or delegation will require a schema migration and middleware change later.

Operational note:
The salted hash model means the full plaintext key is shown to the merchant exactly once at issuance. If lost, a new key must be generated. This is the same model used by Stripe, GitHub, and most payment API providers.