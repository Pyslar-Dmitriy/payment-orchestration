### TASK-120 — Configure domain event publishing to Kafka

#### What to do
Connect a Kafka publisher for:
- payment lifecycle;
- refund lifecycle;
- ledger events.

#### Done criteria
- events are published reliably;
- message keys are chosen deliberately;
- structure is suitable for consumer groups.

## Result

**payment-domain:**
- Fixed `KafkaPublisher` to reuse the producer via lazy init (one connection per publisher instance instead of per publish call) and to throw `BrokerPublishException` for permanent Kafka errors (`UnsupportedApiVersionException`, `UnsupportedApiKeyException`) so they dead-letter immediately rather than retry.
- Updated all payment lifecycle outbox event payloads (InitiatePayment, MarkPendingProvider, MarkAuthorized, MarkCaptured, MarkFailed, MarkRefunding, MarkRefunded, InternalMarkPaymentStatus) to use the contract-defined `MoneyAmount` structure for `amount` — `{"value": integer, "currency": "ISO-4217"}` — instead of flat fields. Added `provider_id` to all payment event payloads where it was missing.
- Updated all refund outbox event payloads (InitiateRefund, InternalMarkRefundStatus) to use the MoneyAmount structure. `InternalMarkRefundStatus` now also derives and includes `provider_id` from the associated payment row (single extra DB column query before the transaction).

**ledger-service:**
- Added `tests/Unit/Infrastructure/Outbox/EventRouterTest.php` covering topic routing and unroutable event handling.
- Added `tests/Unit/Infrastructure/Outbox/KafkaEnvelopeBuilderTest.php` covering envelope field population, version-suffix stripping, correlation/causation ID propagation, and defaults.

**Message key strategy:** aggregate_id is used as the Kafka partition key in both services, guaranteeing all events for the same payment or ledger transaction land in the same partition — enabling ordered processing by consumer groups.

All 201 payment-domain tests and 100 ledger-service tests pass.