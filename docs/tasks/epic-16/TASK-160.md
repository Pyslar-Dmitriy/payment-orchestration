### TASK-160 — Simulate duplicate webhook scenario

#### What to do
Run a scenario where the same webhook is delivered multiple times.

#### Expected behavior
- raw event is stored;
- deduplication works;
- business status is not applied twice;
- the ledger is not duplicated.

#### Done criteria
- there is a test or a reproducible scenario;
- behavior is documented.