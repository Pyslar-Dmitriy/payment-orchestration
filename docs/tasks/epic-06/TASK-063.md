### TASK-063 — Implement activities for provider, ledger, and notifications

#### Activity list
- select provider
- send provider auth/capture
- post ledger entries
- request merchant callback
- publish orchestration audit event

#### Done criteria
- activities do not contain workflow state logic;
- all external calls are moved into activities;
- retry policy is defined explicitly.