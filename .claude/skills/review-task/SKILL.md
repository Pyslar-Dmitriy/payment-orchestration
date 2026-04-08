---
name: review-task
description: Review the implementation of a completed task. Checks code quality, completeness against the task spec, security, tests, and runs Laravel Pint. Use when the user asks to review a task like TASK-043.
---

# Review Task

Review the implementation of the task specified by the argument (e.g. `TASK-043`).

## Step 1 — Load the task spec

- Read `docs/tasks/epics.md` to find the task.
- Read the task file at `docs/tasks/<epic-folder>/<TASK-ID>.md`.
- Note every readiness criterion — each one must be verified against the actual code.

## Step 2 — Locate all implementation files

Find every file that was added or modified for this task. Look in both affected services (`apps/merchant-api/`, `apps/payment-domain/`, etc.):
- Controllers (`Interfaces/Http/Controllers/`)
- Use cases (`Application/`)
- Domain models and value objects (`Domain/`)
- Infrastructure adapters (`Infrastructure/`)
- Routes (`routes/`)
- Migrations (`database/migrations/`)
- Tests (`tests/`)
- Postman collection (`docs/*.postman_collection.json`)

Read all of them before forming any opinion.

## Step 3 — Review against the task spec

For each readiness criterion in the task file, confirm it is actually satisfied by the code. Flag any criterion that is partially implemented, missing, or implemented differently than specified.

## Step 4 — Review code quality

Check each implementation file for:

### Correctness
- Logic matches the intended behaviour — no off-by-one errors, wrong comparisons, or silent data loss.
- All database writes that span multiple tables are wrapped in a transaction.
- No race conditions on shared state (e.g. missing locks where concurrent requests could corrupt data).

### Security
- All input is validated at system boundaries (HTTP request, queue message). No raw caller-supplied data reaches domain logic unvalidated.
- Every query is scoped to the authenticated merchant — no cross-merchant data exposure.
- 404 is returned (not 403) when a resource belongs to another merchant — no existence leak.
- No sensitive values (tokens, raw card references) appear in logs or error responses.
- No SQL injection, mass-assignment, or command-injection vulnerabilities.

### Fail-safe logic
- Missing optional data returns safe defaults (e.g. `null`), not exceptions.
- Error responses are structured and do not leak internal state or stack traces.
- Explicit `null` checks used where a value can legitimately be `0` or `""`.

### Architecture boundaries
- No cross-service database reads.
- No direct queue publishes from domain logic (outbox only).
- `correlation_id` / `causation_id` propagated where required.
- Code placed in the correct layer (`Domain/`, `Application/`, `Infrastructure/`, `Interfaces/`).

### Code style
- Naming is consistent with the surrounding codebase.
- No dead code, commented-out blocks, or unused imports/variables.
- No unnecessary abstractions or premature generalisations.

## Step 5 — Review tests

- Every happy-path scenario has a test.
- Edge cases are covered: invalid input (422), unauthorised (401), not-found (404), cross-merchant isolation (404), boundary values.
- Tests are deterministic — no dependency on wall-clock time or unseeded random data.
- Test assertions are specific — not just `assertStatus(200)` without checking the response body.
- No test logic that would pass even if the feature were broken (e.g. asserting on a value you just hardcoded into the fixture).

## Step 6 — Check Postman collections

If HTTP endpoints were added or changed, check **every affected service** — both public-facing (e.g. merchant-api) and internal (e.g. payment-domain). Each service has its own collection at `apps/<service-name>/docs/<service-name>.postman_collection.json`.

For each collection:
- Confirm a request entry exists for the new/changed endpoint.
- Verify the URL, method, headers, and body match what the code actually expects.
- Verify example response bodies match the actual shapes returned by the code.
- Confirm any new path/query variables are declared in the collection `variable` array.

## Step 7 — Run Laravel Pint and tests

Run the code style fixer for every affected service via Docker (do NOT run it directly on the host — Pint must execute inside the container where the correct PHP version and vendor dependencies are available):

```bash
docker compose -f infra/docker/docker-compose.yml --env-file infra/docker/.env \
  -f infra/docker/docker-compose.override.yml \
  exec <service-name> ./vendor/bin/pint
```

If Pint makes changes, report exactly which files were reformatted. These changes must be committed alongside the implementation.

Re-run the test suite after Pint to confirm formatting changes did not break anything. Use the Makefile target from the repo root (it clears the route cache first, which is required — running `php artisan test` directly inside the container without clearing cache can produce false 404s for newly added routes):

```bash
make test SERVICE=<service-name>
```

## Step 8 — Report findings

Produce a structured report:

```
### Readiness criteria
[x] criterion 1 — satisfied
[ ] criterion 2 — NOT satisfied: <explanation>

### Issues found
- [BLOCKER] <file:line> — <description>
- [WARNING] <file:line> — <description>
- [STYLE]   <file:line> — <description>

### Pint
<"No changes." or list of reformatted files>

### Verdict
PASS / FAIL — <one-sentence summary>
```

Only mark the verdict as PASS if there are zero blockers and all readiness criteria are satisfied. Blockers must be fixed before the task can be considered done.