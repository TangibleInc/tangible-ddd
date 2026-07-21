# Wiring a plugin to tangible-ddd

> Audience: AI assistants (and humans) adding tangible-ddd to a WordPress
> plugin, or auditing an existing consumer's wiring. This is the canonical
> checklist — every block below is the as-built 0.2.0 shape, verified against
> real consumers (tangible-cred, tangible-datastream, lms-monorepo's lms and
> quiz). When this file conflicts with the code, the code wins; fix this file.

> ⚠️ **0.2.5 changed the wiring story** (`docs/migration-0.2-to-0.3.md` is
> the delta ledger — read it WITH this file): the stamped classes this guide
> describes are now OPTIONAL. `wp ddd init` emits zero classes; identity is
> a `DDDConfig` boot declaration (prefix + namespace_root + version) resolved
> at runtime via `ConsumerRegistry::owner_of()`. Existing consumers' stamps
> keep working (overrides win); fresh consumers start collapsed. The
> checklist below still describes the existing fleet accurately.
>
> **0.6.1 fixes process discovery in dumped Symfony containers.** Consumers
> must register the DDD compiler passes before `compile()` and register their
> `Application/Process` namespace as private discovery definitions. At runtime,
> `register_hooks()` prefers the compiled `LongProcessCatalog`; retained
> `ContainerBuilder` consumers keep the 0.6.0 tag-query fallback.
>
> Reference implementations: `lms-monorepo/plugins/quiz` (leanest correct
> wiring), `tangible-cred` (largest surface). Both migrated to 0.2.0 in July
> 2026; their PRs are worked examples of every rule here.

## What a consumer is

A consumer = one plugin with its own `IDDDConfig` prefix. The prefix scopes
everything: six framework tables (`{wp_prefix}{prefix}_integration_outbox`,
`_integration_dlq`, `_long_processes`, `_command_audit`,
`_behaviour_workflows`, `_behaviour_workflow_items`), WP hook names
(`{prefix}_domain_*`, `{prefix}_integration_*`), options
(`{prefix}_outbox_*`, `{prefix}_ddd_schema_version`), and one ActionScheduler
relay. Two consumers on one site are two parallel universes that may talk
only over WP hooks — never over each other's tables.

⚠️ `TangibleDDD\` is an unscoped namespace and every consumer vendors its own
copy — the first-autoloaded copy wins process-wide. All consumers on one
install must run the same framework major, and framework upgrades deploy in
lockstep with a drained outbox (payload wire shapes are versioned by the
framework, not per consumer).

## Fast path: `wp ddd init`

The framework ships a scaffolder that generates most of the ceremony:

```bash
composer require tangible/ddd:^0.6.1
wp ddd init --prefix=acme_orders --namespace=Acme\\Orders --plugin-path=.
```

Generated: the class-free `ddd-src/` directory tree (including
`Application/Process`), `services.yaml` + `tactician.yaml`, and the DI index.
The index registers `DDDCompilerPasses`, compiles on `init:1`, and ends with
the whole framework handshake:
`\TangibleDDD\WordPress\boot($config, fn () => di())`.
Your main plugin file schedules that index after the winning framework copy
initializes (and after defining the version constant/loading Composer):

```php
add_action('plugins_loaded', static function (): void {
    require_once __DIR__ . '/ddd-wordpress/di/index.php';
}, 10);
```

Plus the composer psr-4 mapping for the generated namespace
(`"Acme\\Orders\\": "ddd-src/"`) — the full ceremony is that wrapper plus
the mapping. Do not require the index immediately from the plugin file:
Tangible DDD chooses its winning copy at `plugins_loaded:1`, and neither its
class autoloader nor `TangibleDDD\WordPress\boot()` exists before then.
No activation hook: the migration lane creates/heals the framework tables
on the first `init` tick.

The scaffolder's output is pinned to the framework by
`tests/Unit/Cli/ScaffoldTemplatesConformanceTest.php` (every referenced
class must exist; every hand-listed constructor argument must match the
real constructor) — if you change a framework constructor or move a class,
that test tells you to update the templates.

The manual checklist below is the same material spelled out — use it to
**audit an existing consumer** or when scaffolding isn't an option.

## Checklist

1. `composer require tangible/ddd:^0.6.1`
2. Config class implementing `TangibleDDD\Infra\IDDDConfig`
3. Tables: created/healed automatically by the migration lane once step 6 is
   wired (an explicit activation-time `install_tables($config)` is optional)
4. DI: the framework services block and private process resource (below) in services.yaml
5. Tactician command bus with the middleware chain (below)
6. Bootstrap: enter after framework initialization at `plugins_loaded:1`
   (the scaffolder and current fleet use priority 10), register
   `DDDCompilerPasses` before `compile()`, then make **exactly one call** to
   `\TangibleDDD\WordPress\boot($config, $di_getter)`
7. Events per the 0.2.0 taxonomy (below)
8. Consumers of integration events = `IntegrationListener` classes under `Application\IntegrationListeners\`
9. Container smoke test covering the hand-wired framework service ids

## 3. Tables

Call from an activation/upgrade initializer (idempotent dbDelta):

```php
\TangibleDDD\WordPress\install_tables(new Infra\Config(PLUGIN_VERSION));
```

If the plugin also runs Doctrine ORM, list the six framework table bare-names
in an excluded-tables config so `migration:diff` never emits DROPs for tables
it doesn't manage (see `plugins/lms/ddd-tables.php` in lms-monorepo).

## 4. DI — the framework block

Canonical services.yaml fragment (Symfony DI, `autowire: true` defaults):

```yaml
  # Compiler-pass input: definitions only. LongProcess objects carry
  # journey state and must never be resolved from the container.
  _instanceof:
    TangibleDDD\Application\Process\LongProcess:
      tags: ['ddd.long_process']

  My\Plugin\Application\Process\:
    resource: '../../ddd-src/Application/Process'
    autowire: false
    shared: false
    public: false

  # Config — the consumer's identity
  TangibleDDD\Infra\IDDDConfig:
    alias: My\Plugin\Infra\Config

  # Correlation middleware — THE ACT BRACKET (guard + scope + audit record)
  TangibleDDD\Application\Correlation\CorrelationMiddleware: ~

  # Domain event dispatcher. NO arguments — WordPressEventDispatcher has no
  # constructor; an argument here is silently discarded by PHP and rots.
  TangibleDDD\Application\Events\IDomainEventDispatcher:
    class: TangibleDDD\Infra\Services\WordPressEventDispatcher

  # Events unit of work + router + publish middleware
  TangibleDDD\Application\Events\EventsUnitOfWork: ~
  TangibleDDD\Application\Events\EventRouter: ~
  TangibleDDD\Application\Events\DomainEventsPublishMiddleware: ~

  # Outbox
  TangibleDDD\Application\Outbox\OutboxConfig:
    factory: ['TangibleDDD\Application\Outbox\OutboxConfig', 'from_options']
    arguments: ['@TangibleDDD\Infra\IDDDConfig']

  # Default wpdb-backed repository. Substitute your own ONLY if your writes
  # must share a PDO transaction with aggregate persistence (Doctrine
  # consumers) — and then you owe the FULL interface, including the relay
  # pause lane (set_pause / clear_pause / is_paused + holds-aware
  # fetch_pending). See lms's Doctrine OutboxRepository as the reference.
  TangibleDDD\Infra\IOutboxRepository:
    class: TangibleDDD\Infra\Persistence\OutboxRepository
    arguments:
      - '@TangibleDDD\Infra\IDDDConfig'
      - '@TangibleDDD\Application\Outbox\OutboxConfig'

  TangibleDDD\Application\Outbox\IOutboxPublisher:
    class: TangibleDDD\Infra\Services\ActionSchedulerOutboxPublisher
    arguments: ['@TangibleDDD\Application\Outbox\OutboxConfig']

  # Lives in Infra\Services since 0.2.0 (moved from Application\Outbox).
  # Autowired — 4-arg constructor, do not hand-list arguments.
  TangibleDDD\Infra\Services\OutboxProcessor: ~

  TangibleDDD\Application\Events\IIntegrationEventBus:
    class: TangibleDDD\Infra\Services\OutboxIntegrationEventBus
    arguments: ['@TangibleDDD\Infra\IOutboxRepository']

  # Long processes. ProcessRunner MUST be autowired — its constructor is
  # (IDDDConfig, IProcessRepository); several consumers shipped wrong
  # hand-listed args for months, latent until the first saga awaits.
  TangibleDDD\Infra\IProcessRepository:
    class: TangibleDDD\Infra\Persistence\ProcessRepository
    arguments: ['@TangibleDDD\Infra\IDDDConfig']
  TangibleDDD\Application\Process\ProcessRunner: ~

  # Command audit (the act bracket writes the rows; Redactor masks params)
  TangibleDDD\Application\Logging\Redactor: ~
```

Rule of thumb: prefer `~` (autowire). Every hand-listed argument above that
duplicates what autowiring would inject is a future ProcessRunner bug. This
applies to the consumer's own services too: a hand-listed controller
registrar rots the moment someone adds a constructor param (autowiring
silently patches the hole, so nothing fails — until the list and the
constructor disagree in order instead of count).

## 5. Command bus

Tactician middleware order (the act bracket outermost, handler innermost):

```
CorrelationMiddleware → <your TransactionMiddleware>
  → DomainEventsPublishMiddleware → CommandHandlerMiddleware
```

The transaction middleware wraps event publishing so outbox rows commit
atomically with aggregate writes — that ordering is the outbox pattern.

## 6. Bootstrap — the one call

Register the framework compiler passes after every YAML resource is loaded and
before the container compiles:

```php
use TangibleDDD\Infra\DependencyInjection\DDDCompilerPasses;

$loader->load('tactician.yaml');
$loader->load('services.yaml');
DDDCompilerPasses::register($container_builder);

// Later, after any consumer pre-compile hook:
$container_builder->compile();
```

Use that same construction path in development, integration tests, and the
release builder that invokes Symfony `PhpDumper`. Calling the pass only in the
development bootstrap recreates the `WP_DEBUG=true`/production mismatch that
0.6.1 fixes.

After the framework loader initializes, normally inside the generated index
required at `plugins_loaded:10`:

```php
\TangibleDDD\WordPress\boot(
    new \Acme\Orders\Infra\Config(ACME_ORDERS_VERSION),
    static fn () => \Acme\Orders\WordPress\DI\di(),
);
```

`boot()` announces the plugin to the **consumer registry** (discovery for
the ops dashboard, WP-CLI, cross-consumer tooling — read via
`\TangibleDDD\WordPress\consumers()`, filtered through
`tangible_ddd_consumers`) and defers `register_hooks()` to `init:2`, after
the container compiles on `init:1`. The scaffolder's generated
`di/index.php` already ends with this call, and its main-plugin wrapper waits
until priority 10. Requiring that index directly during plugin-file execution
is invalid: the framework registers copies at `plugins_loaded:0`, initializes
the winner and defines `boot()` at priority 1, then consumers may boot.

Existing consumers that call `register_hooks()` directly on `init:2` are
equivalent — it self-registers with the registry too. Either way,
`register_hooks()` wires four surfaces, each feature-gated internally
(gates = table existence), so the call is correct for every consumer
regardless of which features it uses today:

| Surface | What breaks without it |
|---|---|
| `register_event_handlers` | async handlers + `IntegrationListener`s never `add_action` (Symfony DI is lazy) — AS fails with "no callbacks registered" |
| `register_outbox_hooks` | **outbox never drains** — integration events written, never delivered (this exact bug shipped twice) |
| `register_process_hooks` | sagas never resume after the AS hop |
| process discovery | saga classes never registered with the `ProcessRunner` — awaited integration events fire but wake nothing (see below) |
| `register_migration_hooks` | framework schema migrations never run — fresh installs never get their tables (the lane runs on `init` + `admin_init`; it replaces the activation hook entirely), upgraded installs drift |

**Process discovery** (compiled in 0.6.1): the consumer's `_instanceof` rule
tags each registered process definition, and `DDDCompilerPasses` consumes
those tags while the `ContainerBuilder` still owns its definitions:

```yaml
_instanceof:
  TangibleDDD\Application\Process\LongProcess:
    tags: ['ddd.long_process']

My\Plugin\Application\Process\:
  resource: '../../ddd-src/Application/Process'
  autowire: false
  shared: false
  public: false
```

The pass validates and de-duplicates process classes, preserves legacy
`awaits:` tag attributes, and registers a public `LongProcessCatalog` whose
constructor data contains class names and tag metadata only. Symfony can dump
that ordinary data without retaining definitions or instantiating a process.

At `init:2`, `register_hooks()` prefers `LongProcessCatalog` and reflects each
class's `#[Awaits]` and `#[StartsOn]` attributes. If no catalog exists but the
runtime object still exposes `findTaggedServiceIds()`, the public
`register_processes_from_container()` compatibility path preserves 0.6.0-era
retained builders. A dumped container has no tag-query API, so registering the
compiler passes in every build path is mandatory for dev/production parity.

Late process registration from a side plugin is not supported in 0.6.1; that
runtime extension API is reserved for 0.6.2. Every 0.6.1 process must be known
to the consumer's `ContainerBuilder` before compilation.

**Starting processes** (0.2.4) — three doors, one invariant:

1. **Reactive** (the default for business flows): `#[StartsOn(Event::class)]`
   on the process + a static `from_event(Event $e): ?static` (null = "not my
   business" — the policy filter). Ignition happens at drain, dedups on the
   journey `__event_id` (replay-safe), inherits the fact's correlation, and
   records the fact as `ignited_by_event_id` on the process row. A requested
   saga is this plus an intent command whose handler validates and
   `Events::record()`s the ignition fact — the handler never touches the
   runner.
2. **Edge cold-start**: `di()->get(ProcessRunner::class)->start($process)` —
   from REST controllers, CLI, WP hook closures. (A `(new P(...))->start()`
   self-dispatch hatch was built and stripped — it demanded a stamped
   `Process` base per consumer for an ergonomic with zero callers; if it ever
   earns its way back, the 0.2.5 registry scheme delivers it with no consumer
   base.) The first step runs in-band,
   immediately: steps execute wherever ignition or waking legally happens;
   only awaits and timeouts create hops.
3. **Human**: `wp ddd announce '<EventFQCN>' --payload='{...}'` — the total
   codec makes every integration event JSON-constructible; the announced
   fact rides the outbox and is indistinguishable from an organic one.

The invariant behind all three: **commands never nest, and processes never
start inside a command pass.** The bus refuses nested dispatch
(`CommandDispatchedInsideCommand`), and `start()` inside a handler throws
(`ProcessStartedInsideCommand`) — record an event instead. A handler wanting
a synchronous side effect calls a domain service in-band; a saga's ground
contacts are dispatched flat by the runner with the process as causation.

Anti-pattern this retires: **sync domain event handlers dispatching
commands** (`->send()` inside `Application\EventHandlers\*`). Those run
inside the publishing command's transaction and now throw. Atomic-with-
command work belongs in a domain service; new-unit-of-work-later work
belongs in an `IntegrationListener` (drain time, flat).

Anti-patterns seen in the wild:

- **À-la-carte calls** (`register_process_hooks` + `register_outbox_hooks`
  only) — silently skips migrations and eager handler boot.
- **Homegrown eager-boot registrars** duplicating `register_event_handlers`
  by iterating service ids. Delete them; the framework function covers
  `\Application\EventHandlers\` and `\Application\IntegrationListeners\`.
- **Manual `register_processes_from_container()` calls** in consumer
  bootstrap — keep the function only as the retained-builder compatibility
  API; new consumers register `DDDCompilerPasses` and let `register_hooks()`
  read the catalog.
- **Consumer-prefixed process tags** (`acme.long_process`) — the framework
  compiler pass consumes `ddd.long_process` only; a private tag name means
  your sagas are never cataloged.

## 7. Events — 0.2.0 taxonomy in four rules

Full spec: `docs/integration-event-evolution.md`. The wiring-level rules:

1. **Domain event** (`extends <YourPrefix>DomainEvent`): raisable via
   `record()`/aggregate `event()`; dies before ActionScheduler; hand-written
   `payload()` feeds the sync WP hook.
2. **Pure integration record** (`extends <YourPrefix>IntegrationEvent`, the
   severed base): NOT raisable — publish it straight on
   `IIntegrationEventBus`. Constructor props must be **`protected`** readonly
   (the codec runs on the base via `IntegrationBehaviour` and cannot read
   subclass-private props). No `payload()` — the ctor is the schema.
3. **Self-publisher** (born-scalar fact needed on both surfaces):
   `extends <YourPrefix>DomainEvent implements IIntegrationEvent,
   IAnnouncesIntegration { use IntegrationBehaviour; }` — raise site
   unchanged, `to_integration()` returns `$this`. `private` props are fine
   here (the trait is mixed into the class itself). Keep `payload()` (the
   `DomainEvent` base requires it).
4. **Fat moment** → hand-written scalar twin in the `Integration\`
   sub-namespace via `to_integration()` (same short name = same hook).

**Contract-frozen wire shapes**: when hook name or payload keys are an
external contract (another plugin already subscribes), override the codec
pair — `integration_action()` and/or `integration_payload()` +
`from_payload()` — as class methods (they beat the trait). Keep the pair
total: whatever `integration_payload()` emits, `from_payload()` must revive.
Worked example: quiz's `AttemptCompleted`.

## 8. Consuming integration events

One class per policy under `Application\IntegrationListeners\` (eager-booted
by `register_hooks`, DI-constructed):

```php
final class DoThingOnFact extends IntegrationListener {
    protected function get_event_class(): string { return Fact::class; }
    public function get_command(IIntegrationEvent $event): ?ICommand {
        \assert($event instanceof Fact);
        return new DoThingCommand(id: $event->get_id()); // null = not my business
    }
}
```

Fn-form escape hatch: `\TangibleDDD\WordPress\integration_listener(Fact::class, fn (Fact $e) => ...)`.
Plain `add_action` subscribers receive the **wrapped associative payload as a
single argument** (contract keys + `__correlation_id`/`__event_id` transport
keys) — read by key, tolerate extras. Positional closures over payload
values are a 0.1.x idiom and break on named payloads.

## 9. Tests

Port the container smoke test (see `plugins/quiz/tests/Integration/Container/`
in lms-monorepo): compile the container from yaml, sweep-resolve every
plugin-namespace service, **and explicitly resolve the hand-wired framework
spine** — `OutboxProcessor`, `ProcessRunner`, `IOutboxRepository`,
`IIntegrationEventBus`, `EventRouter`, `IDomainEventDispatcher`. Namespace
sweeps never touch these ids; stale FQCNs and ctor drift hide there.

For a consumer that dumps its production container, add one `PhpDumper`
regression: compile a scalar-constructor `LongProcess`, load the generated
runtime container, assert its public `LongProcessCatalog` contains the class,
and assert the process constructor was never called. Run hook registration
against that runtime container so `#[Awaits]` and `#[StartsOn]` parity is part
of the test rather than inferred from the development builder.

If a test bootstrap doesn't load WordPress, stub `get_option`/`update_option`
backed by a global array (`OutboxConfig::from_options` and pause holds read
options at construction/runtime). Reset that state per test — under
WorDBless, options persist for the whole process.

## 10. Deploy

0.6.1 rolls out after the framework tag exists, in this order:

1. Publish Tangible DDD `v0.6.1`.
2. Update Cred and Datastream compile setup plus dependency metadata.
3. Update LMS/Quiz development and `bin/build-php` compile setup.
4. Update LMS/Quiz constraints and lockfiles against the published tag.
5. Build both release containers and verify their process catalogs under
   `WP_DEBUG=false`.

- Framework major upgrades: **drain or pause the outbox** across the cutover
  (wire shapes change), and move every consumer on the install in one
  lockstep unit (single shared `TangibleDDD\` namespace).
- The relay pause lane is the tool: `set_pause('deploy', '*')` → deploy →
  `clear_pause('deploy')`. Held rows accumulate durably and drain on release.
