### TASK-062 — Implement `RefundWorkflow`

#### What to do
Orchestrate the refund flow separately from the payment flow.

#### Workflow logic
- accept a refund request;
- send the refund request to the provider;
- wait for confirmation;
- trigger ledger reversal/refund entries;
- send a merchant callback.

#### Done criteria
- the refund flow is separated from the payment flow;
- the workflow is visible in Temporal UI;
- duplicate requests do not lead to duplicate operations.