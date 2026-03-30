### TASK-091 — Implement mapping from external statuses to internal statuses

#### What to do
Create a mapping layer:
- provider payload → internal event DTO

#### Examples
- provider `approved` → internal `authorized`
- provider `settled` → internal `captured`
- provider `declined` → internal `failed`

#### Done criteria
- mapping is centralized;
- it can be changed without rewriting the workflow;
- it is covered by tests for different provider payloads.