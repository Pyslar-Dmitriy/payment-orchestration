# ADR-016 — Use readonly DTOs for use-case input and output instead of plain arrays

**Status:** <span style="color:green">Accepted</span>

## Context

Application use cases (in `app/Application/`) exchange data with their callers (controllers, other use cases, queue consumers) at two boundaries:

* **Input** — validated HTTP request data passed into the use case.
* **Output** — structured result data returned by the use case.

Both boundaries are commonly implemented with plain associative arrays and docblock type hints:

```php
// @param array{merchant_id: string, amount: int, ...} $data
public function execute(array $data): array
```

This works but has significant drawbacks at both boundaries:

* Shape is enforced by convention only — a typo in a key or a missing field silently produces `null` or a broken response.
* Callers access values via string keys (`$data['merchant_id']`, `$result['payment_id']`) with no IDE autocomplete.
* Adding or removing a field requires updating docblocks, call sites, and the use case body — with no compile-time feedback if any are missed.
* Output-side: internal control fields (e.g., `created: bool` consumed by the controller) must be manually stripped before serialization.

PHP 8.2+ readonly classes eliminate this problem class at both boundaries.

## Decision

Every application use case that exchanges structured data with callers must use **readonly DTO classes** on both sides:

* **Command** — carries validated input into a write use case.
* **Result** — carries structured output out of a use case.

### Input: Command DTOs

* Live in `app/Application/<Domain>/DTO/`, co-located with the use case.
* Named after the use case intent: `InitiatePaymentCommand`, `InitiateRefundCommand`.
* Declared as `readonly` PHP 8.4 classes.
* No `JsonSerializable` — commands are never serialized to HTTP responses.
* The **controller** constructs the command from the already-validated `FormRequest` data. The use case never touches `$request`.

```php
// app/Application/Payment/DTO/InitiatePaymentCommand.php
readonly class InitiatePaymentCommand
{
    public function __construct(
        public string  $merchantId,
        public int     $amount,
        public string  $currency,
        public string  $externalReference,
        public string  $idempotencyKey,
        public string  $providerId,
        public ?string $customerReference,
        public ?string $paymentMethodReference,
        public ?array  $metadata,
        public string  $correlationId,
    ) {}
}
```

Controller usage:

```php
$command = new InitiatePaymentCommand(
    merchantId:            $request->validated('merchant_id'),
    amount:                $request->validated('amount'),
    // ... remaining fields
);
$result = $this->initiatePayment->execute($command);
```

Only write-side use cases get Command DTOs. Read-side use cases that accept only a small number of scalar identifiers (e.g. `GetPayment(string $paymentId, string $merchantId)`) may keep plain typed parameters — wrapping two strings in a query object adds noise with no type-safety benefit.

### Output: Result DTOs

* Live in `app/Application/<Domain>/DTO/`, co-located with the use case.
* Named after the use case result: `InitiatePaymentResult`, `GetPaymentResult`.
* Declared as `readonly` PHP 8.4 classes.
* Implement `JsonSerializable` so they can be passed directly to `response()->json()`.
* `jsonSerialize()` returns only the fields that belong in the HTTP response — internal control fields (e.g., `$created`) are exposed as typed public properties but excluded from serialization.

```php
// app/Application/Payment/DTO/InitiatePaymentResult.php
readonly class InitiatePaymentResult implements JsonSerializable
{
    public function __construct(
        public string  $paymentId,
        public ?string $attemptId,
        public string  $status,
        public bool    $created,   // controller signal — not in JSON output
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'payment_id' => $this->paymentId,
            'attempt_id' => $this->attemptId,
            'status'     => $this->status,
        ];
    }

    public function isCreated(): bool
    {
        return $this->created;
    }
}
```

### File layout

```
app/Application/Payment/
  DTO/
    InitiatePaymentCommand.php   ← input for InitiatePayment
    InitiatePaymentResult.php    ← output of InitiatePayment
    GetPaymentResult.php         ← output of GetPayment (no command — two scalar params)
  InitiatePayment.php
  GetPayment.php
```

## Alternatives considered

### Alternative A — Plain arrays with typed docblocks (both sides)

Pros:
* Zero extra files; familiar to PHP developers.

Cons:
* No runtime enforcement of shape; missing keys produce `null` silently at both boundaries.
* String key access with no IDE autocomplete on either side.
* Output: internal fields require manual `unset()` before serialization.

### Alternative B — Pass `FormRequest` directly into the use case

Pros:
* No mapping step in the controller.

Cons:
* Couples the use case to Laravel's HTTP layer; cannot be called from a queue consumer or CLI command without faking an HTTP request.
* Breaks the layer boundary defined in ADR-013: `FormRequest` is an HTTP interface concern, not an application concern.

### Alternative C — Laravel Resources (`JsonResource`) for output

Pros:
* Built into Laravel; designed for HTTP response transformation.

Cons:
* Couples the use case result to HTTP semantics — use cases should remain transport-agnostic.

### Alternative D — Immutable value objects using `spatie/data`

Pros:
* Rich ecosystem; automatic validation, casting, collection support.

Cons:
* External package dependency for a problem solvable with native PHP 8.4 features.

## Consequences

Positive:
* Use-case signatures are fully typed end-to-end: `execute(InitiatePaymentCommand): InitiatePaymentResult`.
* Both input and output properties have IDE autocomplete and static analysis coverage.
* Controllers are explicit mapping adapters — their job of translating HTTP into domain intent is made visible.
* Output: internal control signals are cleanly separated from serialized JSON via `jsonSerialize()`.
* Use cases are transport-agnostic and can be called from CLI, queues, or tests without an HTTP request object.

Negative:
* One additional file per write use case input — acceptable given the type-safety benefit.
* Controller mapping is slightly more verbose than `$request->validated()` passthrough.