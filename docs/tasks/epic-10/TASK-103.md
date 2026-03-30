### TASK-103 — Implement ledger outbox events

#### What to do
After posting a ledger transaction, publish events to Kafka.

#### Done criteria
- events are published through the outbox;
- the projection service can consume them.