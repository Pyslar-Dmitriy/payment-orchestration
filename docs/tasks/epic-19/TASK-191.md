### TASK-191 — Minimize sensitive data in logs

#### What to do
Mask:
- payment tokens;
- secrets;
- signatures;
- sensitive headers;
- any fields that look like PII.

#### Done criteria
- the log scheme is safe;
- masking is documented and tested.