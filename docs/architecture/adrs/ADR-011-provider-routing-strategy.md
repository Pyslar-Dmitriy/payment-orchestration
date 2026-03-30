# ADR-011 — Provider routing strategy and circuit breaker scope

**Status:** <span style="color:green">Accepted</span>

## Context

The platform routes payment requests to external PSPs through a `provider-gateway` abstraction. Multiple providers may be configured, and the platform must select the right one for a given payment. The routing decision must be deterministic, extensible, and resilient to provider unavailability.

The routing model needs to answer:

* How is a provider selected for a given payment?
* What happens if the selected provider is unavailable?
* Is circuit breaking in scope for v1?
* Where does routing logic live?

## Decision

Use a **rule-based priority routing model** implemented in `provider-gateway`.

### Routing rules

Provider selection is evaluated against an ordered set of rules. Each provider in the configuration has:

* **`currencies`** — list of supported ISO 4217 currency codes.
* **`countries`** — list of supported ISO 3166-1 alpha-2 merchant country codes.
* **`merchant_types`** — optional list of merchant categories this provider supports.
* **`priority`** — integer; lower value = higher preference. Used to sort candidates after filtering.
* **`available`** — boolean flag; `false` removes the provider from consideration. Managed manually or via a future admin API.

Routing algorithm:

1. Filter all configured providers by `currencies`, `countries`, and `merchant_types`.
2. Remove providers where `available = false`.
3. Sort remaining candidates by `priority` ascending.
4. Select the first candidate. If no candidate matches, reject the payment with a `no_provider_available` error before the workflow starts.

### Fallback behavior

If the selected provider returns a hard failure (non-retriable error, e.g., account suspended, currency not supported at runtime), the workflow retries the routing activity with the failed provider **excluded**. The next eligible provider in priority order becomes the fallback.

This allows one transparent retry against a fallback provider without changing the `payment_id` or the merchant-visible idempotency key.

If no fallback provider is available after exclusion, the workflow proceeds to the Class A failure path defined in ADR-010.

### Circuit breaking — deferred to post-v1

Automated circuit breaking (detecting consecutive failures and opening a circuit to stop routing to a provider) is **not implemented in v1**.

Rationale:
* The provider `available` flag provides a manual circuit break mechanism that operators can use immediately.
* Implementing circuit breaking correctly requires a shared state store (or per-worker in-memory state) and tuning around failure thresholds, half-open probing, and recovery windows — non-trivial to implement safely.
* The mock provider environment makes it difficult to test a real circuit breaker in v1.
* The fallback-on-hard-failure behavior provides a partial automated response for single-request failures without full circuit breaking.

Circuit breaking is an explicit post-v1 item. When implemented, it should be added as a configurable wrapper around the routing selection step, not embedded in routing rules.

## Alternatives considered

### Alternative A — Simple static single-provider configuration

Configure exactly one provider per environment. No routing logic.

Pros:
* Zero complexity in v1.

Cons:
* Does not reflect realistic payment infrastructure.
* No fallback story.
* Routing is a stated architectural goal (TASK-073, scope.md section 12).

**Rejected.** Multi-provider routing is an explicit design goal of this platform.

### Alternative B — Routing via an external rules engine or database table

Store routing rules in a database and evaluate them at runtime.

Pros:
* Dynamic rule updates without code deployment.
* Richer rule logic.

Cons:
* Adds a runtime database read on every payment routing decision.
* Increases coupling between routing and the database.
* Overkill for v1 rule complexity (currency + country + priority).

**Deferred.** Database-backed routing rules are a natural v2 extension when dynamic rule management is needed.

### Alternative C — Routing decisions made in Temporal workflow directly

The `PaymentWorkflow` queries provider availability and selects a provider itself.

Pros:
* All routing logic is co-located with the workflow.

Cons:
* Temporal workflow code must be deterministic; network/database calls in routing introduce non-determinism risk if not wrapped in activities.
* Mixes orchestration logic with routing policy.
* Harder to test routing in isolation.

**Rejected.** Routing is an activity in the workflow, not inline workflow logic.

## Consequences

Positive:
* Extensible routing without architectural changes — new providers are added via configuration.
* Fallback-on-hard-failure provides resilience without circuit breaker complexity.
* The `available` flag gives operators a manual kill switch per provider.
* Routing logic is isolated in `provider-gateway` and testable independently.

Negative:
* No automated detection of degraded providers — relies on monitoring and manual `available` flag updates until circuit breaking is added.
* Priority-based routing does not support load-balancing across providers of equal priority in v1 (deterministic selection, not weighted random).

Operational note:
The `available` flag must be changeable at runtime without a deployment. This requires either a configuration hot-reload mechanism or a minimal admin endpoint in `provider-gateway`. The implementation detail is left to TASK-073, but the requirement is recorded here.