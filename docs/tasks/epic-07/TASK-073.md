### TASK-073 — Implement provider routing strategy

**Routing model: rule-based priority routing — see ADR-011.**

#### What to do
Implement the provider routing activity in `provider-gateway` based on the strategy defined in ADR-011.

Each configured provider has:
- `currencies` — list of supported ISO 4217 currency codes;
- `countries` — list of supported ISO 3166-1 alpha-2 merchant country codes;
- `merchant_types` — optional list of supported merchant categories;
- `priority` — integer, lower = higher preference;
- `available` — boolean, `false` removes the provider from all routing decisions.

#### Routing algorithm
1. Filter providers by `currencies`, `countries`, and `merchant_types`.
2. Remove providers where `available = false`.
3. Sort remaining candidates by `priority` ascending.
4. Return the first candidate. If none match, return a `NoProviderAvailable` error (payment is rejected before the workflow starts).

#### Fallback behavior
If the selected provider returns a hard (non-retriable) error, the routing activity is called again with the failed provider excluded. The next eligible provider in priority order is used. If no fallback exists, the workflow proceeds to the appropriate failure path (see ADR-010).

#### `available` flag — runtime toggling
The `available` flag must be changeable at runtime without a deployment. Implement one of:
- A config hot-reload mechanism (file-based or environment variable polling), or
- A minimal internal admin endpoint `PATCH /providers/{id}/availability` accessible only from internal network.

#### Circuit breaking
Automated circuit breaking is **not in scope for v1**. The `available` flag is the manual circuit break mechanism. Circuit breaking is deferred to post-v1 (noted in ADR-011).

#### Done criteria
- the routing algorithm correctly filters and orders providers;
- `available = false` removes a provider from routing without a deployment;
- a fallback provider is selected when the primary returns a hard error;
- `NoProviderAvailable` is returned (not a runtime exception) when no provider matches;
- the routing strategy is unit-testable in isolation.