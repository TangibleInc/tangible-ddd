# Consumer release and migration ledger — through 0.6.4

> **Status: CURRENT RELEASE LEDGER.** The filename is retained for inbound
> links. Read the entry for every version between the consumer's installed
> package and its target, then verify against the target source and tests.

The framework can move ahead of Cred, Datastream, LMS, and Quiz; each consumer
migrates on its own schedule, but WordPress loads one winning Tangible DDD copy
for the whole request. A newly bundled version must therefore remain compatible
with every plugin on the same site, not only the plugin that carries it.

The earliest entries preserve their contemporaneous migration language. In
particular, the old rule that 0.2.x changes were additive was true for that
release line; later flag-day and shim-purge entries supersede it.

---

## 0.6.1 correctness rider — seal keys on IAnnouncesIntegration

**Mandatory for consumers: NOTHING** — a correctness fix, fully additive.

The per-command seal (`EventsUnitOfWork::record()`) rejected any post-seal
event that wasn't an `IIntegrationEvent`. That was written pre-0.2.0, when
`IIntegrationEvent extended IDomainEvent` — so it meant "let integration-capable
domain events through the seal." The 0.2.0 taxonomy split **severed**
`IIntegrationEvent` from `IDomainEvent` and made `IAnnouncesIntegration` the
raisable "integrable" marker (`IIntegrationEvent` became the scalar twin/record
contract), but the seal condition was left testing `IIntegrationEvent` —
silently narrowing the exemption to self-publishers and wrongly rejecting
**twin-style announcers** (a fat domain event implementing `IAnnouncesIntegration`
whose `to_integration()` returns a separate scalar twin) raised in a sealed
context.

The seal now keys on **`IAnnouncesIntegration`** (the `IIntegrationEvent` clause
in the `AlreadyIntegrated`/`PublishedFacts` re-raise guard is unchanged — that's
a different concern). This mirrors `EventRouter`'s routing gate: **raised past
the seal ⟹ routed to the bus.** It vindicates the twin pattern — a fat
`IAnnouncesIntegration` domain event with a scalar twin is now first-class
raisable in a sealed drain, no self-publisher conversion required.

This correction ships in 0.6.1 alongside compiled `LongProcess` catalog
support. It is independent of the 0.6.2 consumer-module capability.

---

## 0.2.4 (shipped 2026-07-18)

**Mandatory, already done for cred + datastream** (master merges 8fa27f7 /
57f98e2):

- Handlers must not `runner->start()` (throws `ProcessStartedInsideCommand`)
  — announce a fact, give the saga `#[StartsOn]` + static `from_event()`.
- Commands must not dispatch commands in-band (`CommandDispatchedInsideCommand`).
- ⚠️ **lms/quiz still pinned at 0.2.1**: three sync handlers dispatch
  in-band (SeedDefaultMentorOnUserEnrolled, RevertEnrollmentOnProgressionReverted,
  CompleteEnrollmentOnProgressionCompleted) and would fatal on the throw.
  **No 0.2.4+-carrying deploy to any box hosting lms** — the version-manager
  loads the newest copy process-wide; composer pins do not protect shared
  installs. Their migration (each a real design decision, not mechanical) is
  the prerequisite for everything below reaching lms boxes.

## 0.2.5 (branch fix/0.2.5)

**Mandatory: nothing.** Suites verified green for cred (300) and datastream
(239 + known BootstrapTest) against the full branch on 2026-07-19.

**Behavioral (automatic, no consumer code):**

- Causation is scoped, not one-shot: every command a listener dispatches now
  records the draining event as causation (fat-listener hole closed).
- `ProcessRunner::start()` inside a drain absorbs the armed event as
  `ignited_by_event_id` (manual starts stop minting false roots).
- New throws: `ProcessStartedInsideProcess` (steps must not spawn — spell it
  step → Command → Fact → `#[StartsOn]` child) and
  `FactPublishedInsideProcess` (steps must not announce — return
  `Result(commands: [...])`). Both consumers verified clean.

**Optional modernizations (each independent; do them whenever a consumer is
next touched, or never — but ALL become mandatory at 0.3):**

1. `TransportEnvelope` → `IntegrationEnvelope` (datastream already migrated:
   1159afd; deprecated alias covers everyone else).
2. Old registry names `TangibleDDD\WordPress\ConsumerRegistry` /
   `ConsumerHandle` / `NoConsumerOwnsClass` → `TangibleDDD\Infra\Consumers\*`
   (alias stubs cover; no consumer references them directly today).
3. **Stamp collapse** — delete the eight stamped classes, in any order:
   - `Application/Commands/Command.php` + `Queries/Query.php`: delete the
     `container()` override first (trait default = registry), then the base
     itself once subclasses are repointed — or hollow-shim and leave.
   - `Domain/Events/DomainEvent.php` + `IntegrationEvent.php`: same recipe;
     `Event::prefix()` now defaults to `owner_of(static::class)->prefix()`.
   - `Domain/Shared/JsonLifecycleValue.php` + `DirectJsonLifecycleValue.php`:
     repoint VOs to the framework classes; the renderer resolves from the
     owning consumer's container automatically (never load-bearing).
   - `di/HandlerClassNameInflector.php`: delete; bind
     `tactician.class_name_inflector` to
     `TangibleDDD\Application\CQRS\HandlerClassNameInflector` (byte-identical
     convention).
   - `Infra/Config.php`: optionally replace with
     `new TangibleDDD\Infra\DDDConfig(prefix:, namespace_root:, version:)`
     in the boot declaration + a parameterized `DDDConfig` service in
     services.yaml (see the scaffolder's current templates for the exact
     shape). Keeping a hand-written config stays legal forever.
   - **Boot with an explicit `namespace_root`** (DDDConfig ctor arg or
     boot() param). Until then the root derives from the stamped Config
     class's namespace — which stops working the moment that class is
     deleted, so root-first, delete-second.
4. Fresh consumers: `wp ddd init` now emits zero classes — membersync and
   later arrivals start on the collapsed shape natively.

## 0.3 (flag-day — spec: docs/0.3-trace-context.md)

Everything above stops being optional, plus the metamodel break. Running
list of consumer-visible debts (append as 0.3 work lands):

- [x] Alias stubs die: `TransportEnvelope`, `TangibleDDD\WordPress\
  ConsumerRegistry`/`ConsumerHandle`/`NoConsumerOwnsClass`. DONE in 0.4.0
  (fleet grep was already clean).
- [x] `restore_context()` replaced by unwrap + scope composition (0.4.0); any raw
  `add_action` integration hook doing manual ceremony must move to the
  helpers (consumers using `integration_action()`/`IntegrationListener` are
  untouched — the helpers absorb the change).
- [x] `stamp_journey()` and the mutable journey slots are GONE (0.3 lane 5).
  Resolution was gentler than predicted: `$event->correlation_id()` /
  `event_id()` survive as deprecated READ-ONLY accessors backed by
  PublishedFacts (null on fresh/hydrated instances; populated at publish) —
  the fleet's tests pinned exactly that contract, no consumer changes.
  RESOLVED in the 0.4.0 sweep: the accessors are deleted; fleet tests read
  `PublishedFacts::id_of()` (the guard's ledger) instead.
- [x] `CorrelationContext` dissolved (0.3 lane 4), then DELETED in 0.4.0 —
  the three shim caller lanes (consumer `get()` reads, `restore_context()`
  writers, test `command_id()`) were repointed to
  `Correlation`/`TraceContext`/`IntegrationEnvelope::trace_context()` in the
  same sweep (cred + datastream; lms/quiz deferred with their pin).
- [ ] lms handler migration (see 0.2.4) is a hard prerequisite: 0.3 requires
  all consumers on ≥0.2.4 semantics.
- [x] **Drop `@CommandAuditMiddleware` from tactician.yaml chains** (0.3
  lane 1, 2026-07-19): the audit record moved into the act bracket
  (CorrelationMiddleware). DONE in 0.4.0 — the class is deleted; cred and
  datastream chains repointed; lms/quiz chains keep the line until their
  pinned 0.2.1 copy is migrated (it is load-bearing there).

## 0.4.0 (shipped 2026-07-19 — THE SHIM PURGE)

Every deprecated surface the 0.3 dissolution left standing is deleted.
**Mandatory for consumers — a plugin still referencing any of these fatals
at boot (container compile) or at call time:**

- `CorrelationContext` — class GONE. Reads become facade reads
  (`Correlation::current()->correlation_id` where a scope is guaranteed,
  `Correlation::peek()?->correlation_id ?? Uuid::v4()` in fallbacks);
  drop its `services.yaml` registration.
- `CommandAuditMiddleware` — class GONE. Remove from `tactician.yaml`
  chains (the bus chain starts with CorrelationMiddleware) and from
  `services.yaml`.
- `IntegrationEnvelope::restore_context()` — method GONE. Compose
  `Correlation::within($envelope->trace_context()->for_fact(...), $body)`
  (datastream's `DeliveryOutboxPublisher` is the reference).
- `TransportEnvelope` + `TangibleDDD\WordPress\ConsumerRegistry`/
  `ConsumerHandle`/`NoConsumerOwnsClass` alias stubs — GONE; import
  `IntegrationEnvelope` / `TangibleDDD\Infra\Consumers\*`.
- `infrastructure_action()` callbacks now run inside a real facade scope
  (carried correlation + causation as the ambient Cause); no legacy statics
  are armed. Consumers of the helper need no changes.

Done in lockstep 2026-07-19: **cred** (8e772be) and **datastream** (8518b8f)
repointed on their masters. **lms/quiz NOT migrated** — pinned to ddd 0.2.1
where these classes are load-bearing; their repoints fold into the
already-scheduled lms handler migration (0.2.4 prerequisite + these purge
items, one pass).

Second wave (same sweep, owner ruling "afuera"): the deprecated read-only
`correlation_id()`/`event_id()` accessors on IntegrationBehaviour are GONE
(consumer tests read `PublishedFacts::id_of()` — cred's ten per-event tests
and datastream's DeliveryEscalationTest repointed); `PublishedFacts` shrank
to instance → event_id (the `correlation` field existed only for the dead
accessor). Also deleted: `AsyncWordPressActionHandler` (deprecated 0.2.0,
zero subclasses fleet-wide), `ProcessRunner::register()` (no-op; its
LongProcess validation moved inline into the then-current runtime tag
discovery, which throws on a mis-tag), and the internal
`extract_correlation()` helper (ceremonies
unwrap inline; the list/assoc spread contract is pinned through
`integration_action()` itself). `Cause::causation_type()` stays by ruling
(columns outlive fashions).

**Deploy rule:** the first 0.4.0-carrying deploy to a shared box must carry
BOTH repointed cred and datastream (version-manager loads the newest ddd
copy process-wide — an unrepointed neighbor fatals). The standing "nothing
0.2.4+ to lms boxes" rule already covers lms.

## 0.5.0 (the touches lane — spec appendix 9, declared write-set)

**Mandatory for consumers: NOTHING.** Fully additive: the `{prefix}_touches`
table auto-installs via schema v6 on the migrations lane; unannotated
events behave exactly as before (no touches key in the audit JSON, no rows).

**Optional modernization:** annotate lifecycle-declaring events —

    #[Touches(Op::Created, License::class, id: 'license_id')]

Class refs, not strings (IDE/PHPStan via class-string<Aggregate>); `id:`
optional when the `{canonical_name}_id` convention holds. ⚠️ On the first
RENAME of an annotated aggregate class, pin `canonical_name()` to the
historical string (at-rest names outlive class names). The conformance
scan consumers already run validates declarations automatically (bad
aggregate ref or unresolvable id = suite failure). Coverage is opt-in and
grows organically — the biography is only as complete as the annotations
(the declared-side blindness is by design; the observed collector is a
separate, unbuilt decision).

### 0.5.1 (integrity fixes — codex audit)

Mandatory: nothing. Twin-style consumers (cred): stamp the TWIN (the
announced record). The old source-event fallback applied to the 0.5.1 harvest
lane only and is superseded by 0.5.2: once harvesting moved to the outbox bus,
only the published integration record is inspected. Framework-only fix: the
shared query-bus yaml dropped the act bracket (consumer yamls were already
clean — verify yours has no CorrelationMiddleware in tactician.query_bus).

### 0.5.2 (the harvest moves to the bus)

Mandatory: drop the hand-listed `arguments:` from your
`OutboxIntegrationEventBus` wiring (ctor gained IDDDConfig — autowire
handles it; cred/ds already updated in lockstep). Gains: announce-lane
facts get biography rows; twins index with no association machinery;
touches write independent of the audit toggle.

### 0.5.3 (no duplication)

Mandatory: nothing. The audit events JSON is a name roster again; touches
live only in `{prefix}_touches` — join on `command_id`.

## 0.6.0 (RELEASED — self-handling commands AND queries + loader identity fix)

**Loader identity fix (fleet-relevant, zero consumer action):** since 0.2.5
every release's copy registered itself into the version-negotiation loader
under the frozen key `'0.2.4'` (and the frozen function slugs `_0_2_4`), so
"newest copy wins" silently degenerated to "first-loaded wins" among any set
of 0.2.5–0.5.x copies. 0.6.0 registers as `'0.6.0'` with `_0_6_0` slugs, and
`LoaderIdentityTest` now asserts header == constant == register literal ==
slugs — a release with stale identification can no longer pass its own suite.
Deploy note: a 0.6.0 copy correctly outranks every earlier copy INCLUDING the
mislabeled 0.2.5–0.5.x ones (0.6.0 > 0.2.4); boxes mixing only 0.2.5–0.5.x
copies (no 0.6.0) remain load-order-dependent until upgraded.

**Mandatory for consumers: NOTHING.** Fully additive and opt-in. The
command/handler and query/handler two-class shapes stay 100% legal — a plain
command or query still routes to its convention-named handler exactly as
before. Nothing to migrate, nothing deprecated.

**⚠️ THE CONSUMER-ROUTING FIX (if you read the earlier branch state):** as
first built, `SelfHandlingCommand` extended the framework's self-consumer
`Command` base — whose `container()` override pins
`TangibleDDD\WordPress\SelfConsumer\di()`. A CONSUMER command extending the
base would therefore have dispatched through the FRAMEWORK's bus and resolved
its handle() deps from the framework's container, where consumer services do
not exist. Fatal. The base no longer pins the self-consumer container: it is
a standalone `abstract class SelfHandlingCommand implements ICommand { use
CommandBusAware; }`, so `container()` falls through to the trait's registry
default (0.2.5c) — `ConsumerRegistry::owner_of(static::class)->container()` —
and a consumer's self-handling command rides its OWN bus and container.
`SelfHandlingQuery` was born with the same shape (QueryBusAware's identical
default). No consumer ever shipped against the pinned state; nothing to do.

**What shipped:**

- `SelfHandlingCommand` — the command carries its own
  `protected function handle(...$deps)`; `SelfExecutingCommandMiddleware`
  slotted into the command onion immediately before
  `tactician.middleware.command_handler` (so self-handling commands still get
  the act bracket, transaction middleware, and domain-event publishing; the
  stock middleware opens a database transaction only when the command also
  implements `ITransactionalCommand`).
- `SelfHandlingQuery` (additive, opt-in) — the read-side twin. The SAME
  middleware (explicit union check, no new marker interface) is slotted into
  the QUERY bus immediately before `tactician.middleware.query_handler`, and
  is terminal for a self-handling query. THE ASYMMETRY IS THE POINT: a
  command's handle() stays void-by-default (the receipt rule); a query's
  handle() RETURNS the read result — returning data is what a query is. The
  query chain stays read-shaped: no CorrelationMiddleware, no act bracket.

The framework's own `di/tactician.yaml`, `self/tactician.yaml` (which gained
its first query lane with this), and the scaffolder's tactician template
already carry both slots. Consumers who ran the scaffolder BEFORE 0.6.0 and
want the feature add three lines to their `di/tactician.yaml`:

    League\Tactician\CommandBus:
      arguments:
        - '@TangibleDDD\Application\Correlation\CorrelationMiddleware'
        - '@TangibleDDD\Application\Persistence\TransactionMiddleware'
        - '@TangibleDDD\Application\Events\DomainEventsPublishMiddleware'
        - '@TangibleDDD\Application\CQRS\SelfExecutingCommandMiddleware'   # add
        - '@tactician.middleware.command_handler'

    tactician.query_bus:
      class: League\Tactician\CommandBus
      public: true
      arguments:
        - '@TangibleDDD\Application\CQRS\SelfExecutingCommandMiddleware'   # add
        - '@tactician.middleware.query_handler'

      # add (explicit @service_container — not autowired by type):
      TangibleDDD\Application\CQRS\SelfExecutingCommandMiddleware:
        arguments: ['@service_container']

(Consumers without `_defaults: autowire` must register the service explicitly
as shown; those with it still want the explicit `@service_container` argument.)

**Optional modernization:** collapse a thin, single-dependency
command/handler pair into one class —

    final class RecordThing extends SelfHandlingCommand {
      public function __construct(private readonly int $thing_id) {}
      protected function handle(ThingRepository $repo): void {
        $repo->touch($this->thing_id);
      }
    }

— and the query twin —

    final class FindThing extends SelfHandlingQuery {
      public function __construct(private readonly int $thing_id) {}
      protected function handle(ThingReadModel $things): ?ThingView {
        return $things->find($this->thing_id);
      }
    }

    $view = (new FindThing(42))->send();

`handle()`'s params are method-injected from the container by reflection;
`protected` keeps it uncallable except by the middleware. A COMMAND's
`handle()` stays VOID by default (the receipt rule) — it MAY return a
scalar/DTO verdict for transport steering, MUST NOT return domain objects,
and nothing downstream may depend on the return. A QUERY's `handle()` returns
the read result, and `send()` hands it back — no receipt rule for reads. Keep
the separate-handler shape for dependency-heavy handlers; it is not going
away.

## 0.6.1 (compiled LongProcess catalog compatibility patch)

0.6.1 makes the existing `ddd.long_process` contract work in Symfony
`PhpDumper` containers. A dumped runtime container has no service definitions
or `findTaggedServiceIds()`, so 0.6.0 silently skipped `#[Awaits]` and
`#[StartsOn]` registration there even when the same process worked against a
development `ContainerBuilder`.

0.6.1 also corrects the event-unit-of-work seal after the 0.2 taxonomy split.
The sealed drain now admits every domain event implementing
`IAnnouncesIntegration`, including a rich event that announces a separate
scalar twin. The old `IIntegrationEvent` check admitted self-publishers but
incorrectly rejected twin-style announcers. This is an additive correctness
fix; consumers do not need to change their event classes.

Consumer facades must still resolve the live container-managed
`EventsUnitOfWork` rather than cache it statically. A stale cached object is a
separate consumer wiring bug: middleware may reset and seal one instance while
the facade records into another.

0.6.1 also corrects the scaffolder's main-plugin snippet. The Tangible DDD
loader registers copies at `plugins_loaded:0` and initializes the winner (its
class autoloader plus `TangibleDDD\WordPress\boot()`) at priority 1. Requiring
the generated `ddd-wordpress/di/index.php` immediately from a plugin file can
therefore fatal before either `DDDCompilerPasses` or `boot()` exists. Fresh
scaffolds wrap that require at `plugins_loaded:10`; existing consumers already
using a priority-10 bootstrap need no change:

    add_action('plugins_loaded', static function (): void {
        require_once __DIR__ . '/ddd-wordpress/di/index.php';
    }, 10);

**Mandatory for consumers that compile or dump a container:**

1. Require `tangible/ddd:^0.6.1` after the tag is published.
2. Register `DDDCompilerPasses` after loading YAML and before `compile()` in
   every development, integration-test, and release-build construction path:

       use TangibleDDD\Infra\DependencyInjection\DDDCompilerPasses;

       $loader->load('tactician.yaml');
       $loader->load('services.yaml');
       DDDCompilerPasses::register($container_builder);
       $container_builder->compile();

3. When the consumer has processes, register its namespace as discovery-only
   definitions and retain the framework tag rule:

       services:
         _instanceof:
           TangibleDDD\Application\Process\LongProcess:
             tags: ['ddd.long_process']

         Acme\Application\Process\:
           resource: '../../ddd-src/Application/Process'
           autowire: false
           shared: false
           public: false

The compiler pass validates and de-duplicates class names, carries legacy
`awaits:` tag attributes into a public `LongProcessCatalog`, and never resolves
a process definition. At runtime `register_hooks()` prefers the catalog and
reflects `#[Awaits]`/`#[StartsOn]`; retained `ContainerBuilder` consumers with
no compiler pass still use the public `register_processes_from_container()`
fallback. Runtime/late side-plugin registration is not part of 0.6.1 and is
reserved for 0.6.2.

**Post-tag rollout order:**

1. Publish Tangible DDD `v0.6.1`.
2. Register the passes in Cred and Datastream, make their process definitions
   private, update dependency metadata, and replace the local `0.2.9999`
   version with `0.6.1`.
3. Register the passes in LMS/Quiz development, integration-test, and shared
   `bin/build-php` release compilation. Do not add empty Process resources
   until either plugin introduces its first process.
4. Update LMS/Quiz constraints and lockfiles against the published tag.
5. Rebuild both production containers and verify their catalogs with
   `WP_DEBUG=false`; generated containers remain uncommitted artifacts.

## 0.6.2 (consumer modules)

0.6.2 depends on the compiled-catalog behavior introduced in 0.6.1. It adds a
supported way for separately deployed code to define host-native commands,
queries, events, listeners, and `LongProcess` types without mutating the host's
compiled container or creating another persistence identity.

**Mandatory for existing consumers: nothing** unless the consumer will host a
module. Existing top-level consumers continue to own their own config,
container, tables, workers, migrations, and dddash entry.

**New public surfaces:**

- `TangibleDDD\WordPress\boot_module($host_prefix, $namespace_root, $di_getter)`
- `ConsumerRegistry::consumer($prefix)`
- `ConsumerRegistry::config_for($prefix)`
- get-only `ConsumerRegistry::service_for($prefix, $service_id)`
- `ConsumerRegistry::add_module(...)`
- `ConsumerRegistry::modules_for($host_prefix)`

`ConsumerRegistry::all()` remains the top-level consumer list. Module routes
participate in longest-root `owner_of()` resolution but do not become another
dashboard consumer, table prefix, migration lane, or worker set.

**Mandatory for a module-capable host:**

1. Require `tangible/ddd:^0.6.2` once the release is available.
2. Use `boot()` after the framework winner initializes and before
   `plugins_loaded:30`; priority 10 is the generated convention. A host that
   first announces itself through `register_hooks()` at `init:2` is too late.
3. Keep the 0.6.1 `DDDCompilerPasses` registration in every retained and
   dumped-container build path.
4. Expose every stateful service imported by released modules under a stable
   public service ID. The command transaction service is host-specific: LMS
   and Quiz use Doctrine middleware, while stock wpdb consumers use the
   framework transaction middleware.
5. Contract-test the actual dumped host container and those exact public IDs.

**Mandatory for the module/sidecar:**

1. Bundle a compatible 0.6.2 copy and load its Composer autoloader from the
   plugin file so it participates in version negotiation at
   `plugins_loaded:0`. Do not autoload framework runtime classes before the
   winner initializes at priority 1.
2. Build a separate module container, bind `IDDDConfig` through
   `ConsumerRegistry::config_for($host_prefix)`, and call `boot_module()` at
   `plugins_loaded:30` with a strict descendant namespace root.
3. Import the host's exact correlation, transaction, event-publication,
   unit-of-work, outbox, and process objects through `service_for()`. Keep only
   terminal command/query resolution and module application services local.
4. Register `DDDCompilerPasses` in the module build. Private
   `ddd.long_process` definitions compile into its `LongProcessCatalog`.

Module runtime wiring occurs at `init:3`, after host hooks at `init:2`.
Listeners are eagerly constructed from the module container. A non-empty
module catalog is validated and registered on the host's exact
`ProcessRunner`; the host catalog/container are never changed. Identical class
and tag metadata are de-duplicated, while conflicts fail before that module's
listeners or process callbacks run. Missing or empty catalogs are valid for
modules without processes.

**Operational warning:** persisted process rows contain the module process
FQCN. Before deactivating a sidecar or renaming one of its processes, drain,
complete, fail, migrate, or preserve compatibility for every non-terminal row
and its scheduled callbacks. Version 0.6.2 does not automate that migration.

See [Consumer modules](consumer-modules.md) for complete bootstrap, bridge,
routing, dump, failure, and deactivation contracts.

## 0.6.3 (eventing hardening)

The eventing-lanes doctrine lands as enforceable surface (see
[eventing-lanes.md](eventing-lanes.md) for the three raising lanes).

**Mandatory for existing consumers: nothing at require-time** — all additive.
But any consumer adopting 0.6.3 SHOULD, in the same change:

1. Kill consumer-local static event facades. The unit of work is
   container-managed state; adopt `RaisesEvents` (`$this->event()`) with an
   injected `EventsUnitOfWork` instead. `WorkflowHandler` gained an optional
   trailing `?EventsUnitOfWork $events_uow = null` constructor parameter for
   its subclasses.
2. Wire the conformance fences into the consumer suite:
   `IntegrationConformance::pull_events_violations($src)` (absolute — the
   harvest verb is framework-only) and
   `IntegrationConformance::handler_raised_events($src, $allowlist)` (every
   handler-level raise is a reviewed allowlist entry).
3. Heal hydration-records-on-construction: gate birth events on identity
   (null id ⇒ occurrence; persisted id ⇒ load records nothing), or use a
   hydrate-silently door where application code legitimately constructs WITH
   an id to update. `PersistsAggregatesRepository::save()` is now `final`
   (persist → collect_from).

**New public surfaces:** `RaisesEvents`, the two `IntegrationConformance`
fences above, `FactDeliveredUnheard` (infrastructure event fired when an
outbox delivery finds zero listeners — outbox/integration lane ONLY; unheard
DOMAIN moments are not a pathology and get no flag).

Reference adoptions: cred PR #9 (facade removal + fences + `HydratesSilently`),
datastream PR #5 (identity-gated `CapturedEvent::occur()`).

## 0.6.4 (act lane reaches domain-event handlers and self-handling commands)

`WordPressActionHandler` now mirrors `WorkflowHandler`: it uses `RaisesEvents`
and accepts an optional trailing constructor parameter
`?EventsUnitOfWork $events_uow = null`, exposed via `events_uow()`. A
synchronous domain-event reaction can record follow-on facts mid-drain (the
seal admits `IAnnouncesIntegration`; drain-until-empty routes them to the
outbox in the same transaction) without wiring the trait plumbing itself.

`SelfHandlingCommand` carries the lane too — with zero declaration:
`SelfExecutingCommandMiddleware` attaches the owning consumer's live
`EventsUnitOfWork` (the same instance it method-injects from) before invoking
`handle()`, so any self-handling command body may `$this->event()` a
coordination fact. Queries never receive it: reads must not record.
Because the trait arrives invisibly (no per-file `RaisesEvents` mention),
`IntegrationConformance::handler_raised_events()` now also scopes
`Application/Commands/` paths — a raising self-handling command needs an
allowlist entry like any other handler raise.

**Mandatory for existing consumers: nothing.** Subclasses calling
`parent::__construct()` bare keep working; `$this->event()` without a unit of
work throws a `LogicException` naming the raiser rather than dropping the
moment. All raise sites remain subject to the `handler_raised_events`
allowlist fence.

Reference adoption: datastream's `MatchSubscriptionsOnCapture` (the
`EventReadyForDelivery` fan-out, converted from direct bus publication to
self-publishing moments in datastream PR #6).

## How to verify a migration (any version)

- Consumer suite green.
- `wp ddd doctor` green once built (until then: the conformance tests +
  dddash consumer panel + a saga smoke).
- The three-line boot smoke: consumer appears in `consumers()`,
  `owner_of(<some consumer class>)` resolves, framework version reads as
  expected.
