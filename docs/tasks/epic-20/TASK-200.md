### TASK-200 — Prepare Kubernetes manifests for key services

#### Services
- merchant-api
- webhook-ingest
- payment-orchestrator
- payment-domain
- reporting-projection

#### For each service
- Deployment
- Service
- Config/Secret model
- readiness/liveness probes
- resource requests/limits

#### Done criteria
- independent deployment can be demonstrated;
- manifests are consistent with the local Docker model.