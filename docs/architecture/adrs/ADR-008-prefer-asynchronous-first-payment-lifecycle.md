# ADR-008 — Prefer asynchronous-first payment lifecycle over synchronous finalization

**Status:** <span style="color:green">Accepted</span>

## Context

External providers may return:

* immediate success,
* pending result,
* requires_action,
* delayed confirmation via webhook,
* eventual failure.

Trying to force a fully synchronous API model leads to brittle assumptions and poor fit for real providers.

## Decision

Design the public payment flow as **asynchronous-first**.

Merchant API creates a payment intent / payment request and returns a stable internal identifier with current status.
Finalization may happen later via provider callback and workflow continuation.

The platform remains able to expose current status through read APIs and merchant callbacks.

## Alternatives considered

### Alternative A — Synchronous-only payment completion

Pros:

* simpler client expectations,
* easier demo flow.

Cons:

* unrealistic for many providers,
* poor fit for webhook-driven payment confirmation,
* encourages blocking calls and weak retry behavior.

### Alternative B — Polling-only internal model with no callback support

Pros:

* simpler outbound delivery model.

Cons:

* worse merchant integration experience,
* weaker event-driven architecture,
* less realistic for platform integration use cases.

## Consequences

Positive:

* better fit for real payment provider behavior,
* stronger resilience to delayed external confirmation,
* cleaner orchestration model.

Negative:

* more status complexity,
* clients must understand pending/intermediate states,
* requires stronger observability.
