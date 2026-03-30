# Payment orchestration
_A personal project to improve skills in working with microservices and high loads_

Microservice-based payment orchestration platform for merchants with asynchronous payment processing, webhook ingestion, provider routing, internal ledger, refund workflow, idempotency protection, outbox pattern, and event streaming for analytics and audit.

- [Description](#b2b-system-for-merchants-that)
- [Key topics](#key-topics)
- [Services](#services)
- [Key patterns](#key-patterns)
- [High load scenarios](#high-load-scenarios)
- [Tech stack](#tech-stack)
- [Roadmap](./docs/tasks/epics.md) [↗]
- [Scope](./docs/architecture/scope.md) [↗]
- [Architecture decision records](docs/architecture/adrs/_index.md) [↗]
- [Diagrams](docs/diagrams/_index.md) [↗]

### B2B system for merchants that:

- accepts payment requests,
- selects a payment provider,
- processes asynchronous statuses,
- accepts webhooks,
- maintains an internal ledger,
- makes refunds,
- stores an audit trail of events,
- provides reports and payment history.

## Key topics
- High write contention,
- asynchronicity,
- multiple statuses and transitions,
- race conditions,
- message re-delivery,
- webhook storms,
- the importance of money consistency,
- event streaming,
- retry, and DLQ.

## Services
1. API Gateway / Merchant API
2. Payment Service
3. Provider Connector Service
4. Webhook Ingestion Service
5. Ledger Service
6. Notification Service
7. Reporting / Analytics Projection

## Key patterns
- **Idempotency** – _the ability to be called multiple times with the guarantee that the system state will change only once._
- **Outbox Pattern** - _guaranteed delivery of messages without data loss during failures and solves the dual writes problem._
- **Saga** - _Divides the system into services and replaces distributed ACID transactions with a sequence of local transactions._
- **State Machine** – _an architectural approach in which the behavior of a service is determined by its current state, and the transition between states is strictly regulated._
- **DLQ and retries** - _Provide reprocessing of undelivered messages (dead letter queue)._
- **Backpressure** – Feedback that regulates the speed of the data producer to match the speed of the consumer. For load balancing.
- **Observability** – _Metrics, logs, traces._

## High load scenarios
- Webhook storms
- Payment bursting
- Retry avalanche
- Analytics lag

## Tech stack
- PHP
- Laravel
- PostgreSQL
- RabbitMQ
- Kafka
- Temporal
- Grafana
- Loki / OpenTelemetry
- Docker compose
- Kubernetes