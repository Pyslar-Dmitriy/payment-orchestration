### TASK-053 — Implement payment status update use cases

#### What to do
Create separate use cases for:
- mark pending provider
- mark authorized
- mark captured
- mark failed
- mark refunding
- mark refunded

#### Important
Each use case must:
- validate the current status;
- write status history;
- write an outbox event.

#### Done criteria
- arbitrary status jumps are impossible;
- status changes only through the application layer;
- an event is always publishable through the outbox.