### TASK-081 — Implement incoming webhook deduplication

#### What to do
Add a dedup table based on a unique provider event reference.

#### Possible key
- `(provider_name, provider_event_id)`

#### Done criteria
- repeated webhook delivery does not lead to repeated processing;
- deduplication is covered by tests;
- duplicates are visible in logs and metrics.