# With love from Codex, for Opus

## Where we stopped

Branch: `fix/trace-act-fact-brackets`

This branch fixes a false async gap in DDDash trace projection. It deliberately does **not** implement the next geometry idea described below.

## The observed bug

In correlation `c9b21b68-35b4-4550-86d6-f2feb604d24c`:

- `VerifyCredentialEvidence` started at `11:21:02`, took `1153ms`, and recorded `ended_at = 11:21:04`.
- Its directly emitted `credential_evidence_verified` outbox fact was created at `11:21:04` and carries the command's ID.
- The trace drew `+5m59s` at the command, then a separate `+6m01s` async marker at the fact.

That two-second marker was false. Command audit timestamps have whole-second precision while duration uses `microtime()`, and the fact is written inside the same transaction/act bracket. The actual async boundary is between that fact and a later subscriber command, not between the command and the fact it raised.

## The fix

- `TraceFragmentReader` now selects the existing `command_audit.ended_at` column.
- `TraceTimelinePresenter` derives each command's activity end from `ended_at`.
- A directly raised fact inherits its parent command's internal layout bracket.
- Sparse-clock gaps are measured from the end of prior activity, not merely the prior node's start.
- Continuing the same bracket never creates an async marker.
- A subscriber command remains a new bracket, so its real transport wait is still shown.
- The bracket and end timestamp are projection-only fields and are stripped from the API response. No schema migration is involved.

Regression coverage uses the exact `11:21:02 -> 11:21:04` shape and separately proves that a subscriber at `11:21:34` retains a `30s` gap.

## Verification

- Full PHPUnit on the final branch state: `571 tests, 1912 assertions`, passing with 9 existing PHPUnit deprecations.
- The exact DDEV trace was opened after correcting the local loader. The false `+6m 1s` marker disappeared; later genuine markers remained.
- Screenshot: `/Users/titustc/tgbl/anything/.playwright-cli/trace-fix-verified.png`.

The repository-wide dead-code PHPStan task is currently noisy with more than 1000 pre-existing missing-consumer/WordPress symbols and public entrypoint reports. Focused PHP lint and `git diff --check` are clean.

## Local DDEV trap

`wp-content/mu-plugins/tangible-ddd-v2-worktree.php` was force-loading the stale detached worktree `.worktrees/tangible-ddd-dddash-v2` at `238c1cd`, so edits in the real plugin clone were invisible at runtime. The local MU loader now loads:

`wp-content/plugins/tangible-ddd/tangible-ddd.php`

That loader change is outside this plugin repository and therefore is not part of this branch.

## Open visualization question

Titus is reconsidering the trace's basic geometry. An integration fact is currently drawn as an equal-sized little rectangle even though it has no measured duration. A more truthful unit may be a composite **act block**:

- The command rectangle is the measured envelope.
- Synchronous domain moments and their listener reactions live inside that envelope as ordered marks or bands.
- Direct integration facts attach to its edge as output ports/pips and remain clickable causal fan-out anchors.
- The emitted fact stays a distinct graph node; only its geometry is grouped with the act that atomically produced it.
- Long processes/trajectories remain separate spanning objects rather than being collapsed into the act.

The existing evidence supports this. Middleware order is `Correlation -> Transaction -> DomainEventsPublish -> handler`; command duration therefore includes the handler, synchronous domain-event draining/listeners, secondary domain moments, and outbox writes. Command audit records the ordered domain-event names, but it does **not** record individual listener identities or timings. Do not draw time-scaled listener segments without new instrumentation.

For the current sidecar `VerifyCredentialEvidence`, there is no domain moment: the likely future shape is simply a measured command body plus an attached fact output.

## Next moves

1. Review and land this timing correction independently.
2. Decide the composite act-block visual grammar before changing JS/CSS.
3. If richer moment detail is wanted later, instrument total dispatch around `EventRouter`/`WordPressEventDispatcher`; individual WordPress callback timing would be substantially more invasive.
4. Update the temporal-layout design notes after the geometry is agreed.
