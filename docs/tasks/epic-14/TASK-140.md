### TASK-140 — Implement inbox / processed-message tracking in consumers

#### What to do
For all important consumers, track already processed message IDs.

#### Required in
- webhook normalizer
- callback delivery
- projection consumers
- possibly ledger ingestion

#### Done criteria
- repeated delivery does not produce repeated business effects;
- the mechanism is covered by tests.