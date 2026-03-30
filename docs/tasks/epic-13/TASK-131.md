### TASK-131 — Set up structured logging

#### What to do
Use JSON logs with mandatory fields:
- timestamp
- level
- service
- payment_id
- merchant_id
- correlation_id
- causation_id
- workflow_id
- provider_event_id

#### Done criteria
- logs are consistent across services;
- the event path can be reconstructed from logs.