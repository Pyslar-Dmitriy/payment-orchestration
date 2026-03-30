### TASK-122 — Implement replay-friendly projection processing

#### What to do
Make projections replayable so that it is possible to:
- clear the read model;
- reread events;
- rebuild state.

#### Done criteria
- replay is documented;
- the rebuild process does not require manual tricks.