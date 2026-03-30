### TASK-162 — Simulate provider timeout and retry avalanche scenario

#### What to do
Verify that mass provider timeouts do not create an uncontrolled retry storm.

#### Done criteria
- retries are bounded;
- the system remains stable;
- there are logs/metrics for this case.