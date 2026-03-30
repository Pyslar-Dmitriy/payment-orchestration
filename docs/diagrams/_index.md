# Payment Orchestration Platform — C4 and Sequence Diagrams

This document contains the initial architecture diagrams for the payment orchestration platform.

## 1. [C4 — Container Diagram](./C4.png)

```mermaid
flowchart LR
    merchant[Merchant Client / Admin UI]
    psp[External Payment Provider / PSP]

    subgraph Platform[Payment Orchestration Platform]
        merchantApi[Merchant API]
        paymentDomain[Payment Domain Service]
        orchestrator[Payment Orchestrator / Temporal Workers]
        providerGateway[Provider Gateway]
        webhookIngest[Webhook Ingest]
        webhookNormalizer[Webhook Normalizer]
        ledger[Ledger Service]
        callbackDelivery[Merchant Callback Delivery]
        reporting[Reporting Projection]

        rabbit[(RabbitMQ)]
        kafka[(Kafka)]
        temporal[(Temporal)]

        paymentDb[(PostgreSQL: payment-domain)]
        providerDb[(PostgreSQL: provider-gateway)]
        webhookDb[(PostgreSQL: webhooks)]
        ledgerDb[(PostgreSQL: ledger)]
        callbackDb[(PostgreSQL: callbacks)]
        reportingDb[(PostgreSQL: reporting)]

        observability[Observability / Prometheus + Grafana + Logs]
    end

    merchant -->|HTTPS| merchantApi
    merchantApi -->|sync command| paymentDomain
    paymentDomain -->|persist payment + outbox| paymentDb
    paymentDomain -->|start workflow| temporal
    orchestrator <-->|poll task queues| temporal
    orchestrator -->|provider activities| providerGateway
    providerGateway -->|persist provider requests/responses| providerDb
    providerGateway -->|HTTPS| psp

    psp -->|webhooks| webhookIngest
    webhookIngest -->|store raw event + dedup| webhookDb
    webhookIngest -->|enqueue raw event| rabbit
    webhookNormalizer -->|consume raw webhook| rabbit
    webhookNormalizer -->|load raw payload| webhookDb
    webhookNormalizer -->|signal workflow| temporal
    webhookNormalizer -->|publish normalized event| kafka

    orchestrator -->|request status update| paymentDomain
    paymentDomain -->|publish domain events| kafka
    orchestrator -->|post financial entries| ledger
    ledger -->|append-only entries| ledgerDb
    ledger -->|publish ledger events| kafka

    orchestrator -->|enqueue callback request| callbackDelivery
    callbackDelivery -->|persist delivery attempts| callbackDb
    callbackDelivery -->|dispatch/retry/DLQ| rabbit
    callbackDelivery -->|HTTPS signed callback| merchant

    kafka -->|consume domain events| reporting
    reporting -->|build read models| reportingDb

    merchantApi --> observability
    paymentDomain --> observability
    orchestrator --> observability
    providerGateway --> observability
    webhookIngest --> observability
    webhookNormalizer --> observability
    ledger --> observability
    callbackDelivery --> observability
    reporting --> observability
```

### Container responsibilities

#### Merchant API

Public HTTP interface for merchants.
Responsible for authentication, request validation, idempotency handling, rate limiting, and exposing payment/refund read operations.

#### Payment Domain Service

Owns authoritative payment lifecycle state.
Responsible for aggregates, state transitions, payment history, outbox, and business invariants.

#### Payment Orchestrator

Runs long-running workflows using Temporal.
Responsible for payment and refund orchestration, waiting for external confirmations, calling activities, and coordinating async flow.

#### Provider Gateway

Encapsulates provider integrations.
Responsible for request mapping, provider API calls, retry-aware activities, response mapping, and provider-side audit records.

#### Webhook Ingest

Fast ingress service for provider webhooks.
Responsible for signature verification, raw payload persistence, deduplication, and async enqueueing.

#### Webhook Normalizer

Converts provider-specific raw webhook payloads into internal normalized events/signals.
Responsible for status mapping and workflow signaling.

#### Ledger Service

Owns append-only financial records.
Responsible for idempotent ledger posting and financial audit trail.

#### Merchant Callback Delivery

Responsible for asynchronous merchant notifications, signed callbacks, retries, and DLQ handling.

#### Reporting Projection

Consumes Kafka domain events and builds read models for analytics, dashboards, and query-optimized projections.

---

## 2. [Sequence Diagram — Payment Flow](./payment-flow.png)

```mermaid
sequenceDiagram
    autonumber
    participant M as Merchant
    participant API as Merchant API
    participant PD as Payment Domain
    participant T as Temporal / PaymentWorkflow
    participant PG as Provider Gateway
    participant PSP as External PSP
    participant WI as Webhook Ingest
    participant WN as Webhook Normalizer
    participant L as Ledger Service
    participant CB as Callback Delivery
    participant K as Kafka

    M->>API: POST /payments\n(Idempotency-Key, amount, currency, refs)
    API->>API: Authenticate merchant\nValidate request\nCheck idempotency
    API->>PD: CreatePayment command
    PD->>PD: Create payment aggregate\nCreate payment attempt\nWrite status history
    PD->>PD: Persist outbox event in same transaction
    PD-->>API: payment_id + status=created/pending
    API-->>M: 202/201 Accepted-like response

    PD->>T: Start PaymentWorkflow(payment_id)
    T->>T: Select provider
    T->>PG: Authorize/Capture activity
    PG->>PSP: Provider API request
    PSP-->>PG: Sync response\n(success/pending/requires_action/failure)
    PG-->>T: Provider result

    alt Provider returned final failure synchronously
        T->>PD: Mark payment failed
        PD->>K: Publish PaymentFailed event
        T->>CB: Enqueue merchant callback
        CB-->>M: Payment failed callback
    else Provider returned pending / async confirmation expected
        T->>PD: Mark payment pending_provider
        PD->>K: Publish PaymentPendingProvider event
        T->>T: Wait for webhook signal

        PSP->>WI: Webhook event
        WI->>WI: Verify signature\nStore raw payload\nDeduplicate
        WI->>WN: Enqueue raw webhook reference
        WN->>WN: Normalize provider payload
        WN->>T: Signal workflow with normalized result
        WN->>K: Publish normalized webhook event

        alt Webhook indicates success/capture
            T->>PD: Mark payment captured/authorized
            PD->>K: Publish PaymentCaptured event
            T->>L: Post ledger entries
            L->>K: Publish LedgerEntryPosted event
            T->>CB: Enqueue merchant callback
            CB-->>M: Payment success callback
        else Webhook indicates failure
            T->>PD: Mark payment failed
            PD->>K: Publish PaymentFailed event
            T->>CB: Enqueue merchant callback
            CB-->>M: Payment failure callback
        end
    end
```

### Notes

* The public API returns a stable internal identifier and does not depend on final synchronous completion.
* Workflow state is durable in Temporal.
* Payment Domain remains the owner of payment lifecycle state.
* Ledger posting happens only after a financially meaningful outcome.

---

## 3. [Sequence Diagram — Webhook Flow](./webhook-flow.png)

```mermaid
sequenceDiagram
    autonumber
    participant PSP as External PSP
    participant WI as Webhook Ingest
    participant WDB as Webhooks DB
    participant R as RabbitMQ
    participant WN as Webhook Normalizer
    participant T as Temporal Workflow
    participant PD as Payment Domain
    participant K as Kafka

    PSP->>WI: POST /webhooks/{provider}
    WI->>WI: Resolve provider route
    WI->>WI: Verify webhook signature

    alt Invalid signature
        WI-->>PSP: 401/400 reject
    else Valid signature
        WI->>WDB: Store raw payload + headers + metadata
        WI->>WDB: Check/insert dedup key(provider,event_id)

        alt Duplicate event
            WI-->>PSP: 200 OK
        else New event
            WI->>R: Publish raw webhook reference
            WI-->>PSP: 200 OK
            WN->>R: Consume raw webhook reference
            WN->>WDB: Load raw payload
            WN->>WN: Parse provider-specific payload
            WN->>WN: Map external status -> internal normalized status
            WN->>T: Signal workflow(payment_id, normalized_event)
            WN->>K: Publish provider.webhooks.normalized.v1
        end
    end

    T->>PD: Apply valid status transition
    PD->>K: Publish payment lifecycle event
```

### Notes

* Webhook HTTP handling is intentionally thin and fast.
* Raw payload is stored before downstream processing.
* Deduplication is applied at ingest and should be reinforced downstream with inbox/processed message tracking.
* Normalization is separated from ingress to keep the hot path simple and scalable.

---

## 4. [Sequence Diagram — Refund Flow](./refund-flow.png)

```mermaid
sequenceDiagram
    autonumber
    participant M as Merchant
    participant API as Merchant API
    participant PD as Payment Domain
    participant T as Temporal / RefundWorkflow
    participant PG as Provider Gateway
    participant PSP as External PSP
    participant WI as Webhook Ingest
    participant WN as Webhook Normalizer
    participant L as Ledger Service
    participant CB as Callback Delivery
    participant K as Kafka

    M->>API: POST /refunds\n(Idempotency-Key, payment_id, amount)
    API->>API: Authenticate merchant\nValidate request\nCheck idempotency
    API->>PD: RequestRefund command
    PD->>PD: Validate captured/refundable state\nCreate refund request entity\nWrite outbox event
    PD-->>API: refund_id + status=requested/pending
    API-->>M: Accepted response

    PD->>T: Start RefundWorkflow(refund_id)
    T->>PG: Refund activity
    PG->>PSP: Provider refund request
    PSP-->>PG: Sync response\n(success/pending/failure)
    PG-->>T: Provider result

    alt Provider returns final failure synchronously
        T->>PD: Mark refund failed
        PD->>K: Publish RefundFailed event
        T->>CB: Enqueue merchant callback
        CB-->>M: Refund failed callback
    else Provider returns pending / async confirmation expected
        T->>PD: Mark refund pending_provider
        PD->>K: Publish RefundPendingProvider event
        T->>T: Wait for refund webhook signal

        PSP->>WI: Refund webhook
        WI->>WI: Verify signature\nStore raw payload\nDeduplicate
        WI->>WN: Enqueue raw webhook reference
        WN->>WN: Normalize refund event
        WN->>T: Signal RefundWorkflow
        WN->>K: Publish normalized webhook event

        alt Refund confirmed
            T->>PD: Mark refund succeeded
            PD->>K: Publish RefundSucceeded event
            T->>L: Post refund ledger entries
            L->>K: Publish LedgerEntryPosted event
            T->>CB: Enqueue merchant callback
            CB-->>M: Refund success callback
        else Refund failed
            T->>PD: Mark refund failed
            PD->>K: Publish RefundFailed event
            T->>CB: Enqueue merchant callback
            CB-->>M: Refund failed callback
        end
    end
```

### Notes

* Refund is modeled as its own workflow rather than a side effect hidden inside the payment flow.
* Refund eligibility is validated by the Payment Domain service before the workflow starts.
* Ledger receives a separate financial posting flow for refund operations.

---

## 5. Suggested follow-up diagrams

The following diagrams can be added later:

* deployment diagram (Docker Compose / Kubernetes)
* provider routing decision flow
* outbox publishing flow
* merchant callback retry/DLQ flow
* ledger posting model
* observability / tracing flow
* failure scenarios (duplicate webhook, out-of-order status, retry avalanche)

---

## 6. Modeling conventions

The diagrams follow these architectural rules:

* Payment Domain owns authoritative payment state.
* Ledger Service owns financial truth.
* Webhook Ingest is separated from Webhook Normalizer.
* RabbitMQ is used for operational async work.
* Kafka is used for domain event streaming.
* Temporal is used for long-running orchestration.
* Service data is owned locally and never joined across services directly.
