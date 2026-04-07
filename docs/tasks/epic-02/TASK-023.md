# TASK-023 — Restrict internal services to the private Docker network

### Problem

Currently `docker-compose.yml` and `nginx.conf` expose every service port to the host:

```
8001 → merchant-api      (public — correct)
8002 → payment-domain    (internal — should be private)
8003 → payment-orchestrator
8004 → provider-gateway
8005 → webhook-ingest    (public — correct)
8006 → webhook-normalizer
8007 → ledger-service
8008 → merchant-callback-delivery
8009 → reporting-projection
```

Any process on the developer's host (or a misconfigured firewall) can reach payment-domain, ledger-service, etc. directly, bypassing all auth and network policies. This does not reflect production topology where internal services are inside a private VPC subnet.

### What to do

1. **docker-compose.yml** — remove `ports:` mappings from all internal services. Only the following should remain host-accessible:
   - `8001` — merchant-api (merchant-facing public API)
   - `8005` — webhook-ingest (receives callbacks from external PSPs)
   - Management/observability ports (RabbitMQ UI `:15672`, Temporal UI `:8080`, Grafana `:3000`, Prometheus `:9090`) may stay for local dev convenience.

2. **nginx.conf** — remove server blocks for internal services. Internal service-to-service calls go directly over `payment-net` by container hostname (e.g. `http://payment-domain:9000`), not through nginx.

3. **`PAYMENT_DOMAIN_URL`** (and equivalent env vars in other services) — update `.env.example` files to use the internal container hostname, e.g. `http://payment-domain/`.

### Readiness criteria

- `curl http://localhost:8002/...` from the host returns connection refused.
- `docker compose exec merchant-api curl http://payment-domain/health` succeeds.
- All existing feature tests still pass.