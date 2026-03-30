### TASK-142 — Configure retry and timeout policy for Temporal activities

#### What to do
Explicitly define retry policy for activities, especially provider integration and callback initiation.

#### Done criteria
- activities do not retry forever by default;
- timeouts match the operation type.