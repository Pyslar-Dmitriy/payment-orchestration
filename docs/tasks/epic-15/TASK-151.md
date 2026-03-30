### TASK-151 — Write integration tests for key services

#### Scenarios
- create payment → outbox persisted
- webhook ingested → message published
- normalizer → workflow signaled
- ledger entries posted
- callback task enqueued

#### Done criteria
- tests run locally;
- they catch interaction failures between layers.