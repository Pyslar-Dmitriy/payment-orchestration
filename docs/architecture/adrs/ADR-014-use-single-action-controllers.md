# ADR-014 — Use single-action controllers

**Status:** <span style="color:green">Accepted</span>

## Context

Controllers can be structured in two ways:

* **Multi-action** — one class per resource with methods like `index`, `store`, `show`, `update`, `destroy` (Laravel's resourceful controller convention).
* **Single-action** — one class per endpoint with a single `__invoke` method.

This project's API follows clean architecture with a use case per operation. Each controller is a thin adapter: it receives a validated request, calls one use case, and returns a response. There is no shared state or logic between actions that would justify grouping them into a resource class.

## Decision

Every HTTP controller must be a **single-action controller** with a single public `__invoke` method.

Conventions:

* Named after the HTTP action they perform: `CreateMerchantController`, `ShowMerchantController`, `RotateApiKeyController`.
* Registered in routes using the class constant shorthand: `Route::post('/merchants', CreateMerchantController::class)`.
* Live in `app/Interfaces/Http/Controllers/`.
* Infrastructure controllers that have no business logic and are naturally paired (e.g. `HealthController` with `health()` and `ready()`) may remain multi-method — the rule applies to business-facing endpoints.

```
app/Interfaces/Http/Controllers/
  CreateMerchantController.php   ← POST /v1/merchants
  ShowMerchantController.php     ← GET  /v1/merchants/me
  RotateApiKeyController.php     ← POST /v1/api-keys/rotate
  HealthController.php           ← GET  /health, GET /ready (exception)
```

## Alternatives considered

### Alternative A — Resource controllers (multi-action)

Pros:
* Familiar Laravel convention; less files for CRUD-heavy services.
* IDE tooling and `php artisan route:list` label methods clearly.

Cons:
* Groups unrelated operations into one class for the sake of a naming convention.
* Each additional action increases the class's dependency list, making constructors noisy.
* Methods that are never used still appear in the class, obscuring what the endpoint actually does.
* Does not reflect the one-use-case-per-operation model used here.

## Consequences

Positive:
* Each controller has exactly one reason to change.
* Constructor dependencies directly document what one endpoint needs — no guessing which injected service belongs to which method.
* New endpoints are added by creating a new file, not by modifying an existing class.
* Routes read as a flat, scannable list of `Class::class` invocables.

Negative:
* More files than a resource controller approach — acceptable given the one-file-per-endpoint clarity.
* Developers coming from standard Laravel conventions need to adjust expectations for where to find an endpoint's logic.