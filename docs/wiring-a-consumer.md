# Wiring a consumer to Tangible DDD

> **Status: CURRENT FOR 0.6.2.** This is the supported top-level consumer
> contract. Check the installed package version, source, and tests when working
> on an older consumer or when this guide disagrees with executable behavior.

A top-level consumer is one WordPress plugin with one DDD prefix, namespace
root, DI container, persistence identity, migration lane, worker set, and
dashboard entry. A separately deployed extension that must retain this identity
is a [consumer module](consumer-modules.md), not another call to `boot()`.

## Fast path

From the consumer plugin directory:

```bash
composer require tangible/ddd:^0.6.2
wp ddd init --prefix=acme_orders --namespace='Acme\Orders' --plugin-path=.
```

The command creates the class-free `ddd-src/` skeleton, DI YAML, container
index, private process resource, seven-table declarations, and a thin local
agent skill that points to the installed package's canonical skill. Existing
files are skipped unless `--force` is supplied.

Add the printed wrapper to the main plugin file after its version constant and
Composer autoloader are loaded:

```php
add_action('plugins_loaded', static function (): void {
    require_once __DIR__ . '/ddd-wordpress/di/index.php';
}, 10);
```

Do not require the generated index immediately during plugin-file execution.
The framework winner has not initialized yet at that point.

## Required lifecycle

| WordPress phase | Contract |
| --- | --- |
| Plugin-file load | Every bundled Composer copy registers its framework callback for `plugins_loaded:0`. |
| `plugins_loaded:0` | Each copy registers its version and path with `Tangible_DDD_Versions`. |
| `plugins_loaded:1` | The newest copy initializes once and loads the framework classes and WordPress functions. |
| `plugins_loaded:10` | The consumer includes its DI index and calls `boot()`. The consumer handle is registered immediately. |
| `init:1` | The consumer constructs or compiles its container. |
| `init:2` | The callback installed by `boot()` registers handlers, process routes, outbox workers, and migrations. |

This order is part of the API. A host that must accept consumer modules has to
call `boot()` before `plugins_loaded:30`; calling `register_hooks()` for the
first time at `init:2` is too late for module attachment.

## Consumer identity

Fresh consumers use `DDDConfig` directly:

```php
$config = new \TangibleDDD\Infra\DDDConfig(
    prefix: 'acme_orders',
    namespace_root: 'Acme\Orders',
    version: ACME_ORDERS_VERSION,
);

\TangibleDDD\WordPress\boot(
    $config,
    static fn () => \Acme\Orders\WordPress\DI\di(),
    label: 'Acme Orders',
);
```

The namespace root drives `ConsumerRegistry::owner_of()`. Commands, queries,
and events defined below that root resolve the consumer container and prefix
without stamped consumer base classes. Hand-written `IDDDConfig`
implementations and old base-class overrides remain valid when their installed
framework version supports them.

`boot()` is the only top-level handshake. It inserts a `ConsumerHandle` into
the registry and schedules the complete runtime hook registration. Read
top-level discovery through `TangibleDDD\WordPress\consumers()` after
`init:2`; it applies the `tangible_ddd_consumers` filter.

## Container construction

The generated index follows this shape:

```php
$container_builder = new ContainerBuilder();
$loader = new YamlFileLoader($container_builder, new FileLocator(__DIR__));
$loader->load('tactician.yaml');
$loader->load('services.yaml');

DDDCompilerPasses::register($container_builder);

add_action('init', static function () use ($container_builder): void {
    $container_builder->compile();
}, 1);

\TangibleDDD\WordPress\boot($config, static fn () => $container_builder);
```

Register `DDDCompilerPasses` after all YAML/resources are loaded and before
`compile()` in every construction path: development, tests, and the release
builder that invokes Symfony `PhpDumper`. Registering it only in a debug
bootstrap recreates a development/production process-discovery split.

### Core service bindings

Prefer autowiring for framework classes. Hand-listed positional constructor
arguments have repeatedly drifted as framework constructors evolved.

| Service ID | Normal implementation or role |
| --- | --- |
| `TangibleDDD\Infra\IDDDConfig` | Alias to this consumer's config |
| `CorrelationMiddleware` | Outermost command scope, nesting guard, correlation, audit |
| `TransactionMiddleware` | Framework wpdb transaction, or a consumer-specific transaction middleware |
| `EventsUnitOfWork` | Collects aggregate domain events |
| `IDomainEventDispatcher` | `WordPressEventDispatcher` |
| `DomainEventsPublishMiddleware` | Drains domain events before transaction commit |
| `IOutboxRepository` | Framework wpdb repository or a transaction-compatible consumer implementation |
| `IOutboxPublisher` | Action Scheduler or routing publisher |
| `IIntegrationEventBus` | `OutboxIntegrationEventBus` |
| `OutboxProcessor` | Relay, retry, and dead-letter processing |
| `IProcessRepository` | Consumer-scoped process persistence |
| `ProcessRunner` | Process start, continuation, await, timeout, and compensation |
| `Redactor` | Command-audit parameter masking |

If aggregate writes use Doctrine/PDO, the outbox repository and transaction
middleware must use that same transaction boundary. Substituting the default
wpdb repository would make aggregate and outbox commits independent.

The `EventsUnitOfWork` object is equally identity-sensitive. The command
middleware resets, seals, and drains that container-managed instance. Inject it
into repositories/services or resolve the live shared service; never retain it
in a process-static consumer facade, because a rebuilt/test-swapped container
would leave the facade recording into a stale sealed instance.

## Command and query buses

The command middleware order is outermost to innermost:

```text
CorrelationMiddleware
  -> TransactionMiddleware
  -> DomainEventsPublishMiddleware
  -> SelfExecutingCommandMiddleware
  -> CommandHandlerMiddleware
```

The transaction wraps event publication, so aggregate writes and outbox rows
commit atomically. `SelfExecutingCommandMiddleware` is terminal only for a
`SelfHandlingCommand`; otherwise execution continues to the normal
naming-convention handler.

The query bus is deliberately read-shaped:

```text
SelfExecutingCommandMiddleware -> QueryHandlerMiddleware
```

It contains no correlation/audit, transaction, or event-publication
middleware. `SelfHandlingQuery` returns its read result; a plain query uses its
mapped handler.

Both message shapes remain supported:

- Plain `ICommand`/`IQuery` plus `CommandBusAware`/`QueryBusAware` and a
  convention-named handler.
- Opt-in `SelfHandlingCommand` or `SelfHandlingQuery` with protected `handle()`
  dependencies method-injected from the owning container.

A separate `ICommandHandler::handle()` returns `void`. A self-handling command
also normally returns `void`; a scalar or DTO verdict may steer transport, but
must not expose a domain object or become a hidden dependency between domain
steps. Queries return read data.

Every application state change enters the command bus. A command or synchronous
domain-event handler never dispatches another command. Same-transaction work is
performed directly through aggregates, repositories, or domain services; a
later unit of work starts from an integration event.

## Service resources

Register application services by namespace. Command/query handlers should be
non-shared; event handlers and integration listeners must be public so the
framework can eagerly construct them at `init:2`:

```yaml
services:
  Acme\Orders\Application\CommandHandlers\:
    resource: '../../ddd-src/Application/CommandHandlers'
    shared: false

  Acme\Orders\Application\QueryHandlers\:
    resource: '../../ddd-src/Application/QueryHandlers'
    shared: false

  Acme\Orders\Application\EventHandlers\:
    resource: '../../ddd-src/Application/EventHandlers'

  Acme\Orders\Application\IntegrationListeners\:
    resource: '../../ddd-src/Application/IntegrationListeners'
```

`register_event_handlers()` searches service IDs under the two event/listener
namespaces and resolves them. Do not duplicate that scan in consumer code.

## Event boundary

- A `DomainEvent` is recorded by an aggregate, collected by its repository,
  and published synchronously inside the command transaction.
- Once the handler returns, the event unit of work is sealed while synchronous
  handlers drain. Newly recorded events must implement
  `IAnnouncesIntegration`; this admits scalar self-publishers and rich events
  with scalar twins while preventing an unbounded plain-domain-event cascade.
- An `IntegrationEvent` is a reversible record crossing a consistency/time
  boundary through the transactional outbox.
- A rich domain event can implement `IAnnouncesIntegration` and return a scalar
  twin. A scalar event needed on both surfaces can announce itself with
  `IntegrationBehaviour`.
- Constructor parameter names and reversible types form the integration wire
  schema. Treat published action names and payload keys as cross-plugin API.
- An `IntegrationListener` under `Application\IntegrationListeners` translates
  one typed integration event into `?ICommand`. Work stays in the command
  handler.

Raw WordPress subscribers receive one associative payload containing the wire
keys plus `__` transport metadata. They must read named keys and tolerate
additions. Framework listeners restore trace context and hydrate the event
before calling consumer policy.

## Long-process discovery

Long processes are lifecycle values reconstructed by `ProcessRunner`, not
services to instantiate. Register their definitions privately for compile-time
discovery:

```yaml
services:
  _instanceof:
    TangibleDDD\Application\Process\LongProcess:
      tags: ['ddd.long_process']

  Acme\Orders\Application\Process\:
    resource: '../../ddd-src/Application/Process'
    autowire: false
    shared: false
    public: false
```

`LongProcessCatalogPass` validates and de-duplicates the tagged definitions,
then materializes class names and legacy tag metadata into the public
`LongProcessCatalog`. At `init:2`, runtime registration reflects `#[Awaits]`
and `#[StartsOn]` without constructing a process or asking a dumped container
for tags.

A retained `ContainerBuilder` without the compiler pass can still use the
0.6.0 `findTaggedServiceIds()` fallback. A dumped container cannot, so the
compiled catalog is mandatory for production parity.

Use `#[StartsOn]` plus `from_event()` for reactive ignition, `#[Awaits]` for
wake routes, and `#[Compensates]` for rollback steps. Starting a process inside
a command or process step is rejected; announce the event that starts the next
lifecycle instead. Process steps may return commands because coordinating
separate units of work is their purpose.

## Consumer-scoped tables

The migration lane creates and heals these seven tables under the WordPress and
consumer prefixes:

1. `integration_outbox`
2. `integration_dlq`
3. `long_processes`
4. `command_audit`
5. `touches`
6. `behaviour_workflows`
7. `behaviour_workflow_items`

`boot()` registers migrations on `init` and `admin_init`; a separate activation
hook is optional. `install_tables($config)` remains an idempotent explicit
installer when an activation/CLI path needs it.

Doctrine consumers must exclude the seven framework table bare names from ORM
schema-diff ownership. WordPress `dbDelta`, not Doctrine migrations, owns them.

Touches are a rebuildable index over declared aggregate state changes. They
power the dashboard Biography but are not a write-side authority or event
store.

## What `register_hooks()` owns

The one call scheduled by `boot()` registers all supported surfaces:

| Surface | Failure when missing |
| --- | --- |
| Event handlers/listeners | Lazy services never attach their WordPress callbacks |
| Process hooks/catalog | processes cannot ignite, await, resume, or time out |
| Outbox hooks | committed integration records never drain |
| Migration hooks | new and upgraded sites drift from the required schema |

Do not call only selected helpers, and do not call
`register_processes_from_container()` manually in new consumers.

## Verification

Before shipping a consumer or framework-version bump:

1. Compile the real YAML container and resolve every consumer service plus the
   hand-wired framework spine.
2. Build the actual `PhpDumper` production container with the same compiler
   passes. Assert it exposes `LongProcessCatalog` when processes exist and that
   process routes register with `WP_DEBUG=false`.
3. Run `IntegrationConformance` for event codec round trips, touches, process
   attributes, and integration-listener contracts.
4. Verify `ConsumerRegistry::owner_of()` resolves representative command,
   query, event, aggregate, and process classes to the intended prefix.
5. Exercise one command transaction that writes aggregate state plus an outbox
   row, then drain it through Action Scheduler.
6. Verify all seven tables and the consumer schema-version option on a fresh
   database and an upgraded database.
7. Confirm the consumer appears once in dddash and its trace joins command,
   outbox, process, workflow, and touches data as expected.

When an integration wire shape changes, deploy publisher and subscribers
together and account for already-pending outbox rows. A framework copy at 0.6.2
correctly outranks earlier copies, but every plugin sharing the site must still
be compatible with the winner.

## Common wiring failures

- Requiring the DI index before `plugins_loaded:1`.
- Calling `register_hooks()` piecemeal or for the first time too late.
- Hand-listing stale constructor arguments instead of autowiring.
- Putting `CorrelationMiddleware` on the query bus.
- Using a transaction/outbox connection different from aggregate persistence.
- Making `LongProcess` definitions public runtime services.
- Registering compiler passes only in development, not the release builder.
- Scanning tags from a dumped Symfony container.
- Dispatching a command from a command or synchronous domain-event handler.
- Calling `boot()` for a sidecar that should be a consumer module.

See the [documentation map](README.md) for historical design records and the
[release ledger](migration-0.2-to-0.3.md) for version-specific migration work.
