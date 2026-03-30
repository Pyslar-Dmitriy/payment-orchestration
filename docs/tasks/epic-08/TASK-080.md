### TASK-080 — Implement the webhook intake endpoint

#### What to do
Add an HTTP endpoint:
- `POST /webhooks/{provider}`

#### Logic
- resolve provider route;
- basic payload validation;
- signature verification;
- store raw event;
- deduplicate;
- publish a task to RabbitMQ;
- return `200` quickly.

#### Done criteria
- the endpoint does not execute heavy domain logic;
- it responds quickly;
- it stores raw payload before any downstream processing.