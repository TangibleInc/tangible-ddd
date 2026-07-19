# Consumer migration ledger — 0.2.x → 0.3

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

- [ ] Alias stubs die: `TransportEnvelope`, `TangibleDDD\WordPress\
  ConsumerRegistry`/`ConsumerHandle`/`NoConsumerOwnsClass`. Grep and repoint
  before taking 0.3.
- [ ] `restore_context()` replaced by unwrap + scope composition; any raw
  `add_action` integration hook doing manual ceremony must move to the
  helpers (consumers using `integration_action()`/`IntegrationListener` are
  untouched — the helpers absorb the change).
- [x] `stamp_journey()` and the mutable journey slots are GONE (0.3 lane 5).
  Resolution was gentler than predicted: `$event->correlation_id()` /
  `event_id()` survive as deprecated READ-ONLY accessors backed by
  PublishedFacts (null on fresh/hydrated instances; populated at publish) —
  the fleet's tests pinned exactly that contract, no consumer changes.
  Eventually migrate reads to the envelope/scope and the accessors die.
- [x] `CorrelationContext` dissolved (0.3 lane 4): frames, scope stack,
  `with()`, `enter/leave` deleted. It survives as a deprecated shim for
  exactly three consumer-side callers: `get()` reads (facade-first),
  `restore_context()` writers (datastream's relay publisher), and
  `command_id()` in test fixtures (facade-first). Migrate those to
  `Correlation`/`TraceContext`/`IntegrationEnvelope::trace_context()` and
  the shim dies.
- [ ] lms handler migration (see 0.2.4) is a hard prerequisite: 0.3 requires
  all consumers on ≥0.2.4 semantics.
- [ ] **Drop `@CommandAuditMiddleware` from tactician.yaml chains** (0.3
  lane 1, 2026-07-19): the audit record moved into the act bracket
  (CorrelationMiddleware) — the old middleware is a deprecated pass-through
  kept only so existing chains compile. Remove the line; the class dies when
  the last chain does. Harmless to leave meanwhile.

## How to verify a migration (any version)

- Consumer suite green.
- `wp ddd doctor` green once built (until then: the conformance tests +
  dddash consumer panel + a saga smoke).
- The three-line boot smoke: consumer appears in `consumers()`,
  `owner_of(<some consumer class>)` resolves, framework version reads as
  expected.
