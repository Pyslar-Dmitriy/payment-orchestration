### TASK-072 — Implement provider request/response audit logging

#### What to do
Store the history of communication with the provider:
- request payload;
- response payload;
- status code;
- latency;
- timestamps;
- correlation/payment references.

#### Done criteria
- it is possible to reconstruct what was sent to the provider and what came back;
- logs are useful for debugging errors.