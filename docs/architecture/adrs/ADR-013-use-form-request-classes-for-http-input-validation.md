# ADR-013 — Use Form Request classes for HTTP input validation

**Status:** <span style="color:green">Accepted</span>

## Context

HTTP input validation can be placed in several locations:

* Directly in the controller method via `$request->validate([...])`.
* In a dedicated Laravel `FormRequest` subclass, injected as the controller's parameter type.
* In the application use case, treating all input as untrusted.

For a public API that receives JSON from external merchants, validation is a contract concern: it defines what the endpoint accepts, not just a safety net. Where that contract lives affects readability, testability, and reuse.

## Decision

Every HTTP endpoint that accepts user-controlled input must use a dedicated **`FormRequest`** subclass rather than inline `$request->validate()` calls.

Conventions:

* Classes live in `app/Interfaces/Http/Requests/` (co-located with controllers, not in `app/Http/Requests/`).
* Named after the action they guard: `CreateMerchantRequest`, `RotateApiKeyRequest`.
* `authorize()` returns `true` by default for v1 (no per-request authorisation logic yet).
* Rules are declared in `rules()` exactly as they would be in `$request->validate()`.
* Controllers receive the typed `FormRequest` subclass as their parameter — the framework validates and 422s before the controller body runs.

```
app/Interfaces/Http/
  Controllers/
    CreateMerchantController.php
    RotateApiKeyController.php
  Requests/
    CreateMerchantRequest.php    ← guards CreateMerchantController
    RotateApiKeyRequest.php      ← guards RotateApiKeyController
```

## Alternatives considered

### Alternative A — Inline `$request->validate()` in the controller

Pros:
* Zero extra files for simple cases.

Cons:
* Validation rules are invisible to the controller's type signature.
* Cannot be tested in isolation without instantiating a full controller.
* Rules accumulate in the controller body as the endpoint grows.

### Alternative B — Validate inside the use case

Pros:
* Use case is self-contained regardless of transport.

Cons:
* Use cases in this project receive already-typed PHP values from controllers — they are not transport-aware. Pushing HTTP validation into the application layer breaks the layer boundary and couples the use case to HTTP semantics (e.g. 422 responses).

## Consequences

Positive:
* Validation rules are co-located with the HTTP interface, not buried in the controller body or leaking into the application layer.
* `authorize()` provides a clean hook for per-request authorisation logic if needed in the future.
* Request classes are independently unit-testable.
* Controllers remain thin: they receive validated data and delegate to a use case.

Negative:
* One additional file per endpoint that accepts input — acceptable overhead.
* Developers must know to look in `Interfaces/Http/Requests/` rather than inside the controller for validation rules.