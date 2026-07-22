# Trace Temporal Fidelity Design

**Status:** Approved
**Scope:** DDDash V2 trace presentation and the development-only Mega Trace sidecar
**Date:** 2026-07-22

## Problem

The Mega Trace records authentic command durations, but most synthetic commands
only publish a fact and therefore complete in 3-5ms. The trace proves causality
without producing enough execution-time variation to exercise its duration
display.

The compressed trace also labels each asynchronous edge with that edge's local
wait. Successive minute-scale Action Scheduler resumptions consequently read
`1m`, `1m`, `1m` rather than showing how far the story has travelled from its
beginning. A future trace may be active for three minutes, remain dormant for
two days, and then resume. The viewer must report that wall-clock truth without
allocating horizontal space or synthetic ticks to the inactive two days.

Finally, the gap-marker overlay currently has a higher stacking level than the
sticky left label column. When the trace is scrolled horizontally, markers and
bars remain visible while passing beneath that column.

## Decisions

### Measured synthetic work

Selected Mega Trace commands perform fixed, deterministic workloads between
100ms and 1.2s before publishing their fact. The shared sidecar command base
owns the sleeping mechanism; selected concrete commands declare their workload
duration. Commands not selected retain their natural runtime.

The initial profile is:

| Synthetic act | Workload |
| --- | ---: |
| `PersonalizeLearningPath` | 180ms |
| `AnalyzeDiagnosticSignals` | 460ms |
| `VerifyCredentialEvidence` | 1,150ms |
| `PackageCredentialEvidence` | 820ms |
| `QueueCredentialNotification` | 140ms |
| `CommitRegistryDelivery` | 360ms |

Cred routine work items use a deterministic item-specific profile: identity
`120ms`, assessment `260ms`, completion `390ms`, certificate `520ms`,
transcript `650ms`, and badge `180ms`. This exercises the Behaviour Workflow
lane rather than making only ordinary commands slower.

The delay occurs inside the real command invocation. `CorrelationMiddleware`
therefore measures it and writes the actual elapsed `duration_ms`; no audit row,
query result, or rendered width is fabricated. The sidecar remains explicitly
development-only, so deliberately occupying a worker and transaction for at
most 1.2s is acceptable. No framework consumer acquires this behavior.

### Sparse cumulative time

`TraceTimelinePresenter` adds `elapsed_s` to each presented node, measured from
the earliest resolved node in the trace. Causal traversal determines row order
and depth only. Horizontal positions come from one sparse global clock ordered
by the actual activity timestamps across every participating branch.

The presenter emits one time marker per distinct activity moment that follows a
wall-clock gap of at least two seconds. Nodes recorded at the same moment share
that marker, so parallel branches cannot overwrite one another or make elapsed
time move backwards from left to right. Its primary label is cumulative elapsed
time:

- `+1m`
- `+2m`
- `+3m`
- `+2d 3m`

The formatter uses compact compound units for longer spans and never generates
regular wall-clock ticks. A trace with a two-day dormant period therefore has
one break followed by the waking node, not 2,880 empty minute positions.

For an exceptional global gap of at least five minutes, the same marker also
names the compressed hiatus, for example `2d gap`. This is evidence language:
it says that the retained trace contains no recorded activity between those
moments, not that the application or machine was idle.

All gaps continue to occupy a bounded visual width independent of their real
duration. Command bars remain proportional to measured execution duration.
The trace header continues to show total real wall-clock elapsed time.

### Sticky-column masking

The sticky ruler label and every sticky trace label sit above the timeline and
gap overlays and retain opaque backgrounds. Bars, dashed gap markers, and their
labels disappear underneath the first column during horizontal scrolling.

The marker overlay's column geometry must follow the responsive trace geometry:
320px normally and 250px at the existing narrow breakpoint. This keeps the
mask boundary aligned on desktop and mobile widths.

### Branding

The Prime Radiant label belongs only to the temporary brainstorming companion.
No Prime Radiant wording, logo, styling, or other branding enters Tangible DDD,
DDDash, or the Mega Trace sidecar.

## Tests

- A Mega Trace workload test pins the selected command durations, the routine
  item range, and the 1.2s ceiling without sleeping for the full profile.
- Presenter tests cover successive minute gaps as cumulative `+1m`, `+2m`,
  `+3m` offsets, a single two-day compressed gap followed by `+2d 3m`, and
  parallel branches whose causal depth order differs from timestamp order.
- Dashboard artifact tests pin cumulative formatting, exceptional-gap labeling,
  sticky-column stacking order, and responsive overlay-column alignment.
- The full PHPUnit suite and JavaScript syntax check remain required.
- The running DDEV dashboard is visually checked at desktop and narrow widths,
  including horizontal scrolling beneath the sticky column.

## Non-Goals

- Changing Action Scheduler cadence or the scenario's external boundary delays.
- Random or machine-dependent busy work.
- Fabricating command audit durations.
- Expanding long idle periods proportionally on the X-axis.
- Producing empty periodic ticks where no retained trace activity occurred.
- Renaming or rebranding any Tangible product.
