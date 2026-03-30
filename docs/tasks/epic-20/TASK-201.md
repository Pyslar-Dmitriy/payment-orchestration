### TASK-201 — Prepare an example of independent scaling

#### What to do
Prepare an example such as:
- `webhook-ingest` = 10 replicas
- `merchant-api` = 3 replicas
- `payment-orchestrator` workers = 6 replicas
- `ledger-service` = 2 replicas

#### Done criteria
- it is possible to explain why scaling differs by service;
- this closes the monorepo vs independent scaling question.