### TASK-171 — Prepare a webhook storm test

#### What to do
Simulate mass webhook traffic:
- high throughput;
- duplicates;
- out-of-order payloads.

#### Done criteria
- the ingest endpoint does not crash;
- the queue smooths the spike;
- downstream lag is observable.