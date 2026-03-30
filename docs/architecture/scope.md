# Payment Orchestration Platform — Product Scope

## 1. Product overview

This project is a production-style **payment orchestration platform** for merchants.

The platform acts as an intermediary layer between merchants and external payment providers (PSP/acquirers). Its goal is to provide a reliable, asynchronous, event-driven payment flow with strong emphasis on:

* idempotency
* reliability under retries and duplicates
* webhook ingestion
* workflow orchestration
* financial correctness
* auditability
* observability
* independent service scaling

The platform is not intended to become a full PSP or a PCI-heavy card vault. Instead, it focuses on the operational and architectural problems of modern payment processing systems.

---

## 2. Product goal

The main goal of the system is to allow a merchant to:

1. create a payment request through a stable public API,
2. process the payment asynchronously through an external provider,
3. receive provider updates via webhooks,
4. keep an internal authoritative payment state,
5. post financial records into an internal ledger,
6. notify the merchant about final outcomes,
7. publish domain events for reporting and projections.

From an engineering perspective, the goal is to model a realistic distributed payment architecture that demonstrates production-grade design decisions rather than just a CRUD payment demo.

---

## 3. Primary users and actors

### 3.1 Merchant

A business client integrating with the platform via API.

Merchant capabilities:

* create payments
* query payment status
* request refunds
* receive callbacks/webhooks from the platform

### 3.2 External Payment Provider (PSP / Acquirer)

A third-party payment system that:

* accepts authorization/capture/refund requests,
* returns sync responses,
* sends async webhooks,
* may be unreliable, delayed, duplicated, or out of order.

### 3.3 Internal Operations / Developer / Architect

The platform owner who needs:

* traceability of payment flow,
* observability,
* replay/debug capability,
* clear service boundaries,
* a realistic environment for learning microservices and highload patterns.

---

## 4. Core business problem

Merchants need a stable payment API, but external payment providers are often:

* asynchronous,
* unreliable,
* inconsistent in status models,
* webhook-driven,
* prone to retries and duplicates.

This creates several system-level challenges:

* duplicate payment requests,
* duplicate webhooks,
* out-of-order status updates,
* retry storms,
* partial failures,
* mismatch between business status and provider state,
* need for audit-friendly financial tracking.

The platform solves this by separating concerns into dedicated services and using queues, event streams, orchestration, and strict domain rules.

---

## 5. Main use cases in scope

### UC-01. Merchant creates payment

Merchant sends a request to create a payment.

Expected system behavior:

* validate request,
* authenticate merchant,
* enforce idempotency,
* create internal payment entity,
* start payment workflow,
* return stable payment identifier and current status.

### UC-02. Platform submits request to provider

The system selects a provider and sends an authorization/capture request.

Expected system behavior:

* map internal request to provider format,
* handle sync provider response,
* continue asynchronously when final state is not immediately known.

### UC-03. Provider sends webhook

Provider sends webhook with payment result or status change.

Expected system behavior:

* verify signature,
* persist raw payload,
* deduplicate provider events,
* normalize external status,
* continue corresponding workflow.

### UC-04. Platform updates internal payment state

Based on provider response or webhook, the platform updates authoritative payment status.

Expected system behavior:

* allow only valid status transitions,
* record payment status history,
* publish domain events,
* ensure safe concurrent processing.

### UC-05. Platform posts ledger entries

When the payment reaches a financially relevant stage, the platform creates ledger records.

Expected system behavior:

* create append-only ledger transaction,
* guarantee idempotent posting,
* preserve auditability.

### UC-06. Merchant receives callback

After a meaningful status change, the platform notifies the merchant.

Expected system behavior:

* enqueue delivery asynchronously,
* retry temporary failures,
* send signed callback,
* preserve delivery history.

### UC-07. Merchant requests refund

Merchant initiates refund for a captured payment.

Expected system behavior:

* validate eligibility,
* enforce idempotency,
* orchestrate refund through provider,
* update payment/refund state,
* post ledger entries,
* notify merchant.

### UC-08. Reporting and projections consume events

Internal consumers build read models and operational dashboards from domain events.

Expected system behavior:

* consume event stream independently from main operational flow,
* maintain projection state,
* support replay.

---

## 6. Functional scope for v1

The first version focuses on one complete end-to-end payment flow with realistic operational concerns.

### Included in v1

* merchant authentication for public API
* create payment endpoint
* get payment status endpoint
* refund request endpoint
* payment state machine
* provider integration abstraction
* mock provider with failure simulation
* webhook ingestion endpoint
* webhook signature verification
* raw webhook storage
* webhook deduplication
* webhook normalization
* asynchronous workflow orchestration
* internal ledger posting for successful payment and refund
* merchant callback delivery
* retry policy and DLQ for critical async flows
* outbox pattern in write services
* inbox/dedup for critical consumers
* Kafka domain events for reporting/audit
* reporting/read model projection
* structured logs
* correlation/causation ids
* basic metrics and dashboards
* local distributed environment via Docker Compose

---

## 7. Non-functional scope for v1

The system must be designed with the following non-functional priorities:

### Reliability

* safe retries
* duplicate-safe processing
* resilience to transient external failures
* no loss of critical domain events between DB commit and message publish

### Consistency model

* no distributed ACID transactions across services
* eventual consistency between services
* transactional integrity within service-owned database boundaries

### Auditability

* raw webhooks preserved
* payment status history preserved
* ledger entries immutable
* provider interactions traceable

### Observability

* structured logs with payment_id and correlation_id
* metrics for queues, errors, latency, workflow failures, callback failures
* debuggable async flow

### Scalability

* services deployable independently
* hot paths such as webhook ingest and workers should be scalable separately

### Security hygiene

* webhook signature validation
* secret-based callback signing
* rate limiting on public API
* no logging of sensitive secrets/tokens in raw form

---

## 8. Explicitly out of scope for v1

The following are intentionally excluded from the first version:

### Card data storage / PCI-heavy scope

The platform does not store PAN, CVV, or other raw cardholder data.
It operates on provider-side tokens, references, or abstract payment method identifiers.

### Full payment gateway / PSP implementation

This platform is not a real acquiring bank or licensed PSP.
It orchestrates flows around external providers.

### Complex fraud/risk engine

No advanced fraud scoring, ML detection, or behavioral risk engine in v1.
A future risk service may be added later.

### Chargebacks and disputes

No dispute workflow, evidence management, or chargeback handling in v1.

### Merchant billing and invoicing

The platform does not yet bill merchants for usage or fees as a separate billing product.

### Settlement and payout engine

No merchant payout batching, settlements to bank accounts, or treasury management in v1.

### Multi-region / cross-region failover

The first version is single-region in architectural assumption.
High availability may be discussed at design level, but not fully implemented.

### Advanced admin portal

No full-featured operator back office is required for v1.
Minimal inspection tooling or raw DB/dashboard access is sufficient.

### Full reconciliation with real bank files

Only a simplified reconciliation concept may appear later; full bank statement reconciliation is not part of v1.

### Event sourcing of the whole system

The system may publish events and use append-only ledger, but it is not full event sourcing across all domains.

---

## 9. Product boundaries

### What the platform owns

* public merchant API
* internal authoritative payment state
* orchestration logic
* provider integration abstraction
* webhook intake and normalization
* internal ledger
* merchant callback delivery
* domain events and projections

### What external providers own

* card/token capture mechanics
* cardholder authentication specifics
* provider-specific status model
* external webhook delivery behavior
* provider-side availability and rate limits

### What merchants own

* order lifecycle outside the platform
* frontend checkout experience unless separately implemented
* merchant-side callback consumer reliability

---

## 10. Success criteria for v1

The first version is considered successful if it can reliably demonstrate the following scenarios:

1. Merchant creates a payment with idempotency key.
2. Payment workflow starts and interacts with a provider.
3. Provider webhook is received, verified, stored, deduplicated, and processed.
4. Internal payment state changes only through valid transitions.
5. Ledger entries are posted exactly once from the business perspective.
6. Merchant callback is sent asynchronously with retry support.
7. Domain events are published for reporting.
8. The system remains understandable and traceable under retries, duplicates, and transient failures.

---

## 11. Intentional design constraints for v1

### Synchronous merchant-api → payment-domain coupling

`merchant-api` calls `payment-domain` synchronously to create a payment (HTTP, no queue buffer at intake). This means payment-domain availability directly gates public API availability.

This is an **intentional simplicity choice for v1**. The alternative — accepting the create payment command asynchronously via RabbitMQ — adds ordering complexity, complicates the synchronous API response to the merchant, and is unnecessary at v1 scale.

The consequence: under a brief payment-domain restart or high load, the public API becomes temporarily unavailable for payment creation. This is acceptable for v1 and well-understood. The future option is an async intake command via RabbitMQ with a polling or webhook-based response to the merchant, as used by some PSPs.

---

## 12. Why this product scope is intentionally limited

This platform is designed as a realistic learning and portfolio project for:

* microservice architecture,
* event-driven workflows,
* highload-adjacent design concerns,
* payment-domain reliability patterns.

To keep the project both credible and achievable, the first version intentionally focuses on:

* one strong payment lifecycle,
* one realistic async webhook-driven flow,
* one internal ledger,
* one reliable merchant notification pipeline,
* one reporting pipeline.

This is enough to demonstrate real-world engineering maturity without getting lost in unnecessary enterprise breadth.

---

## 13. Future expansion areas

Potential future extensions after v1:

* multi-provider routing strategy
* partial capture / partial refund
* chargeback workflow
* reconciliation workflow
* payout workflow
* risk service
* operator dashboard
* K8s deployment and autoscaling profiles
* multi-region discussion and failover strategy
* merchant billing

---

## 14. One-sentence positioning

A production-style payment orchestration platform for merchants, built to model reliable asynchronous payment processing with microservices, PostgreSQL, RabbitMQ, Kafka, Temporal, webhook ingestion, internal ledger, and observable distributed workflows.
