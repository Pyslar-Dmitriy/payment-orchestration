### TASK-064 — Implement signal handling from webhook normalizer

#### What to do
Allow the normalizer to find the correct workflow and signal it with a normalized event.

#### Done criteria
- the signal locates the workflow by payment reference;
- the workflow handles the signal exactly once from a business perspective;
- duplicate signals are safe.