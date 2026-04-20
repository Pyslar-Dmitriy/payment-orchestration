### TASK-102 — Implement refund ledger posting

#### What to do
Generate reverse entries or a separate refund transaction.

#### Done criteria
- refund does not corrupt previous ledger state;
- history remains readable and audit-friendly.

## Result

### Files created
- `app/Application/PostRefundEntries/PostRefundEntriesCommand.php` — readonly DTO carrying refund_id, payment_id, merchant_id, amount, currency, correlation_id, causation_id, fee_refund_amount
- `app/Application/PostRefundEntries/PostRefundEntriesHandler.php` — use case: finds or creates escrow/merchant/fees accounts, opens a DB transaction, creates `LedgerTransaction` (entry_type=refund, with refund_id) + `LedgerEntry` rows; idempotency key is `"refund:{refund_id}"`
- `app/Interfaces/Http/Requests/PostRefundEntriesRequest.php` — validates all fields; cross-field rule rejects `fee_refund_amount >= amount`
- `app/Interfaces/Http/Controllers/RefundPostingController.php` — wires request → command → handler; returns 201 for new transactions, 200 for idempotent repeats; response includes refund_id
- `tests/Feature/PostRefundEntriesTest.php` — 28 tests covering happy path (no fee + with fee), balance invariant, fee split amounts, idempotency, prior-capture isolation, all validation edges, account auto-creation

### Files modified
- `routes/api.php` — added `POST /api/postings/refund`
- `docs/ledger-service.postman_collection.json` — 3 new Postman entries (no-fee refund, with-fee-refund, 422 missing refund_id)

### Design decisions
- **Idempotency key**: `"refund:{refund_id}"` — scoped to refund_id (not payment_id), allowing multiple partial refunds on the same payment.
- **Double-entry without fee refund**: DEBIT merchant(amount) + CREDIT escrow(amount). Mirrors the capture in reverse.
- **Double-entry with fee refund (3-leg)**: DEBIT merchant(amount - fee_refund_amount) + DEBIT fees(fee_refund_amount) + CREDIT escrow(amount). Platform waives fee income and the merchant bears only the net cost. Always balances.
- **Append-only**: Refund creates a new `LedgerTransaction` (entry_type=refund); the original capture transaction is never touched, preserving full audit history.
- **refund_id stored on transaction**: The `LedgerTransaction` model already had a nullable `refund_id` column; this is now populated, enabling easy lookup of all ledger entries for a given refund.