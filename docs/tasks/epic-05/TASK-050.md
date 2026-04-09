# TASK-050 — Design a Payment Data Model

### Tables:
- `payments`
- `payment_attempts`
- `payment_status_history`
- `outbox_messages`
- `processed_inbox_messages`

### Consider:
- provider reference;
- merchant reference;
- external order id;
- idempotency reference;
- failure code/reason;
- version for optimistic locking.

## Readiness Criteria
- The model covers the entire payment lifecycle;
- There is no need to break the design after adding a webhook flow.

## Result

### Migrations created
- `2026_04_09_000001_add_missing_fields_to_payments_table.php` — adds `idempotency_key` (nullable, unique), `failure_code`, `failure_reason`, `version` to `payments`
- `2026_04_09_000002_create_payment_attempts_table.php` — new table tracking per-provider-call attempts (ULID PK, attempt_number, provider_id, provider_transaction_id, status, failure_code, failure_reason, provider_response JSON)
- `2026_04_09_000003_create_processed_inbox_messages_table.php` — new inbox dedup table (UUID PK, message_id unique, message_type, payload JSON, processed_at)

### Note on `outbox_messages` vs `outbox_events`
The task spec lists `outbox_messages` as the table name. The existing table was kept as `outbox_events` to avoid disrupting working EPIC-04 code with no functional gain. The pattern and structure are identical.

### Models created
- `app/Domain/Payment/PaymentAttempt.php`
- `app/Infrastructure/Inbox/ProcessedInboxMessage.php`

### Enums created
- `app/Domain/Payment/PaymentStatus.php` — 10 cases including `requires_reconciliation` (ADR-010)
- `app/Domain/Payment/PaymentAttemptStatus.php`
- `app/Domain/Refund/RefundStatus.php`

### Models updated
- `Payment` — new fillable fields, `status` cast to `PaymentStatus`, `version` cast, `attempts()` relationship
- `PaymentStatusHistory` — `from_status`/`to_status` cast to `PaymentStatus`
- `Refund` — `status` cast to `RefundStatus`

### Application layer updated
- `InitiatePayment` — added `idempotency_key` field; enum constants used for status; `->value` used when embedding status in serialized arrays
- `InitiatePaymentRequest` — added `idempotency_key` required validation rule
- `InitiateRefundController` — status comparison changed from `!== 'captured'` to `!== PaymentStatus::Captured`
- `InitiateRefund`, `GetPayment`, `GetRefund` — `->value` used when returning status in response arrays

### Tests
61 tests pass (184 assertions). New test files: `PaymentStatusTest`, `RefundStatusTest`, `PaymentAttemptStatusTest`, `PaymentAttemptModelTest`, `ProcessedInboxMessageTest`.