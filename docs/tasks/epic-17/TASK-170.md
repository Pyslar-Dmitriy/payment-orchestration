### TASK-170 — Prepare a k6 scenario for `POST /payments`

#### What to do
Write a load test for payment creation burst traffic.

#### Measure
- p95/p99 latency;
- error rate;
- DB contention;
- response stability under idempotency pressure.

#### Done criteria
- the test can be run locally or in a separate perf environment;
- results are saved.