# Consumer modules

> **Status: CURRENT FOR 0.6.2.** This guide defines the supported contract for
> extending an existing Tangible DDD consumer from separately deployed code.
> Verify the installed host and module versions, containers, and service IDs
> before applying it to a real plugin.

A **consumer module** contributes code beneath an existing consumer's PHP
namespace while retaining that consumer's operational identity. A sidecar
WordPress plugin is one way to package a module; sidecar describes deployment,
not a second DDD identity.

For example, a module rooted at
`Tangible\LMS\Extension\SuperTrace` can behave as LMS-native code:

- its commands and queries resolve through its own container;
- its events use the LMS integration-action prefix;
- its audit, outbox, process, workflow, and Biography data use LMS tables;
- its long processes run on the LMS `ProcessRunner`; and
- dddash shows LMS once, with the module class names inside that consumer.

The module does not alter the LMS container. It builds a separate container
and imports only the host's exact public runtime objects through a get-only
bridge.

## Choose the right identity

Use a consumer module when all of these are true:

- the code belongs to the host's domain language and namespace;
- its writes must share the host transaction, outbox, process repository, and
  retention policy;
- operators should see one host in dddash; and
- the module can be deployed independently without owning independent tables
  or workers.

Use another top-level consumer instead when the extension needs its own
prefix, storage lifecycle, migrations, workers, dashboard entry, or domain
authority. Do not use a module merely to avoid defining an integration event
between genuinely separate domains.

## Invariants

The runtime enforces these boundaries:

1. A module names exactly one registered host prefix.
2. Its namespace root is a strict, whole-segment descendant of the host root.
3. The named host must be the most specific top-level owner of that root.
4. The module handle shares the host's exact `IDDDConfig` object and label.
5. `owner_of()` routes module classes to the module container by longest root.
6. `ConsumerRegistry::all()` and `consumers()` remain top-level only.
7. The module registers no migrations, outbox worker, process continuation
   hook, or second dashboard consumer.
8. Module process types are registered on the host's exact `ProcessRunner`.
9. No module API mutates a retained, compiled, or dumped host container.

The top-level host handle becomes stable after its first module attaches. An
exact repeated host registration is idempotent; a different config, getter,
label, or namespace root throws rather than splitting module routing from host
runtime state.

## Required load order

The order is part of the public contract:

| WordPress phase | Host and module responsibility |
| --- | --- |
| Plugin-file load | Host and sidecar load their Composer autoloaders. Each bundled Tangible DDD copy registers through Composer's `files` entry. Do not reference runtime `TangibleDDD\...` classes yet. |
| `plugins_loaded:0` | Every copy registers its version with `Tangible_DDD_Versions`. |
| `plugins_loaded:1` | The newest compatible copy initializes the framework once. |
| `plugins_loaded:10` | The host includes its DI index and calls `boot()`, which registers its handle immediately. |
| `plugins_loaded:30` | The sidecar includes its DI index and calls `boot_module()`. Missing or invalid hosts fail here. |
| `init:1` | Host and module independently compile or load their containers. Both build paths register `DDDCompilerPasses`. |
| `init:2` | Host `register_hooks()` installs host listeners, process routes, workers, and migrations. |
| `init:3` | Module listeners are eagerly constructed and its compiled process entries are added to the host runner. |

A module-capable host must call `boot()` before priority 30. A legacy host that
first calls `register_hooks()` at `init:2` has not registered an identity when
the module attaches and is therefore incompatible.

`boot_module()` declares a minimum framework requirement of `0.6.2` under the
diagnostic key `ddd-module:<normalized namespace root>`. This makes a loader
mismatch visible without inventing another consumer prefix.

## Host bootstrap

The host uses the normal top-level handshake:

```php
add_action('plugins_loaded', static function (): void {
    require_once __DIR__ . '/ddd-wordpress/di/index.php';
}, 10);
```

Its DI index calls `boot()` while it is included:

```php
\TangibleDDD\WordPress\boot(
    new \TangibleDDD\Infra\DDDConfig(
        prefix: 'tgbl_lms',
        namespace_root: 'Tangible\LMS',
        version: TANGIBLE_LMS_VERSION,
    ),
    static fn () => \Tangible\LMS\WordPress\DI\di(),
    label: 'Tangible LMS',
);
```

The host must expose every stateful service imported by released modules as a
public service under a stable ID. In particular, its real transaction
middleware ID is a host/module wire contract.

## Sidecar bootstrap

The sidecar's Composer dependency is at least `tangible/ddd:^0.6.2`. Requiring
`vendor/autoload.php` loads the package's version-negotiation entry through
Composer's `files` configuration.

```php
require_once __DIR__ . '/vendor/autoload.php';

add_action('plugins_loaded', static function (): void {
    require_once __DIR__ . '/ddd-module/di/index.php';

    \TangibleDDD\WordPress\boot_module(
        host_prefix: 'tgbl_lms',
        namespace_root: 'Tangible\LMS\Extension\SuperTrace',
        di_getter: static fn () =>
            \Tangible\LMS\Extension\SuperTrace\WordPress\DI\di(),
    );
}, 30);
```

Do not call `boot()` or `register_hooks()` for the sidecar. Those calls would
create an independent persistence/worker identity rather than a host-native
module.

Repeated `boot_module()` calls for the same host/root may replace the container
getter before `init:3`; only one runtime callback is installed. A different
host for the same root, or any replacement after wiring, throws.

## Separate container

The module owns its application services, handlers, listeners, terminal
command/query resolution, and compiled process catalog. A minimal construction
path is:

```php
<?php

namespace Tangible\LMS\Extension\SuperTrace\WordPress\DI;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use TangibleDDD\Infra\DependencyInjection\DDDCompilerPasses;

$builder = new ContainerBuilder();
$loader = new YamlFileLoader($builder, new FileLocator(__DIR__));
$loader->load('tactician.yaml');
$loader->load('services.yaml');
DDDCompilerPasses::register($builder);

function di(?ContainerBuilder $instance = null): ContainerBuilder
{
    static $container;
    return $instance !== null ? ($container = $instance) : $container;
}

di($builder);

add_action('init', static function (): void {
    di()->compile();
}, 1);
```

A production builder may dump this container with Symfony `PhpDumper` instead.
Register the same compiler passes in development, tests, and the dump builder;
do not rely on access to service definitions or tags after compilation.

## The host-service bridge

Two static factories are deliberately small:

```php
$config = ConsumerRegistry::config_for('tgbl_lms');
$service = ConsumerRegistry::service_for('tgbl_lms', $public_service_id);
```

`config_for()` returns the host's exact config object. `service_for()` calls
only `get()` on the host's runtime container and returns its exact public
service object. Neither method exposes definitions or a mutation API.

Bind the module's `IDDDConfig` with `config_for()`. Import stateful host
services with `service_for()` rather than constructing lookalikes:

| Shared host object | Why identity matters |
| --- | --- |
| `CorrelationMiddleware` | Owns the command scope, nesting guard, causation, and audit bracket. |
| Host transaction middleware | Must wrap the same database connection and transaction as host persistence. |
| `DomainEventsPublishMiddleware` | Drains the host event unit of work before that transaction commits. |
| `EventsUnitOfWork` | Module aggregate repositories must record into the same pending-event set. |
| `IIntegrationEventBus` | Writes facts through the host's configured publication lane. |
| `IOutboxRepository` | Preserves the host transaction and storage implementation. |
| `ProcessRunner` | Owns host process registration and continuation state. |

The framework service IDs above are common when the host uses the stock
wiring. The transaction ID is not. LMS and Quiz use their own Doctrine
transaction middleware; wpdb consumers commonly use
`TangibleDDD\Application\Persistence\TransactionMiddleware`. A module must
name and test the actual host service ID.

Example bridge definitions for an LMS module:

```yaml
services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: true

  TangibleDDD\Infra\IDDDConfig:
    factory: ['TangibleDDD\Infra\Consumers\ConsumerRegistry', 'config_for']
    arguments: ['tgbl_lms']

  TangibleDDD\Application\Correlation\CorrelationMiddleware:
    factory: ['TangibleDDD\Infra\Consumers\ConsumerRegistry', 'service_for']
    arguments:
      - 'tgbl_lms'
      - 'TangibleDDD\Application\Correlation\CorrelationMiddleware'

  super_trace.host_transaction:
    class: Tangible\LMS\Application\Middleware\DoctrineTransactionMiddleware
    factory: ['TangibleDDD\Infra\Consumers\ConsumerRegistry', 'service_for']
    arguments:
      - 'tgbl_lms'
      - 'Tangible\LMS\Application\Middleware\DoctrineTransactionMiddleware'

  TangibleDDD\Application\Events\DomainEventsPublishMiddleware:
    factory: ['TangibleDDD\Infra\Consumers\ConsumerRegistry', 'service_for']
    arguments:
      - 'tgbl_lms'
      - 'TangibleDDD\Application\Events\DomainEventsPublishMiddleware'

  TangibleDDD\Application\Events\EventsUnitOfWork:
    factory: ['TangibleDDD\Infra\Consumers\ConsumerRegistry', 'service_for']
    arguments:
      - 'tgbl_lms'
      - 'TangibleDDD\Application\Events\EventsUnitOfWork'

  TangibleDDD\Application\Events\IIntegrationEventBus:
    factory: ['TangibleDDD\Infra\Consumers\ConsumerRegistry', 'service_for']
    arguments:
      - 'tgbl_lms'
      - 'TangibleDDD\Application\Events\IIntegrationEventBus'

  TangibleDDD\Infra\IOutboxRepository:
    factory: ['TangibleDDD\Infra\Consumers\ConsumerRegistry', 'service_for']
    arguments:
      - 'tgbl_lms'
      - 'TangibleDDD\Infra\IOutboxRepository'

  TangibleDDD\Application\Process\ProcessRunner:
    factory: ['TangibleDDD\Infra\Consumers\ConsumerRegistry', 'service_for']
    arguments:
      - 'tgbl_lms'
      - 'TangibleDDD\Application\Process\ProcessRunner'
```

If a dumped host does not publish a required service, the module is blocked
until the host exposes a stable public ID in a normal release. Reconstructing a
stateful service from the shared config is not equivalent and runtime container
mutation is never the workaround.

## Module buses and resources

The module command bus reuses the host's three outer middleware instances but
keeps self-handling and naming-convention resolution module-local:

```yaml
services:
  League\Tactician\CommandBus:
    arguments:
      - '@TangibleDDD\Application\Correlation\CorrelationMiddleware'
      - '@super_trace.host_transaction'
      - '@TangibleDDD\Application\Events\DomainEventsPublishMiddleware'
      - '@TangibleDDD\Application\CQRS\SelfExecutingCommandMiddleware'
      - '@tactician.middleware.command_handler'

  TangibleDDD\Application\CQRS\SelfExecutingCommandMiddleware:
    arguments: ['@service_container']

  tactician.query_bus:
    class: League\Tactician\CommandBus
    arguments:
      - '@TangibleDDD\Application\CQRS\SelfExecutingCommandMiddleware'
      - '@tactician.middleware.query_handler'

  tactician.middleware.command_handler:
    class: League\Tactician\Handler\CommandHandlerMiddleware
    arguments:
      - '@service_container'
      - '@tactician.command_to_handler_mapping'

  tactician.middleware.query_handler:
    class: League\Tactician\Handler\CommandHandlerMiddleware
    arguments:
      - '@service_container'
      - '@tactician.query_to_handler_mapping'
```

Use the same mapping/inflector definitions as a normal 0.6.2 consumer. The
query bus remains read-shaped; do not import the command correlation,
transaction, or event-publication middleware into it.

Register module-owned resources under the declared module root:

```yaml
services:
  _instanceof:
    TangibleDDD\Application\Process\LongProcess:
      tags: ['ddd.long_process']

  Tangible\LMS\Extension\SuperTrace\Application\CommandHandlers\:
    resource: '../../src/Application/CommandHandlers'
    shared: false

  Tangible\LMS\Extension\SuperTrace\Application\QueryHandlers\:
    resource: '../../src/Application/QueryHandlers'
    shared: false

  Tangible\LMS\Extension\SuperTrace\Application\EventHandlers\:
    resource: '../../src/Application/EventHandlers'

  Tangible\LMS\Extension\SuperTrace\Application\IntegrationListeners\:
    resource: '../../src/Application/IntegrationListeners'

  Tangible\LMS\Extension\SuperTrace\Application\Process\:
    resource: '../../src/Application/Process'
    autowire: false
    shared: false
    public: false
```

Handler and listener services reached through the runtime container must be
public in a dumped container. Process definitions remain private because the
compiler pass records their class metadata without constructing them.

## Runtime routing

For a module command or query, the message's bus-aware trait asks
`ConsumerRegistry::owner_of(static::class)` for a container. The longer module
root wins over the host root, so the module bus resolves its handler or
self-handling dependencies. The imported host middleware then performs the
same correlation, transaction, and publication work as a host-declared
command.

For a module event, `Event::prefix()` resolves the same module handle, whose
config is the host object. Domain and integration action names therefore use
the host prefix. An eagerly constructed module `IntegrationListener` can turn
that fact back into a module command without losing the host identity.

Rows retain module FQCNs for attribution, but they are stored in the host's
tables. The framework does not rewrite class names or create a module prefix.

## Process catalog overlay

The host's compiled `LongProcessCatalog` is an immutable base. At `init:3`,
each module catalog is processed in registration order:

1. Every entry must extend `LongProcess` and lie below that module root.
2. The entry is compared with the host base and previously wired modules.
3. Identical class and metadata duplicates are ignored.
4. Different metadata for the same class throws before that module's listener
   constructors or process callbacks run.
5. Remaining entries are reflected and registered on the host's exact runner.

The host catalog and both containers remain unchanged. The host runner's
repository persists module process instances to the host `long_processes`
table; existing host continuation hooks rehydrate them. Commands returned by a
module process route through the module container because their class root is
more specific.

A listener/command/query-only module may omit `LongProcessCatalog` or expose an
empty one. That path does not resolve the host runner.

## Discovery and operations

`ConsumerRegistry::all()` and `TangibleDDD\WordPress\consumers()` enumerate
top-level persistence consumers only. `modules_for($host_prefix)` exposes the
namespace-routing overlay for diagnostics; it is not a second worker or
dashboard list.

Consequently, dddash shows the host once. Module command, event, and process
class names appear in that host's trace and operational records.

### Deactivation and class renames

`long_processes.process_class` stores the module FQCN. Removing the sidecar or
renaming a process class while non-terminal rows exist can make continuation
rehydration fail. Before deactivation or rename:

1. Find rows whose `process_class` lies below the module root and whose status
   is not `completed` or `failed`.
2. Drain, complete, explicitly fail, or migrate those rows.
3. For a rename, retain a compatibility class/alias until stored rows are
   migrated.
4. Verify no scheduled continuation or await-timeout action still depends on
   the removed class.

Version 0.6.2 does not automate this operational decision.

## Verification checklist

Before releasing a host/module pair:

1. Assert the host calls `boot()` before `plugins_loaded:30` and the module
   calls `boot_module()` at priority 30.
2. Verify both Composer copies declare compatible framework constraints and
   that the winning runtime is at least 0.6.2.
3. Compile and dump the actual host and module containers with the same
   compiler passes used in production.
4. Assert every bridged host service ID is public and returns the exact host
   object; include the host-specific transaction middleware in this test.
5. Dispatch one module command and query and prove terminal resolution occurs
   in the module container.
6. Publish and consume one module integration event and verify the host action
   prefix, audit row, outbox row, and restored trace context.
7. Register one module `LongProcess` against a dumped host/module pair and
   prove the host runner owns its wake/start routes.
8. Assert `consumers()` contains the host once and `modules_for()` contains the
   declared module root.
9. Test missing hosts, cross-root attachment, private host services, and
   conflicting process metadata as boot failures.
10. Define and rehearse the process-row drain/migration procedure before
    supporting module deactivation.

See [Wiring a consumer](wiring-a-consumer.md) for the host contract and the
[release ledger](migration-0.2-to-0.3.md) for version-specific rollout work.
