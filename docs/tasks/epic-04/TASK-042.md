# TASK-042 — Implement GET /payments/{id}

### Send the current payment status to the merchant.

### Include to the response:
- `payment id`;
- `status`
- `amount`
- `currency`
- `timestamps`
- `provider reference`
- `last known failure reason`

## Readiness Criteria
- The request is fast;
- The response does not depend on internal tables of other services;
- The merchant sees only their own payments.

## Result

Implemented `GET /payments/{id}` across both services.

**payment-domain:**
- `GetPayment` use case (`app/Application/Payment/GetPayment.php`) — queries payment by ID + merchant_id, resolves last failure reason from `payment_status_history` (latest `to_status = 'failed'` entry by ID), returns all required fields.
- `ShowPaymentController` (`app/Interfaces/Http/Controllers/ShowPaymentController.php`) — accepts `merchant_id` query param, returns 404 for unknown/unauthorized payments (no existence leak).
- Route: `GET /api/v1/payments/{id}`
- 5 feature tests covering happy path, failure reason, multi-failure ordering, 404 (unknown), and merchant isolation.

**merchant-api:**
- `PaymentDomainClient::getPayment()` — proxies GET to payment-domain with `merchant_id` query param and `X-Correlation-ID` header; surfaces 404 as `null`.
- `ShowPaymentController` (`app/Interfaces/Http/Controllers/ShowPaymentController.php`) — extracts merchant from auth context, calls client, merges `correlation_id` into response.
- Route: `GET /api/v1/payments/{id}` (inside `auth.api` middleware group)
- 6 feature tests covering happy path, correlation ID forwarding, merchant_id propagation, auth rejection, 404 passthrough, and merchant isolation.