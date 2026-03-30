# TASK-013 — Configure path-based CI in GitHub Actions

### Organize CI so that changed services are tested and built independently.
Requires implementing:
- workflow lint;
- workflow unit tests;
- workflow service build;
- workflow contracts validation;
- path filters;
- image tagging by commit SHA.

## Artifacts
- `.github/workflows/lint.yml`
- `.github/workflows/service-ci.yml`
- `.github/workflows/docker-build.yml`
- `.github/workflows/contract-check.yml`

## Readiness Criteria
- one service changed → only it is built;
- contracts changed → dependent services are checked;
- CI doesn't run everything without reason.