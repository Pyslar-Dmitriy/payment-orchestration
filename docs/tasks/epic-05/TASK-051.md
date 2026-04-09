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

## Result

### PaymentStatus enum updated
- Replaced `INITIATED → CREATED`, `AUTHORIZING → PENDING_PROVIDER`
- Removed `CAPTURING` (no longer a distinct state in the transition table)
- Added `REQUIRES_ACTION`
- Old values `'initiated'`, `'authorizing'`, `'capturing'` are gone; all call sites updated

### New domain classes created
- `app/Domain/Payment/PaymentStateMachine.php` — canonical transition table as a static map; used by `Payment::transition()` as the single source of truth
- `app/Domain/Payment/Exceptions/InvalidPaymentTransitionException.php` — thrown on any forbidden transition
- `app/Domain/Payment/Exceptions/PaymentConcurrencyException.php` — thrown when version at write time doesn't match expected version

### Payment aggregate updated (`app/Domain/Payment/Payment.php`)
- Added `transition(PaymentStatus $to, string $correlationId, ?string $reason, ?string $failureCode, ?string $failureReason): void`
- Validates the transition via `PaymentStateMachine::isAllowed()`
- Performs an atomic `UPDATE … WHERE version = ?` and throws `PaymentConcurrencyException` if 0 rows are affected
- Increments `version` and updates failure fields (set to `null` on non-failure transitions)
- Creates a `PaymentStatusHistory` record in the same call — callers must wrap in a DB transaction

### Application layer updated
- `InitiatePayment` — uses `PaymentStatus::CREATED` for the initial status

### Tests updated
- All existing tests referencing `'initiated'`/`'authorizing'` string values updated to `'created'`/`'pending_provider'`
- `PaymentStatusTest` updated to assert new cases
- New: `PaymentStateMachineTest` (unit) — 18 valid transitions + 14 forbidden transitions via `#[DataProvider]`
- New: `PaymentTransitionTest` (feature) — happy path, invalid transition, version increment, failure field storage, concurrency conflict

### Tests
105 tests pass (243 assertions).