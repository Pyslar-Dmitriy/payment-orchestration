### TASK-163 — Simulate merchant callback failure scenario

#### What to do
Verify that an unavailable merchant callback endpoint does not break the main flow.

#### Done criteria
- callback goes to retry/DLQ;
- payment flow remains successful.