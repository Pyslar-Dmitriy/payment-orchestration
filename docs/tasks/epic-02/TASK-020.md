# TASK-020 — Enable Docker Compose for the entire platform

### Create a local infrastructure including:
- PostgreSQL;
- RabbitMQ;
- Kafka;
- Temporal + Temporal UI;
- Prometheus;
- Grafana;
- Loki;
- All Laravel services.

### What to consider
- clear service names;
- volumes;
- inter-container networking;
- init scripts;
- env configs;
- make command.

## Artifacts
- `infra/docker/docker-compose.yml`
- `infra/docker/.env.example`

## Readiness Criteria
- the entire stack is enabled with a single command;
- services are accessible to each other;
- Temporal UI and RabbitMQ management are available locally.