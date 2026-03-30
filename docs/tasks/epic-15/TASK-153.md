### TASK-153 — Write an end-to-end test for the full payment flow

#### Scenario
- merchant creates payment
- workflow starts
- provider responds
- webhook arrives
- status is updated
- ledger is posted
- callback is sent to a merchant

#### Done criteria
- one test passes through the whole chain;
- the e2e flow works in the local environment.