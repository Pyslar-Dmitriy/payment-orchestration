### TASK-090 — Implement the raw webhook queue consumer

#### What to do
Create a worker that consumes `provider.webhook.raw`.

#### Logic
- read raw event ID;
- load raw payload;
- normalize it;
- recheck processing safety;
- signal the workflow;
- publish the normalized event.

#### Done criteria
- the worker is retry-safe;
- it does not perform irreversible actions without dedup protection.