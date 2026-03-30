### TASK-130 — Introduce correlation and causation IDs across the platform

#### What to do
Pass identifiers through:
- HTTP headers;
- RabbitMQ messages;
- Kafka events;
- Temporal workflow/activity inputs.

#### Done criteria
- the path of a single payment is traceable across the whole system;
- any log can be linked to payment ID and correlation ID.