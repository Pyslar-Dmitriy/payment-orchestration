### TASK-082 — Store raw payload and processing state

#### Tables
- `webhook_events_raw`
- `webhook_processing_attempts`
- `webhook_dedup`

#### Data to store
- provider name;
- headers;
- raw body;
- signature status;
- received_at;
- current processing state.

#### Done criteria
- any webhook can be replayed and analyzed;
- the data is suitable for replay.