# TASK-031 — Describe async contracts for Kafka and RabbitMQ

### Define event and queue formats.

#### Kafka topics:
- payments.lifecycle.v1
- refunds.lifecycle.v1
- ledger.entries.v1
- provider.webhooks.normalized.v1
- provider.webhooks.undeliverable.v1 *(new — see TASK-094)*

#### RabbitMQ queues:
- provider.webhook.raw
- merchant.callback.dispatch
- merchant.callback.retry.*
- merchant.callback.dlq

### For each message, describe the:
- name;
- version;
- required fields (including `schema_version` — see ADR-012);
- correlation/causation id;
- source service;
- timestamp;
- idempotency key/message id.

### Schema evolution policy
All Kafka messages must include a `schema_version` field in the envelope. Topic names use `.v<N>` suffixes. Breaking changes require a new topic version and a 30-day co-existence window. See **ADR-012** for the full schema evolution strategy and TASK-033 for the implementation of the policy tooling.

## Readiness Criteria
- contracts cover all key messages including `WebhookSignalUndeliverable`;
- all Kafka message envelopes include `schema_version`;
- there is a naming convention consistent with ADR-012;
- the schemas are suitable for validation (used by TASK-032 and TASK-033).