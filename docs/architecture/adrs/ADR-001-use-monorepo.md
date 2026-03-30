# ADR-001 — Use a monorepo with independently deployable services

**Status:** <span style="color:green">Accepted</span>

## Context

The platform consists of multiple services:

* merchant-api
* payment-domain
* payment-orchestrator
* provider-gateway
* webhook-ingest
* webhook-normalizer
* ledger-service
* merchant-callback-delivery
* reporting-projection

The project is intended both as a realistic engineering system and as a learning/portfolio platform. The system needs:

* clear service boundaries,
* shared documentation,
* easy local setup,
* unified contracts,
* independent deployability and scaling.

A decision is needed between:

* monorepo,
* multi-repo.

## Decision

Use a **monorepo** that contains multiple **independently deployable services**.

Each service will have:

* its own directory under `apps/`,
* its own Dockerfile,
* its own environment configuration,
* its own CI path filters,
* its own deployable artifact,
* its own runtime scaling strategy.

The monorepo will also contain:

* contracts,
* infra manifests,
* architecture docs,
* shared primitive packages,
* test utilities.

## Alternatives considered

### Alternative A — Multi-repo per service

Pros:

* stronger repository-level ownership isolation,
* cleaner separation for large independent teams,
* separate release lifecycle by default.

Cons:

* harder local development and orchestration,
* contracts drift more easily,
* more setup overhead,
* less convenient for a single developer building a cohesive platform,
* more friction for cross-service refactors in early evolution.

### Alternative B — Single deployable monolith

Pros:

* lower operational complexity,
* faster initial delivery,
* easier debugging at first.

Cons:

* does not satisfy the architectural learning goal,
* hides service-boundary problems,
* does not demonstrate independent scaling patterns,
* weakens the portfolio value for microservices/highload-oriented interviews.

## Consequences

Positive:

* easier to keep all architecture, contracts, and infra in sync,
* simpler local setup with distributed stack,
* easier end-to-end testing,
* easier to document the platform as one system,
* still preserves independent deployment and scaling.

Negative:

* requires discipline to avoid distributed monolith patterns,
* shared packages can become a coupling trap,
* CI configuration must support path-based execution,
* repository can become noisy if conventions are weak.

Operational note:
Monorepo affects code organization only. It does not prevent scaling `webhook-ingest`, `payment-orchestrator`, or any other service independently at runtime.
