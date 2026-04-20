### TASK-101 — Implement ledger posting for capture

#### What to do
Create a use case that generates a ledger transaction and entries for a successful payment.

#### Important
- the transaction must balance;
- the operation must be idempotent;
- repeated execution must not create duplicates.

#### Done criteria
- total debits equal total credits;
- duplicate requests are safe;
- operation audit is stored.

## Result

### Files created
- `app/Application/PostCaptureEntries/PostCaptureEntriesCommand.php` — readonly DTO carrying payment_id, merchant_id, amount, currency, correlation_id, causation_id, fee_amount
- `app/Application/PostCaptureEntries/PostCaptureEntriesHandler.php` — use case: finds or creates ledger accounts, opens a DB transaction, creates `LedgerTransaction` + `LedgerEntry` rows, catches `UniqueConstraintViolationException` for concurrent idempotency
- `app/Interfaces/Http/Requests/PostCaptureEntriesRequest.php` — validates all fields; cross-field rule rejects `fee_amount >= amount`
- `app/Interfaces/Http/Controllers/CapturePostingController.php` — wires request → command → handler; returns 201 for new transactions, 200 for idempotent repeats
- `tests/Feature/PostCaptureEntriesTest.php` — 25 tests covering happy path (no fee + with fee), balance invariant, idempotency, all validation edges
- `docs/ledger-service.postman_collection.json` — Postman collection with 4 request examples

### Files modified
- `routes/api.php` — added `POST /api/postings/capture`

### Design decisions
- **Idempotency key**: `"capture:{payment_id}"` — each payment can be captured exactly once; using only `payment_id` (not `correlation_id`) ensures idempotency across retries with different correlation IDs.
- **Double-entry without fee**: DEBIT escrow(amount) + CREDIT merchant(amount). With fee: DEBIT escrow(amount) + CREDIT merchant(amount−fee) + CREDIT fees(fee). Both always balance.
- **Account auto-creation**: `findOrCreateAccount()` calls `firstOrCreate` outside the main DB transaction, with a `UniqueConstraintViolationException` fallback, preventing aborted transactions from a concurrent account insert.
- **201 vs 200**: 201 for a newly created transaction, 200 for an idempotent repeat — distinguished via Eloquent's `wasRecentlyCreated` flag on the returned model.