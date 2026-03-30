# ADR-007 — Use a dedicated append-only ledger service instead of mutable balances inside payment service

**Status:** <span style="color:green">Accepted</span>

## Context

Payments are not only about status transitions. The platform also needs an internal financial truth for:

* successful captures,
* refunds,
* fees,
* future reconciliation.

A simplistic model that stores only mutable balances or payment status is insufficient for auditability and financial correctness.

## Decision

Create a dedicated **ledger-service** that stores append-only financial records using a ledger model.

The ledger will:

* store immutable transactions and entries,
* support idempotent posting,
* keep payment and refund financial history,
* act as the financial audit source of truth inside the platform.

The payment-domain service will own business state of the payment.
The ledger-service will own financial records.

## Alternatives considered

### Alternative A — Keep a balance column in payment-domain

Pros:

* simpler implementation,
* fewer services.

Cons:

* poor auditability,
* weak fit for refunds/fees/reconciliation,
* not representative of serious payment systems.

### Alternative B — Put ledger tables in payment-domain database

Pros:

* fewer moving parts.

Cons:

* mixes business lifecycle logic with financial accounting concerns,
* weakens service boundaries,
* reduces clarity in architectural storytelling.

## Consequences

Positive:

* stronger financial correctness story,
* clearer bounded context,
* better interview and portfolio value,
* easier future extension to reconciliation and payouts.

Negative:

* added complexity,
* need for idempotent posting and event coordination,
* requires thoughtful schema design.

Architectural note:
The ledger is not introduced for scale alone. It is introduced for **financial truth, auditability, and correctness**.
