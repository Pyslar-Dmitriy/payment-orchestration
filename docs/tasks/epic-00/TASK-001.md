# [DONE] TASK-001 — Describe the platform's product scope

## Prepare a document that describes:

- Who is the user of the system;
- What is the primary use case;
- Which scenarios are included in the first version;
- Which scenarios are deliberately excluded;
- Where are the platform's boundaries of responsibility.

#### Describe:
- Merchant creates payment
- Payment is processed asynchronously
- Provider sends webhook
- Status is normalized and applied
- Ledger posts financial entries
- Merchant receives callback
- Events are published for reporting
- What should be explicitly excluded:
- PAN/CVV storage;
- PCI full scope;
- payout engine;
- chargebacks;
- complex fraud scoring;
- multi-region deployment.
## Artifacts
`docs/architecture/scope.md`

## Readiness criteria
- in-scope and out-of-scope are described;
- The main business scenarios are outlined;
- The document can explain the purpose of each service.