# PaymentWorkflow

Reference documentation for `PaymentWorkflowImpl` in the `payment-orchestrator` service.

**Related:** [ADR-004](adrs/ADR-004-use-temporal-as-orchestration-layer.md) · [ADR-008](adrs/ADR-008-prefer-asynchronous-first-payment-lifecycle.md) · [ADR-010](adrs/ADR-010-workflow-compensation-strategy.md) · [ADR-011](adrs/ADR-011-provider-routing-strategy.md)

---

## Identity and entry point

| Property | Value |
|---|---|
| Workflow type name | `PaymentWorkflow` |
| Workflow ID | `payment_uuid` — one workflow per payment, never reused |
| Task queue | `config('temporal.task_queue')` |
| Run timeout | 2 hours (safety net above the 30-minute webhook wait) |
| Started by | `payment-domain` via `POST /api/workflows/payments` |

The HTTP endpoint accepts a JSON body and starts a Temporal workflow execution:

```
POST /api/workflows/payments
{
  "payment_uuid":   "<uuid>",
  "merchant_id":    "<uuid>",
  "amount":         9900,
  "currency":       "USD",
  "country":        "US",
  "correlation_id": "<uuid>"
}

201 Created  →  { "workflow_id": "<payment_uuid>" }
409 Conflict →  workflow already running for this payment
422           →  validation error
```

---

## Step-by-step flow

```
 ┌─────────────────────────────────────────────────────────────────────────┐
 │  PaymentWorkflow                                                        │
 │                                                                         │
 │  1. markPendingProvider                                                 │
 │  2. selectProvider ──────────────────────────── fail → Class A         │
 │  3. authorizeAndCapture ─────────────────────── fail → fallback        │
 │     └─ fallback: selectProvider + authorizeAndCapture ── fail → Class A│
 │                                                                         │
 │  4. isAsync?                                                            │
 │     ├─ no  → evaluate sync result ───────────── fail → Class A         │
 │     │        └─ success → Step 8               success ─────┐          │
 │     └─ yes → Step 5                                         │          │
 │                                                             │          │
 │  5. awaitWithTimeout(30 min)                                │          │
 │     ├─ authorization_result signal                          │          │
 │     │   ├─ success → markAuthorized, keep waiting           │          │
 │     │   └─ fail    → Class A                                │          │
 │     ├─ capture_result signal → Step 7                       │          │
 │     └─ timeout → Step 6                                     │          │
 │                                                             │          │
 │  6. queryStatus (timeout recovery)                          │          │
 │     ├─ isCaptured  → Step 7                                 │          │
 │     ├─ isAuthorized → markAuthorized, Step 7                │          │
 │     ├─ isFailed    → Class A                                │          │
 │     ├─ unknown     → Class A (fail-safe)                    │          │
 │     └─ query fails → authorizationReceived? Class B : Class A          │
 │                                                             │          │
 │  7. isProviderSuccessStatus? ────────────────── no → Class A           │
 │     └─ yes ─────────────────────────────────────────────────┘          │
 │                                                                         │
 │  8. markCaptured                                                        │
 │     postCapture ─────────────────────────────── fail → Class B         │
 │     publishPaymentCaptured                                              │
 │     triggerCallback("captured")                                         │
 └─────────────────────────────────────────────────────────────────────────┘
```

### Step 1 — Mark pending provider

Transitions the payment to `pending_provider` in payment-domain before any external call is made. This is the last point at which the payment is in a purely internal state.

### Steps 2–3 — Provider routing and call

`selectProvider` returns the key of the highest-priority eligible provider (see ADR-011).

`authorizeAndCapture` submits the authorize+capture request to the provider gateway. If the primary provider fails permanently (all retries exhausted), the workflow excludes it and attempts one fallback:

```
selectProvider([])               → providerKey
authorizeAndCapture(providerKey) → fails
selectProvider([providerKey])    → fallbackKey
authorizeAndCapture(fallbackKey) → fails → Class A ("provider_hard_failure")
```

Only one fallback attempt is made. If the fallback also fails hard, the workflow moves to Class A.

### Step 4 — Synchronous vs asynchronous result

`authorizeAndCapture` returns `ProviderCallResult` with an `isAsync` flag:

- **`isAsync = false`** — the provider answered synchronously. The `providerStatus` field contains the final outcome. No webhook wait. Success statuses (`authorized`, `captured`) proceed to step 8; any other status triggers Class A.
- **`isAsync = true`** — the provider accepted the request but the result will arrive via webhook. Proceed to step 5.

### Step 5 — Webhook signal wait (up to 30 minutes)

The workflow blocks on `Workflow::awaitWithTimeout`, draining the signal queue on each wake-up. Two signal types are handled (see [Signal contract](#signal-contract) below).

The timeout is calculated as remaining wall-clock seconds from when the provider call was made, not a fixed 30-minute countdown from the last signal. This means a delayed authorization signal does not silently reset the capture deadline.

### Step 6 — Timeout recovery query

A timeout does not immediately mark the payment failed. Instead, `queryStatus` polls the provider for the current state:

| Query result | Action |
|---|---|
| `isCaptured = true` | Treat as if capture signal arrived; continue to step 7 |
| `isAuthorized = true` | Call `markAuthorized`, treat as if auth signal arrived; continue to step 7 |
| `isFailed = true` | Class A failure with provider's status string |
| All flags false | Class A with reason `timeout_unknown_provider_status` (fail-safe) |
| Query activity fails | `authorizationReceived = true` → Class B; otherwise → Class A with reason `timeout_query_failure` |

The `authorizationReceived` flag is set whenever a successful `authorization_result` signal is processed during step 5. If auth was confirmed before the timeout and the status query then fails permanently, funds may already be ring-fenced — that is a Class B situation.

### Step 7 — Capture status evaluation

After receiving either a capture signal or a successful timeout recovery, the workflow checks `isProviderSuccessStatus`. Only `authorized` and `captured` are treated as success. Any other value (including an empty string from a malformed signal) triggers Class A.

### Step 8 — Happy path completion

```
markCaptured
postCapture        ← permanent failure here → Class B ("ledger_post")
publishPaymentCaptured
triggerCallback("captured")
```

---

## Signal contract

Signals are sent by `webhook-normalizer` (TASK-092) after it processes and normalizes an incoming provider webhook.

| Signal name | Trigger | Required payload fields |
|---|---|---|
| `provider.authorization_result` | Provider confirms authorization | `provider_event_id`, `provider_status`, `provider_reference`, `correlation_id` |
| `provider.capture_result` | Provider confirms capture | `provider_event_id`, `provider_status`, `provider_reference`, `correlation_id` |

`provider_status` must use the internal status vocabulary (`authorized`, `captured`, `failed`, …). Status normalization from provider-specific strings happens in `webhook-normalizer` (TASK-091) — the workflow never sees raw provider strings.

### Multi-signal handling and deduplication

Signal handlers (`onAuthorizationResult`, `onCaptureResult`) always enqueue immediately and return. No Temporal context operations happen in signal handlers. The workflow drains the queue sequentially in the main coroutine.

Deduplication is performed at dequeue time in `consumeNextSignal()`. If `provider_event_id` matches an already-processed ID, the signal is discarded with a `debug` log entry. Signals without a `provider_event_id` are never deduplicated (both copies are processed). This handles the case where the webhook-normalizer delivers the same event twice due to its own at-least-once delivery guarantee.

---

## Failure classification

See [ADR-010](adrs/ADR-010-workflow-compensation-strategy.md) for the full rationale.

### Class A — no external side effect

No provider call succeeded, or the provider explicitly confirmed failure.

```
markFailed(reason)
publishPaymentFailed
triggerCallback("failed")
```

The `reason` string is the provider status or an internal code:

| Reason | Trigger |
|---|---|
| `no_provider_available` | All provider routing exhausted |
| `provider_hard_failure` | Both primary and fallback providers failed permanently |
| `<provider_status>` | Provider returned a non-success status (sync or signal) |
| `timeout_query_failure` | Status query failed and no prior auth confirmation |
| `timeout_unknown_provider_status` | Status query returned ambiguous / unknown state |

### Class B — external side effect already occurred

A provider call succeeded but a subsequent internal step failed permanently.

```
markRequiresReconciliation(failedStep)
publishPaymentRequiresReconciliation(failedStep, lastKnownProviderStatus, failureReason)
```

No merchant callback — this requires operator intervention (see ADR-010). The `PaymentRequiresReconciliation` Kafka event is the operator alert signal.

| `failedStep` | Trigger |
|---|---|
| `ledger_post` | `postCapture` failed permanently after `markCaptured` |
| `provider_status_query` | `queryStatus` failed permanently and `authorizationReceived = true` |

---

## Activity reference

All stubs are created in `initActivities()` at the start of each workflow execution (including replays).

| Interface | Method(s) | Timeout | Max attempts | Implemented in |
|---|---|---|---|---|
| `ProviderRoutingActivity` | `selectProvider` | 30 s | 3 | TASK-073 |
| `ProviderCallActivity` | `authorizeAndCapture` | 60 s | 3 | TASK-063 |
| `ProviderStatusQueryActivity` | `queryStatus` | 30 s | 3 | TASK-063 |
| `UpdatePaymentStatusActivity` | `markPendingProvider`, `markAuthorized`, `markCaptured`, `markFailed`, `markRequiresReconciliation` | 30 s | 3 | TASK-063 |
| `LedgerPostActivity` | `postCapture` | 30 s | 3 | TASK-063 |
| `MerchantCallbackActivity` | `triggerCallback` | 30 s | 3 | TASK-063 |
| `PublishDomainEventActivity` | `publishPaymentCaptured`, `publishPaymentFailed`, `publishPaymentRequiresReconciliation` | 30 s | 3 | TASK-063 |

Retry backoff for all activities: initial 2 s (5 s for `ProviderCallActivity`), coefficient 2.0, maximum interval 30 s (60 s for `ProviderCallActivity`). Final retry policies are defined in TASK-142.

---

## Determinism rules

Temporal replays the entire workflow history on worker restart. Any non-deterministic code will produce a different event sequence on replay, causing a non-determinism error.

| Forbidden in workflow code | Use instead |
|---|---|
| `time()`, `microtime()`, `Carbon::now()` | `Workflow::now()` |
| `rand()`, `mt_rand()`, `random_int()` | `Workflow::uuid4()` |
| `sleep()` | `yield Workflow::timer(N)` |
| Direct HTTP calls | Activity |
| Direct DB queries | Activity |
| `Log::` facade (safe, but prefer Temporal logger) | `Workflow::getLogger()` for workflow-level logs |

Signal handlers (`onAuthorizationResult`, `onCaptureResult`) must not yield — they are called synchronously during replay event injection.

---

## Sequence diagram — async happy path

```
payment-domain          payment-orchestrator     provider-gateway    webhook-normalizer
      │                        │                        │                    │
      │  POST /workflows/      │                        │                    │
      │  payments              │                        │                    │
      │───────────────────────>│                        │                    │
      │  201 Created           │                        │                    │
      │<───────────────────────│                        │                    │
      │                        │                        │                    │
      │                        │ markPendingProvider    │                    │
      │<───────────────────────│                        │                    │
      │                        │                        │                    │
      │                        │ selectProvider         │                    │
      │                        │──────────────────────> │                    │
      │                        │ providerKey            │                    │
      │                        │<────────────────────── │                    │
      │                        │                        │                    │
      │                        │ authorizeAndCapture    │                    │
      │                        │──────────────────────> │                    │
      │                        │ {isAsync: true}        │                    │
      │                        │<────────────────────── │                    │
      │                        │                        │                    │
      │            ┌───────────┤                        │                    │
      │            │  waiting  │  ... up to 30 min ...  │                    │
      │            └───────────┤                        │                    │
      │                        │                        │  signal:           │
      │                        │                        │  authorization_    │
      │                        │<───────────────────────────────────────────│
      │                        │                        │  result            │
      │                        │                        │                    │
      │                        │ markAuthorized         │                    │
      │<───────────────────────│                        │                    │
      │                        │                        │                    │
      │            ┌───────────┤                        │                    │
      │            │  waiting  │                        │                    │
      │            └───────────┤                        │                    │
      │                        │                        │  signal:           │
      │                        │                        │  capture_result    │
      │                        │<───────────────────────────────────────────│
      │                        │                        │                    │
      │                        │ markCaptured           │                    │
      │<───────────────────────│                        │                    │
      │                        │                        │                    │
      │                        │ postCapture ──────────────> ledger-service  │
      │                        │                        │                    │
      │                        │ publishPaymentCaptured ────> Kafka          │
      │                        │                        │                    │
      │                        │ triggerCallback ───────────> merchant-      │
      │                        │                              callback-      │
      │                        │                              delivery       │
```