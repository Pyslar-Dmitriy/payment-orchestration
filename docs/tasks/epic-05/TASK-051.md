### TASK-051 — Implement the Payment aggregate and state transitions

### Define and implement the allowed payment status transitions.

#### Minimum statuses
- `created`
- `pending_provider`
- `requires_action`
- `authorized`
- `captured`
- `failed`
- `cancelled`
- `refunding`
- `refunded`
- `requires_reconciliation` *(exceptional state — see ADR-010)*

### Optimistic locking
The Payment aggregate must include a `version` integer field incremented on every state transition. This is the mechanism used for optimistic locking (see TASK-055). The aggregate must reject a transition if the version at write time does not match the version at read time, returning a concurrency conflict error.

### Important
Invalid transitions must fail with a domain error. The full transition table is defined in TASK-056.

### Done criteria
- transition rules are centralized;
- they can be tested independently;
- no controller updates payment status directly;
- `version` field is present and incremented on every transition;
- concurrency conflicts are surfaced as explicit domain errors, not silent overwrites.