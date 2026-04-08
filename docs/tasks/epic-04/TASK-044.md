# TASK-044 — Implement GET /refunds/{id}

### Add the request for checking refund status.

## Readiness Criteria
- The request is fast;
- The response does not depend on internal tables of other services;
- The merchant sees only their own refunds;
- The response format is consistent with GET /payments/{id}.

## Result

### Files created / modified

**payment-domain**
- `app/Application/Refund/GetRefund.php` — use case: queries `refunds` scoped by `(id, merchant_id)`, returns structured array or null
- `app/Interfaces/Http/Controllers/ShowRefundController.php` — accepts `?merchant_id` query param, delegates to `GetRefund`, returns 404 on null
- `routes/api.php` — added `GET /api/v1/refunds/{id}`
- `tests/Feature/Refund/ShowRefundTest.php` — 4 tests: happy path (structure + timestamps), 404 for unknown id, 404 for cross-merchant isolation

**merchant-api**
- `app/Infrastructure/PaymentDomain/PaymentDomainClient.php` — added `getRefund()`: passes `merchant_id` as query param, returns null on 404
- `app/Interfaces/Http/Controllers/ShowRefundController.php` — reads merchant from request attributes, calls `getRefund()`, returns 404 on null or 200 with the domain response
- `routes/api.php` — added `GET /api/v1/refunds/{id}` under `auth.api` middleware
- `tests/Feature/Refund/ShowRefundTest.php` — 6 tests: happy path, merchant_id forwarding, correlation_id header forwarding, auth, 404 not found, 404 cross-merchant isolation
- `docs/merchant-api.postman_collection.json` — added "Get Refund" (happy path + 404 + no-auth), "Get Refund — not found", "Get Refund — no auth" to the Authenticated — Refunds folder

### Design decisions
- Response shape `{refund_id, payment_id, status, amount, currency, correlation_id, created_at, updated_at}` mirrors the `GET /payments/{id}` shape. `correlation_id` comes from the stored refund record (set at creation time), unlike `GET /payments/{id}` which echoes the request header — this is consistent because the refund's correlation_id is already stored on the record.
- Merchant isolation is enforced identically to `GET /payments/{id}`: both the payment-domain and merchant-api return 404 (not 403) for cross-merchant requests, preventing existence leaks.