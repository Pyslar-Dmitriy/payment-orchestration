### TASK-121 — Implement the Reporting Projection Service

#### What to do
Create a service that consumes Kafka topics and builds read models.

#### Minimum read models
- merchant payment summary;
- provider performance summary;
- daily aggregates;
- searchable payment read model.

#### Done criteria
- the service is idempotent;
- it can survive replay;
- lag does not affect the main payment flow.