# Tangible DDD

A WordPress framework for domain-driven plugins: commands, events, an
integration outbox, long-running processes, and a trace that makes every unit
of work accountable. Decisions behind these terms live in
[docs/adr/](docs/adr/); rules that use them live in
[docs/modeling-rules.md](docs/modeling-rules.md).

The first sections are the public vocabulary: use them in consumer-facing
docs, code and reviews. The last section is the trace's own vocabulary, used
inside the framework, its trace and its dashboard; consumer-facing docs use it
only when explaining the `Kind` API or a causal-storage field
([ADR 0013](docs/adr/0013-public-docs-speak-conventional-terms.md)).

## Integration events

**Self-publisher**:
A born-scalar domain event that announces itself as its own integration event.

**Twin**:
The scalar `Integration\` record a rich domain event returns from `to_integration()`; derived, never raised.

**Raising lane**:
One of the three ways an event is raised: aggregate diary, handler-level raise, direct bus.

**Seal**:
The drain-time state of the events unit of work, in which only integration announcements may be recorded.

**IntegrationListener**:
A stateless policy: an integration event in, a command (or `null`) out. It makes no decisions and does no work.
_Avoid_: fat handler, async handler

**IntegrationEnvelope**:
The transport wrapper an integration event rides in through the outbox.
_Avoid_: FactEnvelope

**Replay**:
Re-delivering an integration event's outbox row. The remedy for a lost or unheard event.
_Avoid_: splice

## Processes

**Long-running process**:
A `LongProcess`: a named lifecycle that sequences commands across time, recorded in `long_processes`.

**Ignition**:
Starting a long-running process from an integration event via `#[StartsOn]`.
_Avoid_: spawn

**AwaitAll**:
A durable fan-in await on a set of expected integration events, with a required wall-clock timeout.
_Avoid_: Gatherable

## Outbox

**Hold**:
A relay-lifecycle pause `{holder, selector, until}` that keeps matching rows out of the feed without changing them.
_Avoid_: paused (as a row status)

## Write accounting

**Touches**:
Declared write-set entries, `#[Touches(Op, Aggregate, id)]`, on the record that is actually published.
_Avoid_: Declares, Writes, Changes, Lifecycle

**Biography**:
The per-aggregate view over touches rows: everything that ever wrote to one aggregate. A view name; the API stays Touches and Footprint.

**Footprint**:
The canonical-name-keyed write manifest of one command execution.

**Verdict**:
The scalar or DTO a self-handling command may return, used only to steer its transport.

**Receipt**:
The shelved `{verdict, footprint}` value a command execution could return. Not built.
_Avoid_: CommandReceipt, ActReceipt, Outcome

## Wiring

**Consumer**:
A plugin that owns its own set of DDD tables through its `IDDDConfig` prefix.

**Ledger**:
The per-version list of what each release demands of consumers: [docs/migration-0.2-to-0.3.md](docs/migration-0.2-to-0.3.md).

## Trace vocabulary (framework-internal)

**Kind**:
The closed set of trace node types: Act, Fact, Trajectory. A fourth kind is a ruling, never drift.
_Avoid_: work kind, node type

**Act**:
One command execution as the trace sees it: the atomic, audited unit of work, recorded in `command_audit`.
_Avoid_: work record, span

**Fact**:
An integration event once it has been published: recorded, immutable, part of a story.
_Avoid_: message, broadcast

**Moment**:
A domain event that never publishes; an event that never became a fact.

**Trajectory**:
A long-running process as the trace sees it: a lifecycle that leaps between Acts across time.
_Avoid_: work

**Overlay**:
Something drawn over a trajectory without being a kind of its own; a BehaviourWorkflow is an overlay.

**Story**:
Everything sharing one `correlation_id`. A prose and ops word only; support says "case".
_Avoid_: StoryContext, journey

**Cause**:
The `(id, kind)` pair of the node currently executing, which every node born inside it records as its parent.
_Avoid_: Span

**Agent**:
Who started a story. An annotation on the root (`source`/`source_id`), never a node.

**Bracket**:
One of the three scope mechanisms (act, drain, wake) that set the Cause for work done inside them.

**TraceContext**:
The immutable propagated value `{correlation_id, ?Cause, sequence}`; named letter-for-letter after the W3C standard.
_Avoid_: TracedContext, TraceSlice

**Correlation**:
The runtime facade: static access, scope brackets, the sequence counter. Write-side word.
_Avoid_: CorrelationContext (the retired god-class), Journey

**Trace**:
The assembled read-side artifact: causation tree plus warnings.

**Tracer**:
The read service that assembles a Trace from the projections.
_Avoid_: CorrelationTimeline
