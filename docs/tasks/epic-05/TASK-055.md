### TASK-055 — Add optimistic locking or selective pessimistic locking

#### What to do
Choose a concurrent update control strategy and implement it.

#### Done criteria
- race conditions between status updates are reproducible in tests;
- the selected strategy actually prevents them;
- the decision is documented in an ADR.