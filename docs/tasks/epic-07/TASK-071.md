### TASK-071 — Implement `MockProvider`

#### What to do
Create a controllable mock provider that can:
- return success;
- return timeout;
- send delayed webhook;
- send duplicate webhook;
- send out-of-order statuses.

#### Done criteria
- scenarios are configurable via config or test flags;
- the mock provider is suitable for integration and load tests.