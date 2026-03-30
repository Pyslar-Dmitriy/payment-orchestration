### TASK-112 — Implement delivery worker with retry/backoff/DLQ

#### Queues
- `merchant.callback.dispatch`
- `merchant.callback.retry.5s`
- `merchant.callback.retry.30s`
- `merchant.callback.retry.5m`
- `merchant.callback.dlq`

#### Logic
- send HTTP callback;
- sign the request;
- retry only temporary errors;
- permanent failure -> DLQ.

#### Done criteria
- callback delivery is reproducible and observable;
- retries are bounded;
- DLQ can be analyzed and replayed manually.