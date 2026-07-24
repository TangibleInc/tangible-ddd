# Eventing lanes

**Status: CURRENT** (0.6.x, eventing hardening batch). The doctrine for where
an event may be raised, and the fences that keep each lane honest.

One rule generates everything else: **raise it on whom it happened to.**

## The three raising lanes

| # | Lane | Verb | Who | Fence |
| --- | --- | --- | --- | --- |
| 1 | Aggregate diary | `$this->event()` inside the aggregate (`RecordsDomainEvents`) | The thing it happened to | `PersistsAggregatesRepository::save()` is `final`; `pull_events()` is framework-only (`pull_events_violations`) |
| 2 | Handler-level raise | `$this->event()` via the `RaisesEvents` trait | A coordinator (command handler, `WorkflowHandler`) | `handler_raised_events()` — every call site allowlisted, i.e. reviewed |
| 3 | Direct bus | `IIntegrationEventBus::publish()` | Transport fan-out only | Trajectory guard in the bus; the outbox row carries the raiser edge (`command_id`) |

### Lane 1 — the aggregate diary (the default)

Facts about an aggregate's state belong on the aggregate. Raise them there;
the repository harvests on `save()` (persist, then `collect_from`) and the
publish middleware routes them after the handler returns. This is why
`save()` is `final`: persist-then-collect is the seal's ground truth, and an
overriding subclass is the one move that silently kills every downstream
hook.

`pull_events()` is the framework's **harvest verb** — its only caller is
`EventsUnitOfWork::collect_from()`. Consumer code must never call it. To
clear a diary during **reconstitution** (loading must not re-raise stored
moments), use the intention-revealing alias `discard_events()`: it clears
and returns nothing, so there is nothing to smuggle past the unit of work.
`IntegrationConformance::pull_events_violations($src_dir)` is the CI fence.

### Lane 2 — the handler-level raise (the exception, blessed)

Some moments genuinely belong to the **act**, not to any aggregate: a
routine deciding to continue later, a process starting. For these, the
coordinator uses the `RaisesEvents` trait:

> Facts about an aggregate's state belong on the aggregate — raise them
> there and let the repository harvest. This lane is for coordination
> facts only (reschedules, process starts). If your event names something
> that happened to a thing with a repository, you are in the wrong place.

The trait delegates to the injected `EventsUnitOfWork` (the live,
container-managed instance — never a static facade; consumer-local facades
such as cred's `Events` are deprecated by this trait).
`IntegrationConformance::handler_raised_events($src_dir, $allowlist)` makes
every call site a conscious, reviewed decision in CI — the lane is enforced
where style facts belong (review time), not stamped into runtime data.

### Lane 3 — the direct bus (transport fan-out only)

`IIntegrationEventBus::publish()` is a **port**, not a raising lane. Its
charter: fan a fact out across a consistency/time boundary when there is no
domain moment to route it — the sanctioned command-less doors (`wp ddd
announce`, capture relays, migration backfills). Inside an act it still
works — the outbox row carries the act as its raiser (`command_id`), so the
fact docks on its act in the trace as a **momentless port**, the visual
signature of this lane. Saga steps can never use it (the bus throws
`FactPublishedInsideProcess`): steps sequence commands; handlers announce.

## Reschedule-via-fact: the blessed continuation pattern

A workflow that needs another pass does not set a raw Action Scheduler
alarm (that lane carries no causation — continuation passes render as
depth-0 orphans in the trace). It **announces a fact** and lets a thin
listener translate it into the next command:

- cred's `ProcessEndpointResponseBehaviourHandler::reschedule()` announces
  its rescheduled-fact; a paired `IntegrationListener` translates it into
  the next driving command.
- mega-trace's `IssuanceRoutine::reschedule()` mirrors it (the canonical
  in-repo shape): `IssuanceRoutineRescheduled` → `FleetPolicies` listener →
  `RunIssuanceRoutine`.

The outbox row carries the delay and the causation edge back to the pass
that decided to continue. **Process steps are own-context commands**: each
continuation pass is a fresh act with its own audit row, transaction, and
event scope — never a nested dispatch inside the current one.

## The WorkflowHandler phase-lock table

`WorkflowHandler::handle_workflow()` saves the workflow aggregate **before**
calling `reschedule()` (save at `WorkflowHandler.php:161`, reschedule at
`:164`). That ordering is the law behind this table:

| Phase | Lanes open | Why |
| --- | --- | --- |
| pass body (`execute_one`, `generate_work_items`) | aggregate diary **and** act lane | the driving act is open; repositories harvest normally |
| `reschedule()` | act lane only | the workflow was saved at `:161` — its diary is already harvested; anything raised on the aggregate now would dodge the seal. Announce the continuation fact (`$this->event()` on a `RaisesEvents` handler, or the consumer's announcing moment) |

## Replay, not splice

Lost or unheard fact? REPLAY the outbox row — never hand-splice a
correlation with `Correlation::within`; a borrowed correlation with no cause
is an anomaly, not a root. `Correlation`/`TraceContext` are boundary-adapter
internals, not consumer/ops vocabulary.

The delivered-to-nobody flag feeds this: an entry delivered with zero
registered listeners still completes (the contract is fire-and-forget), but
the framework fires `tangible_ddd_fact_delivered_unheard` (+ the
per-consumer `{prefix}_fact_delivered_unheard`) and notes
`delivered_unheard` on the row's `error_history`. The remedy for a fact that
fired into silence is to wire the listener and replay the row — the envelope
restores the story; no correlation surgery.
