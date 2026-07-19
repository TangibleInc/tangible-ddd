# Consumer migration ledger — 0.2.x → 0.3 → 0.4

**What this is.** The framework is moving ahead of its consumers (owner
directive 2026-07-19): tcred / datastream / lms / quiz evolve later, on
their own schedules. This file is the running ledger of what each framework
version *lets* a consumer do (optional modernizations) and what 0.3 will
*make* them do (the flag-day debts). Append as versions land; a consumer
migrating later reads this top to bottom.

**The standing rule:** every 0.2.x change is additive — stamped classes and
old names keep working via overrides and alias stubs. Nothing in this file
is urgent until the 0.3 section.

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
LongProcess validation moved inline into tag discovery, which now throws on
a mis-tag), and the internal `extract_correlation()` helper (ceremonies
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
announced record) — the harvest follows source → record automatically; a
stamped source also works (fallback). Framework-only fix: the shared
query-bus yaml dropped the act bracket (consumer yamls were already clean —
verify yours has no CorrelationMiddleware in tactician.query_bus).

### 0.5.2 (the harvest moves to the bus)

Mandatory: drop the hand-listed `arguments:` from your
`OutboxIntegrationEventBus` wiring (ctor gained IDDDConfig — autowire
handles it; cred/ds already updated in lockstep). Gains: announce-lane
facts get biography rows; twins index with no association machinery;
touches write independent of the audit toggle.

## How to verify a migration (any version)

- Consumer suite green.
- `wp ddd doctor` green once built (until then: the conformance tests +
  dddash consumer panel + a saga smoke).
- The three-line boot smoke: consumer appears in `consumers()`,
  `owner_of(<some consumer class>)` resolves, framework version reads as
  expected.
