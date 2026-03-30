### TASK-180 — Add indexes for hot queries

#### What to do
Analyze key queries and add indexes.

#### Minimum
- `merchant_id, created_at`
- `status, created_at`
- `provider_name, provider_event_id`
- unique keys for idempotency

#### Done criteria
- explain plans improved;
- hot queries are documented.