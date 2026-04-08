---
name: implement-task
description: Implement a task from docs/tasks/epics.md. Use when the user provides a task ID like TASK-043 or says to implement a task.
---

# Implement Task

Implement the task specified by the argument (e.g. `TASK-043`).

## Step 1 — Read and understand the task

- Read `docs/tasks/epics.md` to confirm the task belongs to the current epic and is not yet completed.
- Read the task file at `docs/tasks/<epic-folder>/<TASK-ID>.md`.
- Understand the full scope: readiness criteria, constraints, and how this task fits into the surrounding architecture.
- Read any files that are directly relevant to the implementation (related controllers, use cases, models, routes, tests, migrations). Do not guess — read first.

## Step 2 — Plan before coding

Before writing any code, reason through:
- What files need to be created or modified?
- What are the domain boundaries? Does anything cross a service boundary that shouldn't?
- What are the failure modes — what can go wrong, and what should happen when it does?
- Are there security implications (input validation, authorization, data exposure, injection)?
- Does this touch an existing state machine, outbox, or idempotency mechanism?

Only proceed once the approach is clear.

## Step 3 — Implement thoroughly

Follow these standards without exception:

### Architecture and boundaries
- Never read another service's database. Service boundaries are enforced through APIs and messages only.
- Never publish to a queue directly from domain logic — always use the outbox pattern.
- Never add long-running logic outside Temporal workflows/activities.
- Propagate `correlation_id` and `causation_id` through HTTP headers and message metadata.

### Code quality
- Follow the existing internal layout: `Domain/`, `Application/`, `Infrastructure/`, `Interfaces/`.
- Match the style and conventions of the surrounding code — naming, return types, docblocks where the existing code uses them.
- Do not add comments unless the logic is genuinely non-obvious.
- Do not add features, refactors, or "improvements" beyond the task scope.

### Security
- Validate all input at system boundaries (HTTP requests, queue messages). Never trust caller-supplied data.
- Scope every query to the authenticated merchant — no cross-merchant data exposure.
- Return 404 (not 403) when a resource exists but belongs to another merchant — no existence leak.
- Never log `payment_id` together with raw card data or tokens.
- Sanitize any data included in error responses — never leak internal state.

### Fail-safe logic
- Return safe defaults on missing optional data (e.g. `null` for `failure_reason` when no failure exists).
- Use database transactions for any operation that writes to multiple tables.
- Prefer explicit `null` checks over truthy checks when a value can legitimately be `0` or `""`.
- On unexpected states, fail loudly in dev (exceptions) and return structured error responses in HTTP.

## Step 4 — Write or update tests

Every implementation must be covered by tests. No exceptions.

- **Feature/integration tests** are the default — test the full HTTP request-to-response cycle (or queue message in/out).
- **Unit tests** for pure domain logic or complex algorithms that benefit from isolated testing.
- Cover the happy path **and** all meaningful edge cases:
  - Missing or invalid input (expect 422 / validation errors)
  - Unauthorized access (expect 401)
  - Cross-merchant isolation (expect 404, not 403)
  - Not-found cases (expect 404)
  - Idempotency / duplicate requests where applicable
  - Boundary values (empty strings, zero amounts, max-length fields)
- Tests must be deterministic — no reliance on wall-clock time or random data without seeding/mocking.
- Run the test suite for the affected service(s) before declaring done:
  ```bash
  # From apps/<service-name>/
  php artisan test
  ```
  All tests must pass. Fix failures before moving on.

## Step 5 — Update Postman collection

If any HTTP endpoint was added or changed:
- Update the Postman collection at `apps/<service-name>/docs/<service-name>.postman_collection.json`.
- Add a request entry for the happy path with an example 2xx response body.
- Add negative-case entries (4xx) matching the actual error shapes returned by the code.
- Keep variable references consistent (`{{base_url}}`, `{{payment_id}}`, `{{api_key}}`, etc.).

## Step 6 — Mark the task complete

- Open `docs/tasks/epics.md` and change `[ ]` to `[x]` for this task.
- Append a `## Result` section to the bottom of the task file with a concise summary of what was implemented: key files created/modified, design decisions made, and any deviations from the original task spec (with justification).