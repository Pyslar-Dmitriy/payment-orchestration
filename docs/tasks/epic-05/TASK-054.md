### TASK-054 — Implement the outbox publisher

#### What to do
Create a mechanism that reads `outbox_messages` and publishes:
- domain events to Kafka;
- operational messages to RabbitMQ where needed.

#### What to consider
- retries;
- mark as sent;
- dead-letter or error state;
- safe reprocessing.

#### Done criteria
- no event is lost between DB commit and broker publish;
- rerunning the publisher does not break consistency.