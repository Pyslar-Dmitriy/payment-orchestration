# TASK-011 — Prepare a Laravel service template

### For each service, create the same internal structure:
- `Domain/`
- `Application/`
- `Infrastructure/`
- `Interfaces/`
- `docs/`
- `tests/`
- `.github/`

### Add:
- health endpoint;
- readiness endpoint;
- basic logger;
- middleware correlation id;
- basic exception handling;
- Dockerfile;
- .env.example.

## Artifacts
- template structure on one service;
- then replicate to others.

## Readiness Criteria
- Every new service is created using the same template;
- identical entry points and standards;
- health check is running.