# TASK-012 — Set up shared packages without turning them into a distributed monolith

### Create minimal shared packages only for:
- Money value object;
- UUID helpers;
- Correlation/causation id;
- Shared event DTO/schema helpers;
- Test utilities.

### Do not share:
- Payment domain logic;
- Repositories;
- Use cases;
- Transition rules.

## Artifacts
- `packages/php/shared-primitives`
- `packages/php/contracts`
- `packages/php/testing-kit`

## Readiness Criteria
- Shared packages are small;
- No service depends on shared domain logic;
- Dependencies between services are not hidden within the shared package.