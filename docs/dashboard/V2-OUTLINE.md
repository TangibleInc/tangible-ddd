# DDDash v2 — Working Product Scope

**Recorded:** 2026-07-20

**Status:** implemented and verified in the v2 source on 2026-07-22

V2 preserves the complete v1 operational dashboard and adds two product
capabilities plus a consumer-provenance visual language. It does not reinterpret
the public UI through the framework's internal Act / Fact / Trajectory ontology.

## 1. Unified trace

- Trace discovery and recent-trace lists remain scoped by the existing consumer
  selector.
- Once an exact correlation is opened, the trace automatically queries every
  registered consumer that participated in it. The selected consumer is the
  entry point, not the boundary of the resulting trace.
- Commands, integration events, and long processes are assembled from their
  consumer-owned tables and stitched into one causal graph.
- Exact cross-consumer causation handoffs are shown.
- A recorded parent that cannot be resolved remains visible as an unresolved
  stub or warning; it must not be silently promoted to a root.
- Trace-node identity includes consumer, node kind, and local id so independent
  consumer tables cannot collide.

There is no central correlation registry, correlation minter, or correlations
table. Correlation identity remains coordination-free and trace participation is
derived from the existing consumer-owned records.

## 2. Aggregate biography

- Biography is consumer-scoped because the dashboard already has a consumer
  selector and aggregate ownership belongs to a consumer.
- An aggregate is addressed by canonical aggregate name and id.
- Its retained history is read from the consumer's `touches` table.
- Biography entries link back to their trace, command, and published fact where
  those records are still available.
- Biography is operational and retention-bounded. It is not an event store or a
  permanent archival promise.

## 3. Consumer provenance

Each consumer has a stable read-side accent colour. In a unified trace it is
used for:

- a thin ownership rail on trace rows;
- a swatch in the participant list and trace header;
- a two-consumer marker at exact cross-consumer handoffs;
- provenance in node detail drawers.

Node-kind styling continues to distinguish commands, events, and processes.
Failure red remains reserved for failure. Consumer accents do not recolour whole
timeline bars. Participant controls may dim other consumers but never remove
causal topology.

Accent metadata belongs to the dashboard's consumer definition, with an
explicit consumer override and a stable curated fallback derived from the
consumer key. It is not trace-context or persisted domain data.

## V1 behavior retained

- Command Audit, Flow, Trace, Processes, Behaviour Workflows, Outbox, and DLQ.
- Existing retry, replay, discard, purge, filtering, pagination, drawers, and
  heartbeat behavior.
- The "how far back" plus "window length" time selector.
- Consumer-owned operational tables and the existing consumer selector.

## Supporting structure

The former single-consumer `TraceQuery` is separated into:

1. a per-consumer trace-fragment reader;
2. a host-neutral tracer that gathers participating consumers;
3. a pure trace stitcher;
4. a dashboard-specific timeline presenter/layout.

`ConsumerDefinition` distinguishes consumer key, display label, storage prefix,
table location, accent, and live/ghost capability. These are enabling refactors,
not additional user-facing features.

## Implemented structure

- `TraceFragmentReader` reads one consumer's audit, outbox, long-process,
  workflow, and touch fragments.
- `UnifiedTraceQuery` fans an exact correlation out across the registered
  consumer catalogue.
- `TraceStitcher` joins only recorded command, fact, process-ignition, and
  process-resumption edges. Consumer/kind/local-id UIDs prevent table-local ID
  collisions.
- `TraceTimelinePresenter` produces the dashboard waterfall while preserving
  unresolved and ambiguous recorded parents as explicit evidence.
- `BiographyQuery` provides the consumer-scoped aggregate finder and retained
  version timeline from `touches`, with optional audit/outbox joins by recorded
  IDs.

Trace drawers link exact touch records back to Biography, and Biography entries
link to the correlation that recorded that version. A Biography may therefore
span several correlations; the UI does not merge those correlations into an
invented story.

The development mega-trace sidecar exercised four real host consumers in DDEV.
One completed sample produced 32 command records, 30 integration facts, four
long processes, one Behaviour Workflow, 13 exact cross-consumer handoffs, and
no unresolved parent warnings. See `tools/mega-trace/README.md` for the fixture
and local installation contract.

## Explicitly outside v2

- A global "All consumers" operational browser.
- A second Behaviour Workflow view.
- Public UI built around Act / Fact / Trajectory terminology; occasional labels
  remain acceptable where they clarify rather than teach the ontology.
- Centralized correlation minting or a central correlations table.
- Permanent trace archival or a Grafana/Tempo export product.
- Story-intersection persistence or a `process_arrivals` ledger. The encounter
  concept is shelved separately in `docs/0.3-trace-context.md`; it can be added
  later if a concrete forensic or product need justifies recording wake links.
