# TASK-056 — Define the payment state machine transition table

## Context

TASK-051 implements the Payment aggregate and enforces that invalid transitions fail. However, the full transition matrix — which status can transition to which, under what event, and what is forbidden — is not yet formally specified. Without it, different implementation sessions may produce different allowed transitions, making the state machine inconsistent across tests, the aggregate, and the workflow.

This task fills that gap by producing the canonical transition table as a design artifact before or alongside TASK-051 implementation.

See also: ADR-010 (introduces `requires_reconciliation` status).

## Transition table

| From status | Allowed transition to | Triggering event / command |
|---|---|---|
| `created` | `pending_provider` | Payment workflow started; provider request submitted |
| `pending_provider` | `authorized` | Provider webhook: authorization confirmed |
| `pending_provider` | `captured` | Provider webhook: direct capture confirmed (single-step flow) |
| `pending_provider` | `requires_action` | Provider webhook: 3DS / additional merchant action required |
| `pending_provider` | `failed` | Provider webhook: hard decline; or workflow timeout with no side effect |
| `requires_action` | `authorized` | Provider webhook: action completed, authorization confirmed |
| `requires_action` | `captured` | Provider webhook: action completed, direct capture confirmed |
| `requires_action` | `failed` | Provider webhook: action failed or timed out |
| `authorized` | `captured` | Capture command issued and provider capture confirmed |
| `authorized` | `cancelled` | Merchant cancel command; provider void confirmed |
| `authorized` | `failed` | Capture activity hard-failed after all retries |
| `captured` | `refunding` | Merchant refund request accepted; refund workflow started |
| `captured` | `requires_reconciliation` | Downstream step (e.g. ledger post) permanently failed after capture (see ADR-010) |
| `refunding` | `refunded` | Provider webhook: refund confirmed |
| `refunding` | `captured` | Provider webhook: refund rejected; payment returns to captured state |
| `refunding` | `requires_reconciliation` | Downstream step (e.g. ledger reversal) permanently failed after refund confirmed (see ADR-010) |
| `failed` | _(terminal — no transitions)_ | — |
| `cancelled` | _(terminal — no transitions)_ | — |
| `refunded` | _(terminal — no transitions)_ | — |
| `requires_reconciliation` | `captured` | Manual reconciliation: capture confirmed, ledger now consistent |
| `requires_reconciliation` | `refunded` | Manual reconciliation: refund confirmed, ledger now consistent |

### Notes

* All transitions not listed above are **forbidden**. The aggregate must reject them with a domain error.
* `requires_reconciliation` is an exceptional status — it is never a normal transition target during healthy operation (see ADR-010).
* Transitions out of `requires_reconciliation` are operator-initiated only and must be auditable. They are executed via a dedicated command, not the standard webhook/workflow path.
* Status history must be preserved for every transition: each change creates a `PaymentStatusHistory` record including the previous status, new status, timestamp, and triggering event type.

## Done criteria

- The transition table above is implemented in the Payment aggregate.
- The aggregate rejects all transitions not listed in the table with a domain error.
- The table is covered by unit tests: at minimum one test per valid transition and one per forbidden transition.
- No controller or workflow updates payment status directly — only the aggregate's transition method does.
- Status history is recorded for every transition.

## Result

Implemented as part of TASK-051. The transition table is encoded in `app/Domain/Payment/PaymentStateMachine::TRANSITIONS` as a private constant. All 18 valid transitions and 14 representative forbidden transitions are covered by `PaymentStateMachineTest` using PHPUnit 12 `#[DataProvider]` attributes.