# ADR-006 — Separate webhook ingestion from webhook normalization and business application

**Status:** <span style="color:green">Accepted</span>

## Context

Provider webhooks are one of the hottest and most failure-prone entry points in the platform.
The system must handle:

* burst traffic,
* duplicate events,
* variable payload shapes,
* signature verification,
* out-of-order delivery,
* temporary downstream failures.

If webhook HTTP handling performs too much business logic directly, the platform becomes fragile and harder to scale.

## Decision

Split webhook processing into separate responsibilities:

### Webhook Ingest service

Responsible for:

* receiving webhook HTTP requests,
* verifying signature,
* storing raw payload,
* deduplicating by provider event id,
* enqueueing a raw processing task,
* returning fast HTTP acknowledgment.

### Webhook Normalizer service

Responsible for:

* loading raw event,
* parsing provider-specific payload,
* mapping external statuses to internal event model,
* signaling the relevant Temporal workflow,
* publishing normalized Kafka event if required.

## Alternatives considered

### Alternative A — One service handles ingest + parsing + business update synchronously

Pros:

* fewer components,
* simpler initial development.

Cons:

* slower webhook response time,
* higher fragility under bursts,
* tighter coupling between HTTP ingress and business workflow,
* harder retry and replay model.

### Alternative B — Apply business updates directly from provider-gateway service

Pros:

* fewer services.

Cons:

* mixes outbound provider integration with inbound async processing,
* weakens bounded context clarity,
* complicates debugging and scaling.

## Consequences

Positive:

* fast-ack webhook handling,
* better burst tolerance,
* easier replay and diagnostics,
* clearer separation between ingress and business interpretation,
* independent scaling of webhook hot path.

Negative:

* more services and contracts,
* more operational pieces to manage.

Operational note:
This split is intentionally chosen because webhook behavior is a realistic highload and reliability hotspot.
