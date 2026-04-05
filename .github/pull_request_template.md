## Summary

<!-- Describe what this PR does and why. -->

## Changes

<!-- Bullet-point the key changes. -->

## Test plan

<!-- How was this tested? -->

## Checklist

- [ ] Tests pass locally.
- [ ] No sensitive values (tokens, raw card data) added to logs.
- [ ] If a new Kafka event schema was added, a valid fixture exists in `contracts/json-schemas/fixtures/`.
- [ ] If a Kafka schema changed, classified as breaking or non-breaking per ADR-012. If breaking, new topic version created and co-existence plan documented.