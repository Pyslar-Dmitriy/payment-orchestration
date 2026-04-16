# Reconciliation Runbook — `requires_reconciliation` Payments and Refunds

## Overview

A payment or refund enters the `requires_reconciliation` status when a Temporal workflow step fails permanently **after** an irreversible external action has already occurred (ADR-010 Class B/C failure). The most common cause is the ledger-service being unavailable or returning persistent errors after the provider has already confirmed a capture or refund.

This status means the platform's internal state is inconsistent with the provider's financial state. **Every occurrence is an incident and requires manual resolution.** Do not treat this as a transient condition.

---

## How to identify affected records

### Via Kafka

Subscribe to or replay the `PaymentRequiresReconciliation` and `RefundRequiresReconciliation` events from the Kafka topic. Each event contains:

- `payment_id` / `refund_id`
- `failed_step` — which activity failed (e.g. `ledger_post`, `ledger_post_refund`)
- `last_known_provider_status` — the confirmed provider state at the time of failure
- `failure_reason` — the Temporal activity failure message

### Via direct database query

```sql
-- Payments requiring reconciliation
SELECT id, payment_id, status, failed_step, created_at
FROM payments
WHERE status = 'requires_reconciliation'
ORDER BY created_at DESC;

-- Refunds requiring reconciliation
SELECT id, refund_id, payment_id, status, failed_step, created_at
FROM refunds
WHERE status = 'requires_reconciliation'
ORDER BY created_at DESC;
```

### Via structured logs

Search for log entries with `alert: true` at `ERROR` level:

```json
{ "level": "error", "alert": true, "message": "Payment requires reconciliation — manual intervention needed" }
{ "level": "error", "alert": true, "message": "Refund requires reconciliation — manual intervention needed" }
```

---

## Resolution procedures

### Case: `failed_step = "ledger_post"` (payment capture confirmed, ledger posting failed)

**Financial state:** Provider has captured funds. Ledger has no record of this capture.

**Steps:**

1. Confirm the provider capture is genuine by querying the provider dashboard or provider audit log for the `payment_id`.

2. Invoke the ledger posting endpoint using the correct idempotency key. The idempotency key format is `capture:{payment_id}`. Example:

   ```http
   POST /api/internal/v1/ledger/postings
   Content-Type: application/json
   X-Internal-Secret: <secret>

   {
     "idempotency_key": "capture:{payment_id}",
     "payment_id": "{payment_id}",
     "entry_type": "capture",
     "correlation_id": "{correlation_id}"
   }
   ```

3. Verify the ledger entry was created. Check the ledger service directly:

   ```sql
   SELECT * FROM ledger_entries WHERE idempotency_key = 'capture:{payment_id}';
   ```

4. Transition the payment to `captured` via the reconciliation command:

   ```http
   PATCH /api/internal/v1/payments/{payment_id}/status
   Content-Type: application/json
   X-Internal-Secret: <secret>

   {
     "status": "captured",
     "correlation_id": "{correlation_id}",
     "reconciliation_note": "Manual reconciliation after ledger_post failure"
   }
   ```

5. Verify the `PaymentCaptured` Kafka event is published (check Kafka topic or outbox table).

---

### Case: `failed_step = "ledger_post_refund"` (refund confirmed by provider, ledger reversal failed)

**Financial state:** Provider has returned funds to the customer. Ledger has no record of the reversal.

**Steps:**

1. Confirm the provider refund is genuine by querying the provider dashboard or audit log for the `refund_id`.

2. Invoke the ledger reversal endpoint using the correct idempotency key. The idempotency key format is `refund:{refund_id}`. Example:

   ```http
   POST /api/internal/v1/ledger/postings
   Content-Type: application/json
   X-Internal-Secret: <secret>

   {
     "idempotency_key": "refund:{refund_id}",
     "refund_id": "{refund_id}",
     "payment_id": "{payment_id}",
     "entry_type": "refund",
     "correlation_id": "{correlation_id}"
   }
   ```

3. Verify the ledger reversal entry was created.

4. Transition the refund to `refunded` via the reconciliation command:

   ```http
   PATCH /api/internal/v1/refunds/{refund_id}/status
   Content-Type: application/json
   X-Internal-Secret: <secret>

   {
     "status": "refunded",
     "correlation_id": "{correlation_id}",
     "reconciliation_note": "Manual reconciliation after ledger_post_refund failure"
   }
   ```

5. Verify the `RefundCompleted` Kafka event is published.

---

### Case: `failed_step = "provider_status_query"` (timeout recovery, ambiguous provider state)

**Financial state:** Unknown — the provider may or may not have processed the transaction.

**Steps:**

1. Contact the provider support channel or query the provider dashboard directly to determine the definitive transaction state.

2. If provider confirms **captured / refunded:** follow the `ledger_post` or `ledger_post_refund` procedure above.

3. If provider confirms **no transaction processed:** transition the payment/refund to `failed` via the reconciliation command. No ledger action is needed.

4. Document the resolution and the provider reference in the incident log.

---

## Safety notes

- The ledger posting endpoint is **idempotent by `idempotency_key`**. It is safe to call the posting endpoint multiple times with the same key; duplicate calls will be ignored without creating double entries.
- Do **not** mark a payment `failed` if a capture has been confirmed at the provider. `failed` means no external side effect occurred. Using it to close an inconsistent record would misrepresent the financial state.
- Any operator action on a `requires_reconciliation` record must be documented in the incident log with the correlation ID, the operator's identity, and the action taken.

---

## Escalation

If the ledger service is persistently unavailable and cannot accept the posting, escalate to the on-call engineer responsible for the ledger service. Do not repeatedly retry in a tight loop — the ledger's idempotency key ensures safety when the service recovers.

If the provider state cannot be confirmed within 2 hours, escalate to the payments lead.
