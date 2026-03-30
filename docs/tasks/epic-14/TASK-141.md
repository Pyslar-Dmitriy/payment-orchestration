### TASK-141 — Configure retry policy for RabbitMQ workers

#### What to do
Define:
- transient errors;
- permanent errors;
- retry schedule;
- DLQ route.

#### Done criteria
- no infinite retries;
- policy is documented;
- workers distinguish retriable and non-retriable cases.