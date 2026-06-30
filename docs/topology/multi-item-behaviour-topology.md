# Multi-Item Behaviour Topology — plurality-aware behaviours & verdict maps

**Date:** 2026-06-30
**Status:** Design capture / architecture exploration. **Not in any build scope.**
**Spans:** tangible-ddd (framework primitives) + tangible-cred (the first real consumer).
**Companion:** `tangible-cred/docs/superpowers/specs/2026-06-30-multiboard-reporting-and-dashboard-harness-design.md`
(the multi-board saga + dashboard harness; its "Future design space" section lists the behaviour
*catalog*; this doc is the *execution architecture* behind it).

## Why this exists

tangible-cred's `BehaviourWorkflow` chain (Retry / Stop / Notification / UserTriggeredRetry) has
exactly **one** behaviour that genuinely operates across a plurality of work items (earnings):
`NotificationBehaviourConfig` (a `BatchableBehaviourConfig`, one mail per earning, chunked). Every
other behaviour acts on the *request* as a whole. The system therefore has only an *implicit,
endpoint-coupled* notion of plurality.

The question this doc answers: **how should per-ledger-item (or per-some-items) execution actually
work, as an abstraction that survives leaving the endpoint/response context?** Three responsibilities
are currently fused inside `ProcessEndpointResponseBehaviourHandler`, welded to `EndpointRequest`:

1. **Selection** — which items the behaviour acts on (inline; reads `request->earning_ids()` + batch state).
2. **Interpretation** — turning the raw outcome into a *per-item* statement of what happened. **This
   step does not exist today**: cred reads a request-level `is_attempt_successful()`, never per-item.
3. **Action** — what to do per item (the handler's `execute_*` methods).

## The 4-axis space (notification is one point)

A multi-item behaviour is described by four axes:

- **SELECT** — `all` | `subset-by-predicate` | `set-as-aggregate`
- **ACT** — `notify` | `persist/stamp` | `issue-artifact` | `fork/requeue` | `transform` | `suppress/void` | `summarize`
- **SHAPE** — `1:1 per item` | `N:1 fan-in` | `N:K regroup` | `N→subset filter`
- **WHEN** — `sync` | `chunked/paced` | `windowed/deadline`

`NotificationBehaviourConfig` = `SELECT:all × ACT:notify × SHAPE:1:1 × WHEN:chunked`. One cell of
the space. The intuition "only notification is multi-earning" is just "cred only built one cell."

## The interface lego (the seams not currently employed)

Context-free primitives (would live in the framework):

```
ILedger              items(): iterable<ItemRef>                       // the plurality itself
IOutcomeInterpreter  interpret(RawOutcome, ILedger): VerdictMap       // ItemRef -> Verdict{status,reason,data}
VerdictMap / Verdict                                                  // per-item statement of what happened
IItemSelector        select(VerdictMap, LedgerState): ItemRef[]       // the who/by-what of filtering
IItemAction          apply(ItemRef, Verdict): ItemResult             // do Z, per item
```

Plurality has **three topologies**, so three combinators (not one loop):

```
map        foreach selected: action.apply(item, verdict)             // 1:1 and N→subset   (notify, cert, void)
fold       ICollector.collect(selected, verdicts): AggregateResult   // N:1                 (digest, threshold)
partition  IRegrouper.buckets(selected): map<BucketKey, ItemRef[]>   // N:K → fork child    (re-bucket by state)
```

A behaviour becomes a **composition** run by a context-free `LedgerBehaviourRunner`:

```
verdicts = interpreter.interpret(rawOutcome, ledger)     // or a trivial uniform map (see below)
selected = selector.select(verdicts, state)
switch topology:
  map:       foreach selected → action.apply(item, verdicts[item]) → fold into state
  fold:      collector.collect(selected, verdicts) → one result
  partition: regrouper.buckets(selected) → fork a child ledger-workflow per bucket
state.record(results); runner owns chunking / pacing / reschedule
```

## The two pointed answers

**Source of info = `IOutcomeInterpreter`.** It is the *only* component allowed to know the raw
outcome's shape. `EndpointResponseInterpreter` parses a 207/multi-status body into per-earning
verdicts. **Key move:** when the source can only speak at the whole-batch level (a single HTTP
status), the interpreter *broadcasts* that one verdict to every item — producing a **uniform
`VerdictMap`**. Downstream never branches on "did we get per-item info or not"; the uncertainty is
absorbed at the edge. Today's entire per-request model is just the **degenerate (uniform)** case of
the per-item one.

**Filtering = `IItemSelector`**, a named predicate object — not handler code. "According to what" =
the `VerdictMap` (from the interpreter) + accumulated `LedgerState` (the `success_batch` /
`error_batch` / `unresolved_error_batch` that already exist on `BehaviourExecutionResult`) + any
external/temporal input it is handed. Concrete selectors: `AllSelector` (= notification today),
`AcceptedSelector`, `RejectedSelector`, `RetryableSelector`, `StaleSelector(deadline)`,
`DedupSelector(externalState)`, `ThresholdSelector` (all-or-none on an aggregate).

## The abstraction test — surviving outside endpoints/responses

**Push all context to the edges; keep the plurality core context-free.** The runner, selector,
action, and topology combinators only ever touch `(ILedger, VerdictMap)` — none names HTTP,
endpoints, or earnings. All endpoint-ness lives in exactly two adapters:

- `EndpointRequest → ILedger`
- `HttpResponse → VerdictMap` (the `EndpointResponseInterpreter`)

Swap those adapters and the same machinery runs over a bulk import, a validation pass, or a
certificate batch. This is ports-and-adapters: **plurality is a domain concept; "endpoint response"
is an adapter detail.**

## Proof it is not speculative: the multi-board saga is the same shape

The `MultiBoardReportingProcess` (companion spec) is this exact lego at a **coarser granularity**:

| topology element        | per-earning (within a request) | per-board (within the saga) |
|-------------------------|--------------------------------|------------------------------|
| `ILedger`               | the request's earnings         | the saga's boards            |
| `IOutcomeInterpreter`   | parse HTTP response body        | aggregator: request terminal-states → per-board verdicts |
| `VerdictMap`            | per-earning accept/reject       | `AllBoardsResolved` payload  |
| `IItemSelector`         | accepted / rejected / …         | `evaluate()` = `ThresholdSelector` (all-required-accepted?) |
| action / topology       | notify / fork / cert            | finalize vs compensate       |

Same `(Ledger, VerdictMap, Selector, Topology, Action)`; only the interpreter differs. The
abstraction appears **twice already** — which is the signal that the seam is real, not invented.

## Where pieces would live

- **Framework (tangible-ddd):** `ILedger`, `IOutcomeInterpreter`, `VerdictMap`/`Verdict`,
  `IItemSelector`, `IItemAction`, the topology combinators, `LedgerBehaviourRunner`. (Generic; no
  earning/endpoint knowledge.)
- **Consumer (tangible-cred):** the adapters (`EndpointRequest → ILedger`,
  `EndpointResponseInterpreter`) and the concrete selectors/actions (mail, cert, fork, void).

## The per-format decoder edge (how a user discriminates over CSV/JSON/XML)

The interpreter's first job — locate per-item records in an arbitrary body — uses a **format-native
path language, contained to a single config field**, then normalizes to a uniform record stream:

| format | locate/discriminate | PHP |
|--------|--------------------|-----|
| JSON   | **JMESPath** (one spec; preferred over JSONPath's dialect swamp) | `mtdowling/jmespath.php` |
| XML    | **XPath** (canonical) | built-in `DOMXPath` |
| CSV    | rows = records, columns = fields (no path lang) | built-in |

Design move: a per-format **decoder** turns `RawOutcome → record[]` (record = map of dotted keys).
The format-native path appears in exactly **one** field ("where is the record collection");
everything downstream (correlate, classify) addresses the normalized record by dotted key. A
**dry-run preview** against captured sample responses shows what the locator matched before save.
Path authoring is the realistic no-code ceiling; the named-interpreter registry covers the rest.

## Behaviour shape — `Behaviour<ItemType, Cardinality>`

A behaviour carries **two type-level facts**, the second of which cred never encoded:

1. **Ranges over** `T` — `Unit` (request-level) or an item type (`Earning`).
2. **Cardinality accepted** — `None` | `One` | `Many`.

| behaviour | shape | note |
|-----------|-------|------|
| Notification | `Behaviour<Earning, Many>` | per earning, any count |
| ResetUserCourseProgress | `Behaviour<Earning, Many>` | per earning (being built) |
| Retry | `Behaviour<Unit, —>` | resends the whole request |
| Stop | `Behaviour<Unit, —>` | request-level |
| UserTriggeredRetry | `Behaviour<Earning, One>` | **needs a singleton ledger** — primes *a* user to trigger; ambiguous for many |

`UserTriggeredRetry<Earning, One>` is the unencoded constraint today → a latent footgun (nothing
stops attaching it to a batching endpoint). PHP has no runtime generics, so encode as:
- runtime: `item_type(): ?class-string` + `cardinality(): Cardinality`, or simpler `accepts(Ledger): bool`;
  the runner validates a behaviour against its ledger **before** running (reject `One` on `count>1` at
  attach-time, not at 2am).
- static: PHPStan `@template T` on the interface + `@implements Behaviour<Earning>` on impls.
- UX payoff: the endpoint-config UI **filters selectable behaviours by the endpoint's cardinality** —
  a batching endpoint never shows `UserTriggeredRetry`.

Retry is **dual**: today's `<Unit>` (resend all) vs a future per-failed-subset `<Earning, Many>` —
same verb, two shapes — which is exactly why the type parameter must exist.

## YAGNI — disciplined extraction order

A clean hexagon, but building all of it for a single consumer (one context, one outcome type, one
real multi-item action) is over-fitting. Recommended order:

1. **Now (if/when per-item verdicts are needed):** introduce only the `IOutcomeInterpreter` +
   `VerdictMap` seam. It is the genuinely-missing capability, and it already has **two callers** (the
   per-earning response parse and the saga's board aggregator) — the one seam justified today.
2. Keep selectors/actions as concrete classes; leave topology as map + ad-hoc fork.
3. **Rule of three:** extract `ILedger` / `IItemSelector` / `IItemAction` / the topology combinators
   / `LedgerBehaviourRunner` when a *second non-endpoint context* actually arrives. Not before.

Decouple where it pays (interpretation); avoid ceremony where it does not (yet).

## Build decision (2026-06-30) — `ResetUserCourseProgressBehaviour`, concrete only

To satisfy the plurality need *without* building the generic lego, cred gets a **second concrete
multi-earning behaviour**: `ResetUserCourseProgressBehaviour`, a `Behaviour<Earning, Many>` (a
`BatchableBehaviourConfig` like Notification). Per earning it dispatches a Command-backed,
**idempotent** `ResetUserCourseProgressCommand(user_id, content_id)` (cross-context → LearnDash),
audited so the trace shows cred→LearnDash causation. Wired (for the harness) so a `200` from the
bogus board triggers it across **all** earnings in the request. **Code only, no selection UI now.**
This is a concrete cell of the space — NOT the `LedgerBehaviourRunner`/`IOutcomeInterpreter`
abstraction, which stays deferred per the extraction order above.
