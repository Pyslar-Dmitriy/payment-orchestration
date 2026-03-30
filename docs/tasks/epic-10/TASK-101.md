### TASK-101 — Implement ledger posting for capture

#### What to do
Create a use case that generates a ledger transaction and entries for a successful payment.

#### Important
- the transaction must balance;
- the operation must be idempotent;
- repeated execution must not create duplicates.

#### Done criteria
- total debits equal total credits;
- duplicate requests are safe;
- operation audit is stored.