# Integration Event Evolution — Handoff Document

**Purpose:** Self-contained briefing for a fresh session (human or AI) on the tangible-ddd 0.2.0 event-architecture evolution. Everything here was designed and decided in a multi-day design dialogue (2026-06-29 → 2026-07-04). The formal spec lives at `docs/superpowers/specs/2026-07-03-integration-event-taxonomy-and-await-mechanisms-design.md` in this repo — read it after this for full detail. This document explains the system as it IS, what it BECOMES, and WHY.

**Repos involved:**
- `wp-content/plugins/tangible-ddd` — the framework (this repo). Working checkout was on branch `v3-ddd` with unrelated uncommitted work; the 0.2.0 work targets a new `release/0.2.0` branch (not yet created).
- `wp-content/plugins/tangible-cred` — main consumer (composer `tangible/ddd: @dev` — loose pin, floats with checkout).
- `wp-content/plugins/tangible-datastream` — second consumer (`tangible/ddd: *` — also loose). Defines zero integration events; consumer-only.

---

## 1. The system today (v0.1) — how events actually work

### One class, two dispatch surfaces

`IntegrationEvent extends DomainEvent` (ddd-src/Domain/Events/). An "integration event" in v0.1 is **a domain event that additionally gets broadcast through the outbox**. There is one event instance, raised in the domain with live entities in hand, fanned to two surfaces by `EventRouter` (ddd-src/Application/Events/EventRouter.php):

```
aggregate raises new EndpointRequestSent($request, $attempt)     ← live objects
        │
  EventRouter::publish
        ├── ALWAYS: WordPressEventDispatcher → do_action('{prefix}_domain_{name}', ...$event->payload())
        │     in-process, synchronous, positional OBJECT spread. Event instance alive.
        │
        └── if IIntegrationEvent: bus → outbox row (integration_payload(), SCALARISED)
              → ActionScheduler → do_action('{prefix}_integration_{name}', [$wrapped_scalars])
              → event instance DEAD. Only a scalar shadow crosses.
```

### The scalarise membrane (one-way, lossy)

`IntegrationEvent::scalarise()` (ddd-src/Domain/Events/IntegrationEvent.php:31-94) flattens the payload for the wire: **Entity → get_id()**, BackedEnum → value, DateTime → ISO8601, arrays recursive. This is deliberate and lossy — there is no inverse. Post-ActionScheduler, an integration event can never be reconstituted as a typed object. The type exists on both sides of the boundary; instances exist only before it.

### Transport wrapping

`OutboxProcessor` wraps the payload with `__correlation_id`, `__sequence`, `__event_id` before enqueueing. Consumers registered through the `integration_action()` helper (ddd-wordpress/integration-events.php:19) get `extract_correlation()` (:56-87), which restores correlation context, stashes `__event_id` as command causation, strips transport keys, and spreads **positional** args to the callback. An `array_is_list()` gate (:83) already passes associative payloads through as a single arg — infrastructure half-ready for named payloads.

### Consumers today

- **Domain handlers** (`Application\EventHandlers\`, extend `WordPressActionHandler`): self-wire via ctor `add_action`; rehydrate the domain event from positional hook args (`IEventFromArgs::from_args()` or `ReflectionClass::newInstanceArgs`); `handle()` receives a typed event; may hold domain services and do real work. Auto-wired by eager instantiation: `register_event_handlers()` (ddd-wordpress/hooks.php:39) walks DI service ids matching `\Application\EventHandlers\`.
- **`AsyncWordPressActionHandler`**: hooks the DOMAIN action, re-enqueues `'async_' . $domain_action` with the raw domain params as AS args, rehydrates on the worker. This is a shadow lane — hand-rolled serialization (`from_args`), hand-rolled hook (`async_` prefix), hand-rolled outbox (AS args, minus durability/correlation/sequence). Design intent was an "async domain handler" (same bounded context, deferred for time-budget reasons) — the intent is legitimate; the vehicle is wrong (see §3.5).
- **Process resume** (`ProcessRunner::register_event`, ddd-src/Application/Process/ProcessRunner.php:81-85): raw `add_action` at priority 99 calling `$event_class::from_payload($payload)`.

### Three latent defects in process resume (verified, never hit in prod)

1. **`from_payload()` is a phantom.** Not on `IIntegrationEvent`, not on any base class. Sole implementation: `tests/Fakes/FakeIntegrationEvent.php`. Any real event registered for await would fatal when the hook fires.
2. **Transport keys leak.** The runner bypasses `extract_correlation`, so its callback receives `__correlation_id` etc. mixed into the payload.
3. **Causation dropped.** The `__event_id` stash is skipped on the runner path.

No production process awaits anything, so these are latent. Tests call `resume_on_event()` directly with the fake — the await machinery has never been exercised through the real outbox → AS → hook pipe.

### The await primitive is 1-of-1 only

`AwaitEvent` (event class + scalar match_criteria) suspends a `LongProcess`; `resume_on_event` advances on the FIRST matching event, unconditionally (ProcessRunner.php:144). No fan-in ("wait for all N") exists. The driving use case — cred's `MultiBoardReportingProcess`, which must await N state-board endpoint results — is inexpressible.

### Consumer inventory (verified 2026-07-02)

tangible-cred has 9 integration events; **7 are fat** (ctor takes entities): `LicenseCreated/Revised/Deactivated/Reactivated`, `EarningIssued`, `EndpointRequestSent`, `BehaviourWorkflowReschedule`. Clean: `EndpointAuthRefresh` (int,int); conditional: `ReportingEndpointsReschedule` (array). These are **correct v0.1 usage**, not abuse — the API was designed this way. tangible-datastream defines zero. Decisive follow-up finding (2026-07-04): all 5 of cred's path-1 integration consumers (`includes/hooks/integration/`) are already thin scalar listeners (`fn(ids) → Command->send()`) — the fat object payloads have ZERO integration-side consumers.

---

## 2. The core insight driving the evolution

An event is an immutable statement of fact about the past. An entity is mutable present. Embedding an entity in a durable event is a category error — `$request` at publish time ≠ `$request` at consume time. The only entity-part that is a fact is its **identity** — which is exactly what `scalarise()` preserves. v0.1's membrane was telling the truth all along; v0.2 promotes it from a runtime shim into the type system.

The boundary splits an event's life into **the moment** (domain side — live objects, same process/transaction) and **the record** (integration side — durable, scalar, resurrectable). In v0.1 the record is write-only. v0.2 makes it readable — events get an afterlife: await, replay (a planned DDD-dashboard command), typed listeners.

Crucially: **the integration surface crosses TIME, not context.** Same-bounded-context deferred reactions are its most common case (integration with your own future has the same physics as integration with another plugin). "Integration event" is defined by physics (what it is made of), not audience (who consumes it) — audience is unknowable at publish time; composition is known at the type level.

---

## 3. The evolution (v0.2)

### 3.1 Taxonomy split — the one breaking change

| Type | Definition | Raisable? | Wire |
|------|-----------|-----------|------|
| `DomainEvent` | may hold anything | **yes** — only raisable kind | in-process only, unless it announces |
| `IntegrationEvent` **(SEVERED + redefined, owner 2026-07-06)** | reversible values by definition; **derived-only record** | **NO** — type-level (not `IDomainEvent`) | codec, named payload, total return ticket |

**PARTITION, not subset** (supersedes earlier hierarchy): both extend a shared `Event` root. Twins unraisable by type. **Self-publisher** = born-scalar fact opting in explicitly: `extends DomainEvent implements IIntegrationEvent, IAnnouncesIntegration { use IntegrationBehaviour; }` — raisable, hydratable, announces `$this` (trait default). `Integration\` sub-namespace = twins only; self-publishers live in the parent namespace. **Journey slots** (nullable correlation_id/event_id on IntegrationBehaviour, null = "not yet integrated", stamped at publish AND hydrate, stable across retries/replay) double as the re-raise guard: `record()` throws `AlreadyIntegrated` on a stamped instance (a hydrated reconstruction must not be re-raised — re-delivery = replay, not raising).

**Hard break, by owner ruling (2026-07-04): there is NO legacy species.** An earlier draft kept v0.1 semantics alive under a `BroadcastEvent` parking type; rejected once the consumer inventory showed fat payloads are unconsumed across the boundary and all consumers are in-house. `scalarise()` is deleted; the codec is the only serializer. Every fat event is rewritten in the same release: scalar-native where consumers only need ids (most), `IAnnouncesIntegration` twin where domain handlers need the object, or DEMOTED to plain `DomainEvent` where the integration action had no consumers at all (the `License*` events, pending a notifications-bridge audit).

Three lanes to the outbox:
1. **Record directly**: `extends IntegrationEvent` — thin facts, no in-process consumer needs entities.
2. **Moment announces record**: fat `DomainEvent implements IAnnouncesIntegration` with `to_integration(): Integration\SameShortName` — rich in-process handlers AND an awaitable record. Twin lives in an `Integration\` sub-namespace, same shortname. The `to_integration()` **return type IS the twin announcement** (reflection reads it; no duplicate static member — two sources of truth drift). No reverse pointer — the record never references its moment.
3. **Demote**: `extends DomainEvent` — integration surface deleted when the audit shows no consumers (firing into the void).

Decision rule for whether an event should exist at all: if its only purpose is triggering one same-plugin command, use a queued/async command instead. Events earn existence through: unknown/multiple consumers, cross-plugin reach, await, replay/audit value. Secondary rule: if a domain expert would state the reaction as a standing business rule ("whenever a user joins an agency, backfill accreditations"), it is a policy → event + listener; if it is mere implementation deferral → async command, no event.

### 3.2 The codec — class as schema

No authored serialization code, ever. Event author writes ONLY a constructor with promoted readonly reversible-value params. Base class derives both directions via reflection (schema cached per class; codec lives ON the base — standalone EventCodec class folded in, owner ruling 2026-07-05):

- `integration_payload()`: props by name → named array.
- `from_payload(array): static`: named lookup + per-param type coercion (`Enum::from`, `new DateTimeImmutable`, scalar casts). Transport keys ignored naturally by named lookup.

**Round-trip law** (property-tested framework guarantee): `E::from_payload($e->integration_payload())` value-equals the original, for every registered `IntegrationEvent`.

**Enforcement (owner ruling 2026-07-04): correctness by construction, zero shipped machinery.** Scalar-declared ctors make illegal VALUES a PHP TypeError at raise (an existing instance = proof of scalar contents; twin lane: `to_integration()` feeds `get_id()` into scalar params, mismatch fails at raise). Illegal DECLARATIONS (entity-typed param) → `scalarise()` throws `NonReversibleValue` at first publish, incl. the author's first local test. `hydrate()` throws on corrupted/skewed rows. Drafted and deleted in review: runtime verdict, config gate, CI conformance sweep (residual value nil for in-house lockstep migration).

Precedent in-repo: `IEventFromArgs::from_args()` + `WordPressActionHandler::create_domain_event()` already do positional rehydration on the domain surface. The codec is the named, total, integration-surface sibling.

### 3.3 Integration consumers — exactly two kinds

Both thin, both framework-mediated, both causation-correct. The asymmetry with domain handlers is principled: a domain handler runs in the same moment (entities alive, services legitimate, real work allowed — "a glorified post-command handler"); an integration consumer runs at another time, where the only legitimate move is starting a new unit of work — dispatching a Command. Work beyond translation belongs in the command handler, where audit/causation/retry live. Motto: **coordinators sequence, commands act, listeners translate.**

| Consumer | Shape | DDD name |
|----------|-------|----------|
| `IntegrationListener` | event → `?ICommand` | stateless automation policy (EventStorming purple sticky) |
| `LongProcess` resume | event → saga wake | stateful policy / process manager |

`IntegrationListener` — self-wiring dumb class (mirrors `WordPressActionHandler` muscle memory):

```php
abstract class IntegrationListener {
  /** @return class-string<IIntegrationEvent> */
  abstract protected function get_event_class(): string;
  /** The whole job: fact in, intention out. Null = not my business. */
  abstract protected function get_command(IIntegrationEvent $event): ?ICommand;

  public function __construct() {
    // delegates to the single internal ceremony primitive integration_listener():
    // hook integration_action(), extract_correlation (unwrap + causation stash),
    // codec-hydrate, call get_command(), $cmd?->send()  (causation already ambient)
  }
}
```

- Auto-wired via namespace convention `\Application\IntegrationListeners\` (same eager-boot walk as handlers) — construction through the container, so ctor DI works.
- Class form is THE convention; a procedural `integration_listener($class, $fn)` primitive exists internally/as escape hatch but is not the paved road. Deciding argument: **observability** — classes are enumerable topology for the DDD dashboard (walk namespace, read `get_event_class()` → event→command edges) and name themselves in error logs; closures are invisible edges.
- One event may have MANY listeners — fan-out is why the event hop exists. Hence the mapping does NOT live on the event class (a fact must not prescribe its response; cross-plugin consumers can't edit the publisher's contract class).
- Layer placement: the ceremony is adapter mechanics (framework base); what remains in the consumer's file is one line of application POLICY — which command this fact triggers. Hence `Application\`, beside `EventHandlers\`. The decision rule between the two namespaces is the **transaction boundary**: must commit atomically with the raising command → domain handler (e.g. datastream's `MatchSubscriptionsOnCapture`, whose outbox writes are transactional with `captured_events` — it MUST stay a domain handler); new unit of work later → integration listener.

### 3.4 Await mechanisms

`IAwaitMechanism` strategy VO replaces the hardcoded 1-of-1: `event_class()` (SQL prefilter → `waiting_for` column), `accepts()` (routing: is this event for THIS process?), `accumulate()` (immutable arrival record), `is_satisfied()` (structural only — counts arrivals, never judges success), `resume_argument()` (what the post-await step receives). Self-contained — spec AND gathered state serialize polymorphically (`{_class,_data}` via `JsonLifecycleValue`) into a new `await_mechanism` column.

- `AwaitEvent` refactors onto the interface with zero behavior change (`resume_argument()` = the event → existing 2-param steps untouched). Legacy rows (`await_mechanism` NULL, `waiting_for` set) reconstruct an `AwaitEvent` on hydrate — zero migration.
- `AwaitAll(event_class, expected ids, timeout_seconds REQUIRED, on_timeout: TIMEOUT_FAIL|TIMEOUT_PROCEED, gathered=[])`. Routing = set-membership via a static extractor ON THE PROCESS, announced per-await (`key_by: [self::class, 'resolution_key']` — serializes as two strings; replaces an earlier `Gatherable` event interface, owner design 2026-07-04) against ids the saga itself minted (held in its payload ledger). Event stays totally consumer-ignorant — no reverse index, no coordinator id on domain entities. Buffer persists the id set only; verdict data is re-read from durable rows post-await (terminal state can't regress). Duplicate redelivery is idempotent (`!in_array(gathered)`).
- Authority split: framework counts (structural), each entity owns its own outcome, the coordinator's post-await step judges the group (throw → `#[Compensates]` chain).
- Runner surgery (the one irreducible change): `resume_on_event` accumulates and advances ONLY when satisfied (today: always advances, ProcessRunner.php:144). Concurrency: accumulate+save wrapped in `GET_LOCK('ddd_process_{id}', 5)` (two AS workers racing read-modify-write on `gathered` would lose an arrival → saga waits forever). Timeout: `suspend_for_event` schedules a single AS action `[process_id, step_index]`; on fire, still-suspended-at-same-step_index guard (stale timers harmless); `TIMEOUT_FAIL` → compensation, `TIMEOUT_PROCEED` → resume with partial mechanism (coordinator sees `gathered < expected`). Semantics: **pure wall clock** — deliberately NOT pause-aware (see `docs/outbox-pause-design.md` §6 exception: an outbox pause freezes event delivery but not the alarm; accepted ops caveat, snooze rejected as over-engineering).
- Resume plumbing repaired: codec kills the phantom `from_payload`; runner unwraps transport keys + stashes `__event_id` causation (shared unwrap logic hoisted so Application doesn't depend on the WordPress namespace); `register_event` guards awaited class is a new-species `IntegrationEvent` — fail at registration, not at wake.
- Await registration: `#[Awaits(Event::class)]` class attribute on the process (read by the existing boot walk via reflection) replaces per-saga YAML `awaits:` tag entries — the `_instanceof` blanket tag can't carry per-service attributes, so the YAML path required hand-written entries whose omission was silent-fatal. Plus a suspend-time guard: `suspend_for_event` throws `AwaitedEventNotRegistered` if the awaited class has no registered hook — first suspension fails loudly instead of the saga sleeping forever.
- Deferred (YAGNI): `AwaitAny`, `AwaitKofN`, multi-event-class mechanisms.

### 3.4b Raw-hook escape hatch (sanctioned interop lane)

Raw `add_action($event::integration_action(), $fn)` always works — it's how a consumer WITHOUT a tangible-ddd dependency (vanilla plugin, theme, third party) subscribes to published facts. Receives one array: named codec payload + `__`-prefixed transport keys (ignore them). Forfeits: typed hydration, correlation/causation (invisible to command_audit), retry, topology membership. Framework obligations: (1) codec payload keys = ctor param names = **public API** — param-name stability discipline on published events; (2) dashboard enumerates foreign subscribers via `$wp_filter` — the hatch is detectable, not dark matter. Spec §5.3.

### 3.5 `AsyncWordPressActionHandler` deprecated

The "async domain handler" concept is physically incoherent: the AS hop is another TIME, not another thread — deferred code observes later state, and the serialization boundary forces params into record-land regardless of intent. Its legitimate use cases decompose into `IntegrationListener` (one-line policy) + command handler (the body, with its deps). Strict upgrade: the deferred work — currently invisible to `command_audit` — becomes a command with an audit row, `__event_id` causation, and retry. Deprecated 0.2.0, removed 0.3.0. cred has exactly one migration: `IssueRetroactiveAccreditationsOnAgencyJoinHandler` → `Integration\UserJoinedAgency` + listener + `IssueRetroactiveAccreditations` command.

### 3.6 Versioning

Composer pins are loose (cred `@dev`, datastream `*`) — consumers float with the checked-out branch, so a release branch alone gates nothing. Belt + suspenders: (1) `release/0.2.0` branch AND consumers move to explicit pins; (2) **no runtime config gate, no CI sweep** (enforcement = TypeError at raise + scalarise() throw at first publish; hard break obligates lockstep migration, nothing left to gate). **This release is deliberately reverse-incompatible.** Await/codec/runner-repair are additive; the redefinition + `scalarise()` strictification are breaking: every fat event rewritten, wire payload shape changes (positional scalarised → named codec), and the 5 hook-file registrations convert to `IntegrationListener` classes. Deploy choreography required per site: drain/pause the outbox, deploy framework + consumers together, resume (old pending rows carry old shapes; no automatic translation).

---

## 4. First consumer (tangible-cred — separate spec/plan, NOT in the framework scope)

- New terminal event `Integration\EndpointRequestResolved { request_id: int, outcome }` — pure scalar record (no await interface; saga keys it via its own `resolution_key()` static) — fires ONCE when an `EndpointRequest` reaches terminal state. Distinct from `EndpointRequestSent`, which fires per-attempt and is rewritten scalar-native. ("Request resolved" is a real domain fact the model was missing.)
- `MultiBoardReportingProcess`: `submit_to_boards` creates request rows synchronously via service (needs the ids for its ledger; creating rows ≠ sending — the existing cron send/batching boundary is untouched) → `Result(await: new AwaitAll(...))` → `evaluate($payload, AwaitAll $gather)` re-reads rows by id, judges group, throws → `#[Compensates]` voids accepted boards.
- Parked commits to revert (killed by this design): `0b05e46` (origin_saga_id entity field), `6dcb7d9` + `7b9aafa` (column + index).

---

## 5. Decision log (compressed — full table in spec §10)

| Rejected | Why |
|----------|-----|
| `origin_saga_id` on entity / aggregator / tally table / synthetic group event | coordinated thing pointing at coordinator; infra to work around missing framework primitive |
| Temporal-style closure predicates | snapshot-resume can't serialize closures; predicate must be reified data |
| `Gatherable::is_terminal()` | wake-side event carries entity as id (scalarised) — solved by dedicated terminal event |
| repo-loading `from_payload` / entity-snapshot payloads | event becomes query; temporal gap; stale-truth duplication |
| `lossy_legacy()` shame marker | fat events are CORRECT v0.1 usage; taxonomy split is honest, marker was shame for following the API |
| `Contracts\` namespace | PHP convention: contracts = interfaces; context already has the term "integration event" |
| static `integration_class()` member | duplicates `to_integration()` return type; drift |
| `to_command()` on the event (merge listener into event) | fact must not prescribe response; breaks fan-out (N listeners) and cross-plugin consumers |
| closure-binding listeners as public API | invisible topology, anonymous logs, manual wiring, no DI |
| `IAnnouncesIntegration`/`IIntegrationSource` names | agency misattribution / ES connotation + datastream `IEventSource` collision → `IAnnouncesIntegration` |
| `AwaitAny`/`AwaitKofN` | YAGNI |

---

## 6. Current state & next steps

- **Spec**: written + 3 amendments, at `docs/superpowers/specs/2026-07-03-integration-event-taxonomy-and-await-mechanisms-design.md`. **NOT committed** — the tangible-ddd checkout was on `v3-ddd` with unrelated uncommitted work; branch/commit decision pending with the user.
- **Next**: user reviews spec → `writing-plans` skill produces the implementation plan for the framework work → implementation on `release/0.2.0`. The cred consumer (§4) gets its own spec + plan afterward.
- **Testing priorities** (spec §9): codec round-trip property test; publish guard; AwaitAll unit + runner + timeout + hydration fallback; and the always-missing END-TO-END test — raise → outbox → AS → wrapped payload → process actually wakes, through the real pipe.
- Session memory for this work: `awaitall-framework-spec` in the project memory dir.
