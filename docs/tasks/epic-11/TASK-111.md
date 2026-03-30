### TASK-111 — Implement callback dispatch to RabbitMQ

#### What to do
After a successful payment or refund, create a callback task and enqueue it.

#### Done criteria
- dispatch is asynchronous;
- payload is versioned;
- delivery ID is traceable.