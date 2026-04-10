# ADR-015 — Use HTTP status code constants instead of plain integers

**Status:** <span style="color:green">Accepted</span>

## Context

HTTP response codes appear in controllers whenever a response is built. They can be expressed as:

* Plain integer literals: `response()->json($data, 201)` — concise but opaque at a glance.
* Named constants from `Symfony\Component\HttpFoundation\Response`: `response()->json($data, Response::HTTP_CREATED)` — verbose but self-documenting.

Plain integers scatter unnamed "magic numbers" across the codebase. When a developer sees `422`, they must recall or look up the meaning. When they see `Response::HTTP_UNPROCESSABLE_ENTITY`, the intent is unambiguous without any context switch.

Laravel ships with Symfony's `HttpFoundation` component, so `Symfony\Component\HttpFoundation\Response` (aliased as `Illuminate\Http\Response`) is always available — no additional dependency is required.

## Decision

Every HTTP status code in controller classes must use a named constant from `Symfony\Component\HttpFoundation\Response` rather than a plain integer literal.

Conventions:

* Import `Symfony\Component\HttpFoundation\Response` at the top of each controller that uses status codes.
* Use `Response::HTTP_OK` (200), `Response::HTTP_CREATED` (201), `Response::HTTP_NOT_FOUND` (404), `Response::HTTP_UNPROCESSABLE_ENTITY` (422), `Response::HTTP_SERVICE_UNAVAILABLE` (503), etc.
* The rule applies to all service codebases; migration is done service by service as controllers are touched.

```php
use Symfony\Component\HttpFoundation\Response;

// Bad
return response()->json($data, 201);

// Good
return response()->json($data, Response::HTTP_CREATED);
```

## Alternatives considered

### Alternative A — Keep plain integers

Pros:
* Shorter code; developers familiar with HTTP know the codes.

Cons:
* Magic numbers — intent requires mental lookup.
* Typos (`210`, `412`) fail silently at runtime.
* Code review diffs show numbers with no semantic label.

### Alternative B — Custom enum or constant class

Pros:
* Could narrow the set to only codes actually used in the platform.

Cons:
* Reinvents a well-maintained, battle-tested standard library.
* Additional file to maintain with no benefit over Symfony's `Response`.

## Consequences

Positive:
* Status codes are self-documenting at the call site.
* IDEs autocomplete constants, reducing typo risk.
* Consistent import pattern across all controllers.

Negative:
* Slightly more verbose import line and call site.
* Developers must update existing controllers when they touch them (no big-bang rewrite needed — apply progressively).