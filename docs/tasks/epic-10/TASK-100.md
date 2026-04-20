### TASK-100 — Design the ledger data model

#### Tables
- `ledger_accounts`
- `ledger_transactions`
- `ledger_entries`
- `outbox_messages`

#### What to define
- account types;
- debit/credit rules;
- links to payment/refund references;
- currency support;
- immutable entry policy.

#### Done criteria
- the model can represent authorize/capture/refund/fee;
- it does not require updating old entries;
- balance can be derived from entries.

## Result

### Files created
- `app/Domain/Ledger/AccountType.php` — enum: merchant | provider | fees | escrow
- `app/Domain/Ledger/EntryType.php` — enum: authorization | capture | refund | fee | reversal
- `app/Domain/Ledger/EntryDirection.php` — enum: debit | credit
- `app/Domain/Ledger/LedgerAccount.php` — Eloquent model with `balance()` derivation method
- `app/Domain/Ledger/LedgerTransaction.php` — Eloquent model (append-only, no `updated_at`)
- `app/Domain/Ledger/LedgerEntry.php` — Eloquent model (append-only, no `updated_at`)
- `app/Infrastructure/Outbox/OutboxMessage.php` — matches outbox pattern used by other services
- `tests/Feature/LedgerModelTest.php` — 15 tests covering all four tables

### Files modified
- `database/migrations/2026_01_01_000010_create_accounts_table.php` → creates `ledger_accounts` (renamed from `accounts`; `owner_type` → `type`)
- `database/migrations/2026_01_01_000011_create_ledger_entries_table.php` → repurposed to create `ledger_transactions`
- `database/migrations/2026_01_01_000012_create_outbox_events_table.php` → repurposed to create `ledger_entries` (proper double-entry lines)

### Files created (migrations)
- `database/migrations/2026_01_01_000013_create_outbox_messages_table.php` → creates `outbox_messages` (with partial index for pending polling)

### Design decisions
- **True double-entry**: each `ledger_transaction` groups one or more `ledger_entries`; each entry is a single debit or credit line with `direction` (debit|credit). This supports multi-leg transactions (e.g., capture with fee split into 3 legs: debit escrow, credit merchant, credit fees).
- **Idempotency key on transactions**: prevents duplicate postings; callers use `"capture:{payment_id}:{correlation_id}"` format (matching `LedgerPostActivityImpl`).
- **Balance derivation**: `LedgerAccount::balance()` uses `SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END)` over all entries — no running balance field, no mutation required.
- **Immutability enforced**: `ledger_transactions` and `ledger_entries` have no `updated_at`, no soft deletes, and `UPDATED_AT = null` on the models.
- **`outbox_messages` vs `outbox_events`**: task spec names the table `outbox_messages`; other services use `outbox_events`. This divergence is intentional per the task; can be standardized in a future cleanup task.