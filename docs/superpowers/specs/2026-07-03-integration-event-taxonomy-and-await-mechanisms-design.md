# Integration Event Taxonomy & Await Mechanisms — Design

**Date:** 2026-07-03
**Target:** tangible-ddd `release/0.2.0`
**Status:** Draft — pending review
**Driving use case:** `MultiBoardReportingProcess` in tangible-cred (fan-in over N state-board endpoint results)

---

## 1. Motivation

Three converging problems, discovered while designing the multi-board reporting saga:

1. **No fan-in await primitive.** `AwaitEvent` is a 1-of-1 single-shot: `ProcessRunner::resume_on_event` advances on the first matching event, unconditionally. A saga that must wait for N results has no legal way to express it — leading to design smells we explicitly rejected (an `origin_saga_id` column on the domain entity, external aggregator services, tally tables, synthetic "group resolved" events).

2. **The process resume plumbing has never worked in production.** `ProcessRunner::register_event` calls `$event_class::from_payload($payload)` — a method that exists nowhere except a test fake. It also receives the raw wrapped payload (transport keys `__correlation_id`/`__sequence`/`__event_id` still inside) because it bypasses `extract_correlation()`, and it drops the `__event_id` causation stash. No production process awaits anything today, so these defects are latent — the await machinery is test-proven only.

3. **`IntegrationEvent` conflates two species.** Today `IntegrationEvent extends DomainEvent` means "a domain event that additionally gets broadcast through the outbox." It is raised with live entities in hand, and `scalarise()` flattens them (`Entity → get_id()`) for the wire — a deliberate, one-way, lossy transport adaptation. Consequence: an integration event can never be reconstituted after the ActionScheduler hop. The type exists on both sides of the boundary; instances exist only before it. This blocks await (which needs typed events at wake), replay (a planned DDD-dashboard command), and typed async handlers.

## 2. Philosophy

An event is an immutable statement of fact about the past. An entity is mutable present. Embedding an entity in a durable event is a category error — `$request` at publish time is not `$request` at consume time. The only entity-part that is genuinely a fact is its identity. `scalarise()`'s `Entity → get_id()` was always the membrane telling us this; v0.2 promotes the membrane from a runtime shim into the type system.

The boundary splits an event's life into **the moment** (domain side — live objects, handlers react while state is in hand) and **the record** (integration side — durable, scalar, resurrectable statement of fact). Today the record is write-only. This design makes it readable — events get an afterlife. Await, replay, and typed listeners all fall out of that one repair.

**The class split is a lifetime split.** What the separation exists to kill is complete name/class anemia post-ActionScheduler: in v0.1, one class claimed both lifetimes but its instances only existed pre-AS — everything after the hop was anonymous positional scalars wearing a hook name (`fn($a, $b, $c)`, meaning by argument order, invisible to types and IDEs). v0.2: `DomainEvent`'s validity is bounded by the request, honestly; `IntegrationEvent` is the class engineered so its instances exist on BOTH sides of the hop — constructed pre-AS, resurrected post-AS. The post-AS world gets typed, named, self-describing citizens.

## 3. Event taxonomy (the core change)

| Type | Definition | Raisable? | Wire |
|------|-----------|-----------|------|
| `DomainEvent` | may hold anything — entities, VOs, object graphs | **yes** — the only raisable kind | in-process only, unless it announces |
| `IntegrationEvent` *(severed, redefined)* | **composed of reversible values, by definition**; derived-only record | **NO — type-level** (not an `IDomainEvent`; `record()` rejects it) | codec (named payload), total return ticket |

**Reversible values:** `int|string|bool|float|null`, `BackedEnum`, `DateTimeInterface`, and arrays thereof. Entities and arbitrary objects are not legal constructor parameters for any record.

**The taxonomy is a PARTITION (owner ruling 2026-07-06 — supersedes the earlier subset/hierarchy design).** `IntegrationEvent` no longer extends `DomainEvent`; both extend a shared `Event` root. Consequences: a derived record physically cannot be raised — the theoretical possibility of a user triggering an integration event directly is eliminated by the type system, not by convention. The moment/record lifetimes never overlap except at the `to_integration()` handoff.

**Hook-name placement under the partition** — each species owns its surface: `Event` root carries `name()` derivation + abstract `prefix()`; **`action()` moves down to `DomainEvent`** (domain hook is the raisable species' concern — twins no longer possess a never-fired domain hook name); **`integration_action()` lives on `IntegrationBehaviour`** (self-publishers need it without the record base; the trait resolves `static::prefix()` through the host's chain). Derived strings unchanged from v0.1 (`{prefix}_domain_{name}` / `{prefix}_integration_{name}`) — `#[Awaits]`, listeners, and raw-hook subscribers address the same names as today. Consumer generated bases survive: the `DomainEvent` base untouched; the `IntegrationEvent` base re-parents to the severed framework base, still only pinning `prefix()`.

**Born-scalar facts opt in EXPLICITLY — the self-publisher.** A domain event that happens to be scalar may declare itself its own record: `extends DomainEvent implements IIntegrationEvent, IAnnouncesIntegration { use IntegrationBehaviour; }` — raisable (it IS a domain event), hydratable (the trait carries the codec), self-announcing (trait default `to_integration(): static { return $this; }`). Two declaration lines replace what would otherwise be a verbatim twin class. Nothing is implicitly a record: recordhood is either derived (twins, unraisable) or declared (self-publishers, explicit paperwork). `IntegrationBehaviour` is the single codec implementation — the `IntegrationEvent` base and self-publishers both `use` it.

**The reconstruction wrinkle and its guard.** A self-publisher hydrates post-AS into its own (raisable-typed) class — a listener could re-`record()` the reconstruction, re-announcing a traveled fact (publication loop). Guard: **the journey slots are the "already integrated" bit.** Null journey = not yet traveled = raisable; stamped journey (set at publish AND at hydrate) = a reconstruction — `record()` throws `AlreadyIntegrated`, loud and immediate. Twins are blocked by type; traveled self-publishers by the stamp. Re-delivery of a traveled fact is REPLAY (outbox, operational command) — never raising.

**Hard break, by owner ruling (2026-07-04): there is no legacy species.** An earlier draft kept v0.1's lossy semantics alive under a `BroadcastEvent` parking type. Rejected: all consumers are in-house; a consumer inventory showed every integration-side subscriber is already a thin scalar-consuming listener (nobody consumes the fat objects across the boundary); and two species would be a permanent cognitive tax plus an invitation to keep writing fat events. `scalarise()` is NOT deleted — it is promoted: same name, same membrane position, ONE changed behavior (strict: throws `NonReversibleValue` on a non-reversible value instead of lossily flattening it). Consuming plugins are **obligated to write proper integration events**: every existing fat event is rewritten in the same release (see §7 migration).

### 3.1 The bridge: `IAnnouncesIntegration`

For a fat domain event that ALSO needs an awaitable/replayable record (both rich in-process handlers AND an afterlife):

```php
interface IAnnouncesIntegration {
  public function to_integration(): IIntegrationEvent;   // interface return — the bus needs no more
}
```

**One outbox door; exactly two announcer kinds, both explicit.** Router: `instanceof IAnnouncesIntegration → bus->publish($event->to_integration())`. Implementors: fat moments (return their derived twin) and scalar self-publishers (trait default returns `$this`). The unraisable `IntegrationEvent` base does NOT implement `IAnnouncesIntegration` — twins are the announcement's product, never its subject. Semantic split: `IIntegrationEvent` = IS a record (hydratable, either shape); `IAnnouncesIntegration` = outbox-bound (knows its record); `IDomainEvent` = raisable.

```php
final class LicenseRevised extends DomainEvent implements IAnnouncesIntegration {
  public function __construct(
    public readonly License $license,        // fat — domain handlers get the entity
    public readonly string $old_value,
  ) {}

  public function to_integration(): Integration\LicenseRevised {   // narrow return type = the announcement
    return new Integration\LicenseRevised(
      license_id: $this->license->get_id(),
      old_value:  $this->old_value,
    );
  }
}
```

- **`to_integration()` is the irreducible residue — decisions, not mechanics.** Auto-deriving the bridge (scalarising the moment's payload into the record) was proposed four times and fails twice each time: *fact selection* (which raise-time truths publish — e.g. facts harvested from inside the entity, absent from `payload()`) and *vocabulary translation* (`earning` → `earning_id`; the published names ARE the API design act) exist in neither class's structure. Everything mechanical is derived elsewhere (codec, wiring, conformance); the five hand-written lines are pure judgment. The moment is the harvest point: the one place the live entity is in hand, in-transaction, at the instant the fact became true — raise-time truths not harvested here are gone as such. Rule: identity, verdicts, frozen timestamps → into the record; mutable state consumers must see fresh → repo at consume-time.
- `EventRouter::publish`, complete: `dispatcher->dispatch($event)` always; `if ($event instanceof IAnnouncesIntegration) bus->publish($event->to_integration())` — the single outbox branch, both lanes.
- Raise-site ergonomics preserved: the domain raises ONE event; the framework derives the record. The moment knows how to write its own record.
- **No separate class-string member.** The `to_integration()` return type IS the twin announcement; reflection reads it statically (`ReflectionMethod::getReturnType()`). One source of truth — a duplicate `integration_class(): string` member could drift. Convention: narrow your return types (DI compile may warn on a wide `: IntegrationEvent` return).
- Twin naming: sub-namespace, same shortname — `Events\Reporting\LicenseRevised` (moment) / `Events\Reporting\Integration\LicenseRevised` (record). NOT `Contracts\`. **`Integration\` = derived twins ONLY** (the unraisable kind); self-publishers are domain events and live in the parent namespace — the address says which kind you hold.
- No reverse pointer: the record never references its moment (it is rehydrated in a worker where the moment never existed).
- Same shortname → identical derived `name()` → the twin's `action()` STRING equals the moment's domain hook. Harmless by construction: **twins are bus-only** — the router sends `to_integration()` output straight to the bus, never through the dispatcher, so the twin's domain action never fires. In practice the moment owns `_domain_{name}`, the record owns `_integration_{name}`.

### 3.2 Lanes to the outbox (and one away from it)

| Lane | Mechanism | When |
|------|-----------|------|
| self-publisher | `extends DomainEvent implements IIntegrationEvent, IAnnouncesIntegration { use IntegrationBehaviour; }` | born-scalar facts; no in-process consumer needs entities |
| moment announces twin | fat `DomainEvent` + `IAnnouncesIntegration` → twin `extends IntegrationEvent` (unraisable, `Integration\` sub-namespace) | rich in-process handlers AND a published record needed |
| **demote** | plain `extends DomainEvent` | audit shows the event's integration action has NO consumers — firing into the void; stop publishing |

**Decision rule — when does an event deserve to exist at all:** if an event's only purpose is triggering one same-plugin command, use a queued command instead. Events earn their existence through: unknown/multiple consumers, cross-plugin reach, await, replay/audit value.

**Lane choice is wire-invisible and revisable for free.** Migrating lane 1 → lane 2 (a record gaining a fat moment when a transactional in-process consumer appears) = add the moment class + fatten the raise site; the record keeps its FQCN, hook name, and payload — sagas, listeners, and raw-hook subscribers untouched. Choosing lane 1 today is never a trap; pick the cheapest lane that serves today's consumers.

**The cohesion rule: one fact, one raise site.** v0.1's conflation had one virtue — a fact's domain-face and wire-face couldn't drift, being one object. Post-split, the known hazard: a lane-1 record ships; months later someone needs a rich in-process reaction, writes a NEW fat domain event for the same fact, and forgets to make it announce the existing record. If the raise site migrates to the unannounced moment, the record silently stops firing — sagas starve, listeners fall silent, domain surface looks healthy. Therefore: adding a moment to an existing record is ALWAYS the lane migration above (moment announces the EXISTING record, raise site swaps in the same commit) — never a parallel event. The naming convention is the tripwire (the moment belongs at the record's parent namespace, same shortname — created adjacent to the record it must announce). A mechanical `doctor` audit (unlinked same-shortname pairs; records with zero subscribers; unawaited announcements) belongs to the DDD dashboard's command family, out of 0.2.0 scope.

## 4. The codec: class-as-schema

No authored serialization code, ever again. The event author writes only:

```php
final class EndpointRequestResolved extends IntegrationEvent {
  public function __construct(
    public readonly int $request_id,
    public readonly ReportingOutcome $outcome,   // BackedEnum — typed, reversible
  ) {}
}
```

The base class derives both directions from the constructor signature via reflection (schema cached per class; the codec IS the base — no standalone engine class):

- **scalarise** (`integration_payload()`, on the base): read promoted readonly props by name → named array of reversible scalars. (JSON encoding is the outbox row's downstream concern — the codec scalarises values, it never serializes bytes.)
- **hydrate** (`from_payload(array): static`): named lookup per ctor param with type coercion — `ReportingOutcome::from($raw)`, `new DateTimeImmutable($raw)`, scalar casts. Transport keys (`__correlation_id` etc.) are ignored naturally by named lookup.
- `payload()` (domain surface) defaults to the same named array; existing overrides win (non-breaking).

**Round-trip law** (framework guarantee, property-tested): `Event::from_payload($event->integration_payload())` is value-equal to the original, for every registered `IntegrationEvent`.

**The fact vs. the journey — journey lives in SLOTS, never in the schema (owner ruling 2026-07-06, revising an earlier envelope design).** Correlation/causation are per-PUBLICATION, not per-trip: assigned once at outbox insert, stable across retries AND dashboard replays (both re-deliver the same row with the same stamps) — so a record MAY carry them, and does, via nullable base/trait slots (`correlation_id()`, `event_id()`), NOT constructor params. Null = "not yet integrated" (honest pre-publish state); stamped by the framework at publish and at hydrate. Because the codec schema derives from ctor params only, the slots never enter `integration_payload()` — the round-trip law is untouched, and authors' ctors stay pure facts. The slots double as the re-raise guard (§3, `AlreadyIntegrated`). Ambient `CorrelationContext` plumbing is unchanged (still auto-stamps causation onto listener-dispatched commands); the slots add explicit read access on the reified record: `$event->correlation_id()`.

**The invariant being enforced — hydratability, not wire-scalarity.** v0.1's `scalarise()` was a *total* flattener: only scalars ever reached the outbox, guaranteed, since day one. That was never at risk. v0.2's `scalarise()` is deliberately *partial* (throws on non-reversible) because it guards the stronger property the afterlife needs: **every row is a valid constructor call for its class** — readable back, not merely writable. The total flattener writes `{earning: 812}` for `EarningIssued(Earning $earning)`; the row is scalar and permanently unhydratable — a cheque that can't be cashed, discovered at wake three days later on an AS worker. Throwing at the membrane moves that failure to publish-time, synchronous, on the right side. Same membrane, same name, one changed verdict on the illegal case.

**Enforcement (owner ruling 2026-07-04): correctness by construction — zero shipped machinery.** The type system and the codec's own throws are the entire enforcement:

- **Illegal VALUES are unrepresentable.** A conforming event's ctor params are scalar-declared; `new Event(request_id: $entity)` is a PHP `TypeError` at raise, synchronous. An `IntegrationEvent` instance that exists is already proof of scalar contents. Same in the twin lane: `to_integration()` feeds `get_id()` results into scalar params — any mismatch fails at raise in the aggregate's own request.
- **Illegal DECLARATIONS fail at first publish.** Authoring an entity-typed param (`Integration\Foo(EndpointRequest $r)`) is the only remaining sin; `scalarise()` throws `NonReversibleValue` the first time the event is published anywhere — including the author's first local test of the feature. Loud, publisher-side. `hydrate()` throws symmetrically on corrupted/skewed rows (data errors).
- **NOT the constructor** (children don't call `parent::__construct()` — a base-ctor guard would never run), NOT a pre-flight verdict, NOT a config gate, NOT a CI conformance sweep. All four were drafted and deleted in review: the verdict re-derives what scalarise discovers; the hard break leaves nothing to gate; and the sweep's only residual value (a declared-fat event shipping with zero tests publishing it) is a nil window for an in-house team rewriting every event with tests. Accepted risk: such an event would fatal in prod at first publish — loud, mechanical fix.

(The membrane's strictification is stated in §3 — same `scalarise()` name, throws instead of flattens.)

Precedent in-repo: `IEventFromArgs::from_args()` (positional, domain surface) and `WordPressActionHandler::create_domain_event()` already rehydrate domain events at the hook boundary. The codec is the integration-surface sibling: named instead of positional, total instead of best-effort.

## 5. Consumers of the integration surface

Exactly **two** consumer kinds are legal on the integration surface. Both thin, both framework-mediated, both causation-correct:

| Consumer | Shape | Style |
|----------|-------|-------|
| listener | event → `?ICommand` | choreography |
| process resume | event → saga wake (`IAwaitMechanism`) | orchestration |

The asymmetry with the domain surface is principled. A domain handler runs in the same process and moment — entities alive, domain services legitimate, real work allowed ("a glorified post-command handler"). An integration consumer runs in a different worker at a different time; the only legitimate move is starting a new unit of work — dispatching a Command. Work beyond translation belongs in the command handler, where audit/causation/retry live. Coordinators sequence, commands act, listeners translate.

### 5.1 `IntegrationListener` (self-wiring base, mirrors `WordPressActionHandler`)

```php
abstract class IntegrationListener {

  /** @return class-string<IIntegrationEvent> */
  abstract protected function get_event_class(): string;

  /** The whole job: fact in, intention out. Null = not my business. */
  abstract protected function get_command(IIntegrationEvent $event): ?ICommand;

  public function __construct() {
    $event_class = static::get_event_class();

    add_action($event_class::integration_action(), function (array $wrapped) use ($event_class) {
      $params = extract_correlation([$wrapped]);          // unwrap + correlation + __event_id causation
      $event  = $event_class::from_payload($params[0]);   // codec — typed event resurfaces
      $this->get_command($event)?->send();                // causation already ambient
    }, 10, 1);
  }
}
```

- Auto-wired by namespace convention `\Application\IntegrationListeners\` via the existing eager-boot walk (`register_event_handlers` pattern) — construction through the container, so ctor injection is available if a listener ever needs a dependency.
- Commands self-send; `extract_correlation` has already stashed `__event_id` as causation before `send()` runs — the event→command causation chain is correct for free.
- One event may have MANY listeners — fan-out is the reason the event hop exists. This is why the mapping does NOT live on the event class (a fact must not prescribe its response; consumers own reactions; cross-plugin consumers cannot edit the publisher's contract class).
- The class form is THE convention — not a choice against a closure-binding alternative. Deciding argument: observability. Class listeners are enumerable topology (walk the namespace, read `get_event_class()` → the dashboard draws event→command edges) and name themselves in ceremony error logs (`RecordBoardOutcomeOnResolved`, not `Closure@bootstrap.php:47`). The `OnX` naming convention cred already uses is the topology label.
- Internally, the base ctor delegates to a single procedural ceremony primitive — `integration_listener(string $event_class, callable $translate)` — so the framework has exactly one ceremony implementation. The primitive remains available as an escape hatch but is not the documented path (manual wiring timing, no DI, invisible to tooling).

Legacy path-1 positional handlers (`integration_action()` helper) remain untouched — the `array_is_list()` gate in `extract_correlation` already separates positional (spread) from named (single assoc arg) payloads.

### 5.2 `AsyncWordPressActionHandler` deprecated

The existing async handler was designed as an "async domain handler" — same bounded context, deferred purely for time-budget reasons. The instinct (defer heavy same-context reactions; keep the raising handler ignorant of them) is correct and fully supported — the flaw was the vehicle. The AS hop is another *time*, not another thread: the deferred code observes later state, not raise-time state, and the serialization boundary forces its params into record-land regardless of intent (`as_enqueue_async_action` json-mangles domain params; `from_args` is a hand-rolled `from_payload`; the `async_` hook prefix is a hand-rolled `integration_action`; AS args are a hand-rolled outbox minus durability, correlation transport, and sequence).

**The integration surface crosses TIME, not context.** Same-context deferred reactions are its most common case, not an abuse — integration with your own future has the same physics as integration with another plugin. EventStorming policies are usually same-context ("whenever a user joins an agency, backfill retro accreditations" is a statement about ONE context). Fork per the §3.2 decision rule: if the reaction is a named business policy a domain expert would state as a standing rule → scalar event + listener + command (also buys a durable record future subscribers can attach to); if it is mere implementation deferral → dispatch an async command directly and skip the event.

Its legitimate use cases decompose as: `IntegrationListener` (one-line policy) + command handler (the body, with its dependencies). This is a strict upgrade: the deferred work — currently invisible to `command_audit` — becomes a command with an audit row, `__event_id` causation, and retry. Deprecated in 0.2.0 with migration per handler (cred has one: `IssueRetroactiveAccreditationsOnAgencyJoinHandler`); removal in 0.3.0.

### 5.3 The raw-hook escape hatch (documented, sanctioned)

The integration surface is a WordPress action at bottom — `add_action($event::integration_action(), $fn)` always works and cannot (and should not) be prevented. This is the **interop lane**: a consumer with no tangible-ddd dependency (vanilla plugin, theme, mu-plugin, third party) subscribing to our published facts without coupling to the framework.

Two forms: **class-static** — `add_action(Integration\EarningIssued::integration_action(), $fn)` — rename-safe, but fatals if the publisher plugin is absent (guard with `class_exists()`); **hardcoded string** — `add_action('tgbl_cred_integration_earning_issued', $fn)` — zero coupling, degrades to a silent no-op when the publisher is gone. Pick by whether the integration is required or optional. Neither uses a framework API.

What a raw subscriber receives: ONE array arg — the named codec payload with transport keys still inside (`['earning_id' => 812, '__correlation_id' => …, '__sequence' => …, '__event_id' => …]`); `extract_correlation` only runs inside framework wrappers. Guidance: ignore `__`-prefixed keys; treat the rest as the contract.

What they forfeit (document, don't mitigate): typed hydration, correlation/causation restoration (their work is invisible to `command_audit`), retry/audit semantics, topology membership.

Two framework obligations created by sanctioning this:
1. **Payload keys are public API.** Codec keys = ctor param names; once published, renaming a param of a published `IntegrationEvent` is a cross-plugin break. Param-name stability discipline applies to published events.
2. **Observability, not prevention.** `$wp_filter` enumerates all callbacks per action — the DDD dashboard can list foreign subscribers on every integration action. The hatch is detectable; escape-hatch users appear as observed strangers rather than dark matter.

Layer placement note: integration listeners are EventStorming *policies* ("whenever [fact], then [intention]") — stateless siblings of the stateful `LongProcess`. They live in `Application\IntegrationListeners\`, beside `Application\EventHandlers\` (same-moment, transactional choreography — e.g. datastream's `MatchSubscriptionsOnCapture`, which must stay a domain handler because its outbox writes commit atomically with the command's transaction). The decision rule between the two namespaces is the transaction boundary: atomic-with-the-command → domain handler; new unit of work at a later time → integration listener.

## 6. Await mechanisms

### 6.1 `IAwaitMechanism` (strategy VO — replaces hardcoded 1-of-1)

```php
interface IAwaitMechanism {
  /** SQL prefilter — what goes in the waiting_for column */
  public function event_class(): string;

  /** Routing (P2): is this event for THIS process? */
  public function accepts(IIntegrationEvent $event): bool;

  /** Record arrival. Immutable — returns new instance */
  public function accumulate(IIntegrationEvent $event): static;

  /** Cardinality (P1): structurally satisfied — everything ARRIVED? Never judges success. */
  public function is_satisfied(): bool;

  /** What the post-await step receives as its 2nd parameter */
  public function resume_argument(?IIntegrationEvent $last_event): mixed;
}
```

The mechanism is self-contained: spec AND gathered state in one VO, serialized polymorphically (`{_class,_data}` via `JsonLifecycleValue`) into a new `await_mechanism` column. Authority split: the framework counts arrivals (structural), each entity owns its own outcome, the coordinator's post-await step judges the group.

### 6.2 Implementations

**`AwaitEvent`** — refactored onto the interface, zero behavior change. `accepts()` = the current `event_matches_criteria` logic (moves out of the runner into the mechanism); `is_satisfied()` = true after one `accumulate`; `resume_argument()` = the event itself, so existing 2-param steps are untouched.

**`AwaitAll`** — the fan-in:

```php
final class AwaitAll implements IAwaitMechanism {
  public function __construct(
    public readonly string $event_class,
    public readonly array $expected,         // keys the saga minted (its ledger)
    /** @var array{class-string, string} static-method ref — serializes as two strings */
    public readonly array $key_by,
    public readonly int $timeout_seconds,    // REQUIRED — no default
    public readonly string $on_timeout = self::TIMEOUT_FAIL,  // or TIMEOUT_PROCEED
    public readonly array $gathered = [],
  ) {}

  public function accepts(IIntegrationEvent $e): bool {
    $key = ($this->key_by)($e);   // static extractor announced at construction
    return in_array($key, $this->expected, true)
      && !in_array($key, $this->gathered, true);   // idempotent redelivery
  }

  public function is_satisfied(): bool {
    return count($this->gathered) >= count($this->expected);
  }

  public function resume_argument(?IIntegrationEvent $last): mixed {
    return $this;   // coordinator sees gathered/expected — partial detection on timeout
  }
}
```

The routing key is extracted by a **static method on the process itself**, announced per-await at construction (owner design 2026-07-04 — replaces an earlier `Gatherable` interface on the event):

```php
protected function submit_to_boards(): Result {
  return new Result(await: new AwaitAll(
    event_class: Integration\EndpointRequestResolved::class,
    expected:    $request_ids,
    key_by:      [self::class, 'resolution_key'],
    timeout_seconds: WEEK_IN_SECONDS,
  ));
}

public static function resolution_key(Integration\EndpointRequestResolved $e): int {
  return $e->request_id;   // typed param — property typos caught by static analysis
}
```

- Routing is set-membership on ids the saga itself minted (held in its payload ledger). No reverse index, no coordinator id on domain entities.
- The buffer persists the **id set only** — verdict data is re-read from durable rows post-await (terminal state cannot regress; the read is authoritative).
- The extractor is a static method reference, not a closure and not an event interface, because: closures cannot serialize under snapshot-resume, while `[class, method]` is two strings; a static keeps the event **totally consumer-ignorant** (no `Gatherable` badge — a fact must not know its consumption patterns) and puts 100% of gathering knowledge on the process (ids, recognition, extraction, cardinality, timeout — all in the mechanism, all in the process row); per-await extractors allow different sagas (or sequential awaits in one saga) to key the same event type differently, and each is named for what it extracts at its construction site. Hydrate validates `is_callable`; the suspend-time guard fails fast. A single announced-name convention (one fixed static doing `instanceof` switches) was rejected: wide param defeats static analysis, and every new await edits a shared method.
- Deferred (YAGNI): `AwaitAny`, `AwaitKofN`, multi-event-class mechanisms. One `event_class()` per mechanism.

### 6.3 Runner surgery

`resume_on_event` — the one irreducible change (today it always advances on first match):

```php
foreach ($waiting as $process) {
  $mechanism = $process->await_mechanism();
  if (!$mechanism->accepts($event)) continue;

  CorrelationContext::with($process->correlation_id(), function () use (...) {
    $updated = $mechanism->accumulate($event);

    if (!$updated->is_satisfied()) {
      $process->update_await($updated);    // persist gathered, STAY suspended
      $this->repository->save($process);
      return;
    }

    $process->advance_step();              // satisfied — same path as today
    // ... existing resume flow
  });
  return;   // first-accepting-process semantics kept — key sets disjoint by construction
}
```

`execute_step` changes one line: 2-param steps receive `$mechanism->resume_argument($this->resume_event)`.

**Concurrency:** two AS workers delivering two gathered events simultaneously would race read-modify-write on `gathered` (lost arrival → saga waits forever). Accumulate+save is wrapped in a MySQL named lock: `GET_LOCK('ddd_process_{id}', 5)`.

**Timeout (mandatory — every serious prior-art system has one on a join):** `suspend_for_event` additionally schedules `as_schedule_single_action(now + timeout, 'ddd_await_timeout', [process_id, step_index])`. On fire, if the process is still suspended at the SAME step_index (stale-timer guard — no unscheduling needed): `TIMEOUT_FAIL` → `begin_compensation('await timed out')`; `TIMEOUT_PROCEED` → advance with the partial mechanism as `resume_argument` — the coordinator sees `gathered < expected` and judges.

**Timeout semantics: pure wall clock** from the moment of suspension. Deliberate: no pause-aware accounting. Known interaction with the outbox-pause design (`docs/outbox-pause-design.md`): an outbox pause freezes event delivery but NOT the timeout alarm (it is a direct AS action, not an outbox row) — a pause that outlives a saga's remaining timeout budget will fire the timeout against events sitting undeliverable in the frozen outbox. Accepted as an operational caveat, not engineered around: pause-aware snooze was considered and rejected (exact unpaused-time accounting is unimplementable for derived holds, which are live predicates with no interval history; approximations add machinery for a rare, ops-visible window). Ops rule of thumb: before a long pause, know your suspended sagas' deadlines — `TIMEOUT_PROCEED` coordinators will judge partial sets, `TIMEOUT_FAIL` coordinators will compensate.

### 6.4 Resume plumbing repair & await registration

- `from_payload` becomes a real contract (codec, §4) — no more phantom method.
- The runner's hook callback unwraps transport keys and stashes `__event_id` causation before hydration (same discipline as path 1). The unwrap logic is hoisted so both the WordPress helper and the runner share it without the Application layer depending on the WordPress namespace.
- `register_event` guards that the awaited class is a (new-species) `IntegrationEvent` — fail at registration, not at wake.
- **`#[Awaits(Event::class)]` class attribute replaces per-saga YAML.** Today, awaited events must be declared in the consumer's services.yaml as tag attributes (`awaits: [...]`) — but the `_instanceof` blanket tag that auto-registers every `LongProcess` cannot carry per-service attributes, so each saga needs a hand-written YAML entry whose omission is silent-fatal (event fires into the void; saga sleeps forever — the footgun `AwaitEvent`'s docblock warns about). Fix: awaits knowledge moves onto the process class as a repeatable `#[Awaits]` attribute (joining the existing `#[Async]`/`#[Compensates]` idiom); `register_processes_from_container` reads it via reflection during the boot walk it already performs. Per-saga YAML drops to zero lines. The YAML `awaits:` tag path stays supported but undocumented. (Deriving awaits from step bodies was rejected — the awaited class is a runtime value; the attribute is the honest static declaration.)
- **Suspend-time guard** (closes residual drift between attribute and step): `suspend_for_event` throws `AwaitedEventNotRegistered` if the mechanism's `event_class()` is not in the runner's `registered_events` map — deterministic, loud, at first suspension in dev, instead of an eternal silent sleep in prod.

### 6.5 Persistence

- New column `await_mechanism` (polymorphic JSON). Additive migration.
- `waiting_for` **stays** — it is the SQL prefilter; `find_waiting_for` unchanged.
- `match_criteria` becomes legacy: on hydrate, if `await_mechanism` is NULL but `waiting_for` is set, reconstruct `AwaitEvent(waiting_for, match_criteria)`. Zero migration — old suspended rows resume fine.

## 7. Versioning & migration

Composer pins are currently loose (cred: `"tangible/ddd": "@dev"`, datastream: `"*"`) — consumers float to whatever branch the path repo has checked out. A release branch alone gates nothing. Belt and suspenders:

1. **Branch:** `release/0.2.0`. Consumers move to explicit pins (`dev-release/0.2.0`) as part of this change.
2. **No runtime config gate, no CI sweep** (see §4): TypeError at raise + `scalarise()` throw at first publish are the enforcement. Nothing to configure.
3. **This release is deliberately reverse-incompatible** (owner ruling 2026-07-04): consuming plugins are obligated to rewrite their fat events as proper integration events in the same release that bumps the dep. No parking lane, no grandfather type.

| Change | Compat |
|--------|--------|
| await machinery (columns, classes) | additive |
| codec via base implementations | additive |
| `payload()` abstract → codec default | non-breaking — existing overrides win |
| runner path-2 repair | fixes a never-worked-in-prod path |
| **`IntegrationEvent` redefinition + `scalarise()` strictification** | **breaking** — every fat event rewritten; wire payload shape changes (positional → named) |

**Migration inventory (verified 2026-07-04).** tangible-datastream: defines zero integration events → unaffected as publisher. tangible-cred: 9 events, and the decisive finding is that **all 5 of its path-1 integration consumers (`includes/hooks/integration/`) are already thin scalar listeners** (`fn(ids) → Command->send()`) — nobody consumes fat objects across the boundary. Per event:

| Event | Integration consumers | Migration |
|-------|----------------------|-----------|
| `EndpointAuthRefresh(int, int)` | RefreshEndpointAuthCommand listener | already compliant — named payload + listener class |
| `EndpointRequestSent($request, $attempt, $extra)` | ProcessEndpointResponseBehaviour listener (uses ids only) | scalar-native rewrite `(request_id, attempt_id, extra)` |
| `BehaviourWorkflowReschedule($workflow, ...)` | ProcessEndpointResponseBehaviour listener (uses ids only) | scalar-native rewrite |
| `ReportingEndpointsReschedule(array)` | ReportEarningsToEndpointsScheduled listener (decodes json itself) | scalar-native (array of reversibles) |
| `EarningIssued($earning)` | ReportEarningToEndpointsOnTheFly listener (uses id only) | scalar-native `(earning_id)` — or twin if domain handlers need the object (check at migration) |
| `License*` ×4 (`$license`) | **none found** — firing into the void | **demote to `DomainEvent`** (delete integration surface) or twin if the notifications bridge claims them (check `tangible-notifications-pro` sources at migration) |

Also in the same migration: the 5 hook-file registrations convert to `IntegrationListener` classes (they are already the right shape — mechanical); the notifications-pro bridge (`includes/sources/tangible-cred/`) is audited for which cred integration actions it consumes.

**Deploy choreography (required — wire shape changes):** pending outbox rows and in-flight AS actions at deploy time carry OLD payload shapes. Per site: pause/drain the outbox (the companion `outbox-pause-design.md` machinery, or simply deploy in a quiet window and let the outbox drain first), deploy framework + consumers together, resume. Old undelivered rows after a botched window are recoverable by hand (payloads are readable JSON) but there is no automatic shape-translation.

## 8. First consumer (separate spec, tangible-cred)

Recorded here for context; NOT in scope of the framework plan:

- New terminal event `Integration\EndpointRequestResolved { request_id: int, outcome: ReportingOutcome }` — pure scalar record, implements nothing await-related; fired once when a request reaches terminal state. The saga keys it via its own `resolution_key()` static. ("Request resolved" is a real domain fact distinct from "attempt sent"; `EndpointRequestSent` fires per-attempt and is rewritten scalar-native per §7.)
- `MultiBoardReportingProcess`: `submit_to_boards` creates request rows synchronously via service (it needs the ids for its ledger; creating rows ≠ sending — the existing cron batching boundary is untouched) → `Result(await: new AwaitAll(Integration\EndpointRequestResolved::class, expected: $request_ids, timeout_seconds: WEEK_IN_SECONDS, on_timeout: AwaitAll::TIMEOUT_PROCEED))` → `evaluate($payload, AwaitAll $gather)` re-reads the rows by id and judges the group → throw → `#[Compensates]` voids accepted boards.
- Kills (already designed out): `origin_saga_id` entity field + column (parked commits `0b05e46`, `6dcb7d9`, `7b9aafa` to revert), external aggregator, tally table, synthetic group event.

## 9. Testing strategy

- **Codec:** property test — round-trip law over every registered integration event class; coercion cases (enum, datetime, nullables); `scalarise()`/`hydrate()` throws on non-reversible/un-coercible input.
- **Taxonomy:** `scalarise()` throw on non-reversible value (declared-fat fixture); TypeError-at-raise fixture; router branch for `IAnnouncesIntegration`; demoted events no longer reach the outbox.
- **AwaitAll:** unit — accepts/accumulate/is_satisfied including duplicate redelivery and unknown keys; runner — partial arrival persists and stays suspended, satisfaction advances, first-accepting-process routing with two suspended sagas and disjoint key sets; timeout — stale-timer guard, both policies; hydration fallback for legacy `waiting_for`+`match_criteria` rows.
- **Listener:** `get_command()` as pure function; ceremony test — wrapped payload in, command dispatched with event causation.
- **End-to-end (the test that was always missing):** raise event → outbox → publisher → AS hook fires with wrapped payload → process actually wakes. Through the REAL pipe, not a direct `resume_on_event` call.

## 10. Rejected alternatives (decision record)

| Alternative | Why rejected |
|-------------|--------------|
| `origin_saga_id` on domain entity | coordinated thing pointing back at coordinator — backwards arrow |
| external aggregator / tally table / synthetic group event | infrastructure to work around a missing framework primitive |
| closure predicates (Temporal-style `await(fn)`) | snapshot-resume cannot serialize closures; predicate must be reified data |
| `Gatherable::is_terminal()` | wake-side event carries entity as id only (scalarised) — cannot ask the object; solved by a dedicated terminal event |
| `Gatherable` interface on events | superseded (owner design 2026-07-04) by per-await static extractor `key_by: [Process::class, 'method']` — event stays consumer-ignorant, all gathering knowledge on the process, serializes as strings, per-process keying possible |
| `EventCodec::verdict()` + `off\|warn\|strict` config gate | pre-flight verdict re-derives what `scalarise()` discovers; hard break's lockstep migration leaves nothing to gate |
| standalone `EventCodec` class | folded into the `IntegrationEvent` base (owner ruling 2026-07-05) — no consumer needed a detached engine (listeners/runner/replay all call `$class::from_payload()`); restores v0.1 topology (`scalarise()` always lived on the base); "class is the schema" completes: the class owns its own codec |
| `Testing\EventConformance` CI sweep | correctness is by construction — scalar-declared ctors make illegal values a `TypeError` at raise; illegal declarations throw at the scalarise membrane on first publish (incl. author's first local test); sweep's residual value (declared-fat event with zero publish coverage) is a nil window in-house |
| key extraction via property-name string | typo = saga silently never resumes; no fail-fast |
| repo-loading `from_payload` (rebuild entities) | event becomes a query; temporal gap; Domain doing Infra reads |
| event-carried state transfer (ship entity snapshots) | stale truth duplication; bloated AS args; entities reconstructed outside repo invariants |
| `lossy_legacy()` shame marker | misread the situation — fat events are correct v0.1 usage; shame for following the API is wrong (superseded twice: first by the `BroadcastEvent` split, then by the hard break) |
| `BroadcastEvent` legacy species (parking lane) | owner ruling 2026-07-04: hard break instead — all consumers in-house; inventory showed integration consumers are already thin scalar listeners (fat payloads unconsumed); two species = permanent cognitive tax + invitation to keep writing fat events |
| `Contracts\` sub-namespace | PHP convention says contracts = interfaces; bounded context already has the term "integration event" |
| static `integration_class()` member on events | duplicates what the `to_integration()` return type declares; two sources of truth drift |
| `to_command()` on the event (merged listener) | consumer knowledge in the producer's artifact; a fact must not prescribe its response; breaks fan-out (N listeners per event) and cross-plugin consumers |
| framework-wired (non-self-wiring) listeners | uniformity with `WordPressActionHandler` won; the testable part (`get_command`) is pure either way |
| closure-binding listeners as public API | topology invisible to dashboard/tooling; anonymous in error logs; manual wiring timing contract; no DI; god-registry drift — survives only as the internal ceremony primitive the class base calls |
| `IIntegrationConvertible` / `IHasIntegrationContract` / `IIntegrationSource` naming | final name = `IAnnouncesIntegration` (owner ruling 2026-07-04: "announce" = declaration of intent that a record will exist, not the publishing act — no agency misattribution); `IIntegrationSource` dead regardless (event-sourcing connotation + datastream `IEventSource` collision) |
| `AwaitAny` / `AwaitKofN` / multi-class mechanisms | YAGNI — no driving use case |
| pause-aware timeout (snooze while outbox frozen) | derived holds have no interval history → exact unpaused accounting impossible; approximations = machinery for a rare ops-visible window; wall clock + documented ops caveat wins |
