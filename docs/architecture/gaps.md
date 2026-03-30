# Architecture Gaps

This document lists architectural decisions and design concerns that were identified during the initial architecture review but are not yet addressed by existing ADRs or tasks.

Each gap is classified by priority and cross-referenced against the task backlog.

---

## Priority levels

- **High** — Missing before implementation of the related epic begins. Risk of inconsistent or contradictory designs across services.
- **Medium** — Should be resolved before the first end-to-end flow is tested. Risk of surprising runtime behavior.
- **Low** — Can be deferred to a later epic or resolved during implementation, with a note in the relevant task.

---

## High priority gaps

### GAP-001 — Payment state machine is not formally specified

**Status: Resolved** — TASK-056 added with the full transition table, including `requires_reconciliation` (introduced by ADR-010).

---

### GAP-002 — No compensation/rollback strategy for partial workflow failures

**Status: Resolved** — ADR-010 defines the full compensation strategy. TASK-065 implements it in `PaymentWorkflow` and `RefundWorkflow`.

---

### GAP-003 — No ADR for merchant API authentication mechanism

**Status: Resolved** — ADR-009 documents API key authentication as the chosen mechanism. TASK-040 updated with implementation details.

---

## Medium priority gaps

### GAP-004 — No strategy for Kafka schema evolution and breaking changes

**Status: Resolved** — ADR-012 defines breaking vs. non-breaking change criteria, the `.v<N>` topic versioning strategy, the 30-day co-existence policy, envelope `schema_version` field, and the deferred schema registry decision. TASK-033 implements the policy tooling. TASK-031 updated to reference ADR-012.

---

### GAP-005 — Temporal signal delivery to a dead or completed workflow is unhandled

**Status: Resolved** — TASK-094 implements explicit dead-workflow signal handling (warn + publish `WebhookSignalUndeliverable` Kafka event, no silent discard, no automatic new workflow). TASK-092 updated with error differentiation table.

---

### GAP-006 — Webhook signal timeout path in Temporal is undefined

**Status: Resolved** — TASK-061 updated with the explicit timeout policy: 30-minute signal wait timeout triggers a `ProviderStatusQuery` activity before marking the payment `failed`. The timeout no longer goes directly to `failed`.

---

### GAP-007 — No ADR for provider routing strategy

**Status: Resolved** — ADR-011 documents the rule-based priority routing model, fallback-on-hard-failure behavior, manual `available` flag as v1 circuit break, and explicit deferral of automated circuit breaking. TASK-073 updated with implementation details.

---

## Low priority gaps

### GAP-008 — Synchronous merchant-api → payment-domain coupling is undocumented

**Status: Resolved** — Documented in `docs/architecture/scope.md` section 11 as an intentional simplicity choice for v1, with the future async intake option noted.

---

### GAP-009 — Multi-signal workflow design is not specified

**Status: Resolved** — TASK-061 updated with the full signal contract: two distinct signal types (`provider.authorization_result`, `provider.capture_result`), payload fields, sequential handling via signal queue, and deduplication by `provider_event_id`.

---

## Gaps confirmed as covered by existing tasks

The following gaps were initially noted but are adequately addressed by existing tasks:

| Concern | Covered by |
|---|---|
| Ledger model — single vs. double entry | TASK-100 explicitly designs double-entry with `ledger_accounts`, `ledger_transactions`, `ledger_entries`, debit/credit rules, and balance derivation from entries |
| Outbox publisher process type | TASK-054 (implement outbox publisher) will resolve this at implementation time |
| Correlation ID propagation across all transport channels | TASK-130 explicitly covers HTTP headers, RabbitMQ messages, Kafka events, and Temporal workflow/activity inputs |
| Kafka topic naming and versioning convention | TASK-031 establishes `.v1` suffixes and required message fields including `message_id` for dedup |