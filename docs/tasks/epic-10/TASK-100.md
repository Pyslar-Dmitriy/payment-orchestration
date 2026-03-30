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