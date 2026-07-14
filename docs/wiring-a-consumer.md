# Wiring a plugin to tangible-ddd

> Audience: AI assistants (and humans) adding tangible-ddd to a WordPress
> plugin, or auditing an existing consumer's wiring. This is the canonical
> checklist — every block below is the as-built 0.2.0 shape, verified against
> real consumers (tangible-cred, tangible-datastream, lms-monorepo's lms and
> quiz). When this file conflicts with the code, the code wins; fix this file.
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

## Checklist

1. `composer require tangible/ddd:^0.2`
2. Config class implementing `TangibleDDD\Infra\IDDDConfig`
3. Table install at activation + version bump: `\TangibleDDD\WordPress\install_tables($config)`
4. DI: the framework services block (below) in your services.yaml
5. Tactician command bus with the middleware chain (below)
6. Bootstrap: **exactly one call** — `\TangibleDDD\WordPress\register_hooks($config, $di_getter)` after the container compiles
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
  # Config — the consumer's identity
  TangibleDDD\Infra\IDDDConfig:
    alias: My\Plugin\Infra\Config

  # Correlation + transaction middleware
  TangibleDDD\Application\Correlation\CorrelationMiddleware: ~
  TangibleDDD\Application\Correlation\CorrelationContext: ~

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

  # Command audit
  TangibleDDD\Application\Logging\Redactor: ~
  TangibleDDD\Application\Logging\CommandAuditMiddleware: ~
```

Rule of thumb: prefer `~` (autowire). Every hand-listed argument above that
duplicates what autowiring would inject is a future ProcessRunner bug.

## 5. Command bus

Tactician middleware order (audit outermost, handler innermost):

```
CommandAuditMiddleware → CorrelationMiddleware → <your TransactionMiddleware>
  → DomainEventsPublishMiddleware → CommandHandlerMiddleware
```

The transaction middleware wraps event publishing so outbox rows commit
atomically with aggregate writes — that ordering is the outbox pattern.

## 6. Bootstrap — the one call

After the DI container compiles (typical: container on `init:1`, this on
`init:2`):

```php
$config = di()->get(\TangibleDDD\Infra\IDDDConfig::class);
\TangibleDDD\WordPress\register_hooks($config, static fn () => di());
```

`register_hooks()` wires four surfaces, each feature-gated internally
(gates = table existence), so the call is correct for every consumer
regardless of which features it uses today:

| Surface | What breaks without it |
|---|---|
| `register_event_handlers` | async handlers + `IntegrationListener`s never `add_action` (Symfony DI is lazy) — AS fails with "no callbacks registered" |
| `register_outbox_hooks` | **outbox never drains** — integration events written, never delivered (this exact bug shipped twice) |
| `register_process_hooks` | sagas never resume after the AS hop |
| `register_migration_hooks` | framework schema migrations never run on upgraded installs — tables drift from the framework's expectations |

Anti-patterns seen in the wild:

- **À-la-carte calls** (`register_process_hooks` + `register_outbox_hooks`
  only) — silently skips migrations and eager handler boot.
- **Homegrown eager-boot registrars** duplicating `register_event_handlers`
  by iterating service ids. Delete them; the framework function covers
  `\Application\EventHandlers\` and `\Application\IntegrationListeners\`.

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

If a test bootstrap doesn't load WordPress, stub `get_option`/`update_option`
backed by a global array (`OutboxConfig::from_options` and pause holds read
options at construction/runtime). Reset that state per test — under
WorDBless, options persist for the whole process.

## 10. Deploy

- Framework major upgrades: **drain or pause the outbox** across the cutover
  (wire shapes change), and move every consumer on the install in one
  lockstep unit (single shared `TangibleDDD\` namespace).
- The relay pause lane is the tool: `set_pause('deploy', '*')` → deploy →
  `clear_pause('deploy')`. Held rows accumulate durably and drain on release.
