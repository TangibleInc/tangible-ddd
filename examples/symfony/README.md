# tangible/ddd on Symfony 7.4 (ddd-symfony)

How a Symfony app (TXP is the first) installs and configures `TangibleDddBundle`.
State: wave 2, round 1 of the extraction. Nothing is published; the app consumes
the packages through Composer path repositories.

What the bundle gives you, all on one Doctrine DBAL connection to Postgres 16:

- the Tactician command bus in the frozen order: act bracket, transaction,
  domain events, self-executing, handler; and the query bus (no act bracket);
- `DbalTransactionBoundary` (nested transactions rejected by default);
- the transactional outbox (`ddd_outbox`, `ddd_dlq`), relay pauses and the
  per-subscriber delivery ledger, as plain SQL you put in your own migrations;
- `ddd:relay`, which hands due facts to the Messenger `ddd_facts` Doctrine
  transport in the same transaction as the outbox update;
- a Messenger handler that delivers each fact to its subscribers in priority
  order (listeners, then process ignition, then resume), each at most once per
  fact through the ledger, even when Messenger delivers the message twice;
- compile-time discovery of listeners and processes (no constructor side
  effects), marker-interface subscriptions, worker reset, and audit actors.

## 1. Install

In the app's `composer.json` (paths relative to the app; the monorepo checkout is
`../tangible-ddd` here):

```json
{
    "repositories": [
        { "type": "path", "url": "../tangible-ddd/packages/ddd-core",    "options": { "symlink": true } },
        { "type": "path", "url": "../tangible-ddd/packages/ddd-symfony", "options": { "symlink": true } }
    ],
    "require": {
        "tangible/ddd-core": "*@dev",
        "tangible/ddd-symfony": "*@dev",
        "league/tactician": "^2.0-rc1",
        "doctrine/doctrine-bundle": "^2.13",
        "symfony/doctrine-messenger": "7.4.*",
        "symfony/console": "7.4.*"
    }
}
```

- `league/tactician ^2.0-rc1` must be restated in the root manifest: Composer
  honours the RC flag only there (register O20).
- `*@dev` accepts the branch version the path repository reports
  (`dev-extraction/ddd-packages` or a wave branch).

**Transition note (until the wave-2 move lands).** Most 0.6 classes the bundle
builds on (`Correlation`, `EventsUnitOfWork`, `DomainEvent`, `ConsumerRegistry`,
...) still live in the monorepo's `ddd-src/`, not yet in `packages/ddd-core/src/`.
Until they move, add the legacy tree as a PSR-4 fallback in the app:

```json
"autoload": {
    "psr-4": {
        "App\\": "src/",
        "TangibleDDD\\": "../tangible-ddd/ddd-src/"
    }
}
```

Composer consults ddd-core's own `TangibleDDD\` map first, so classes that have
already moved win. No `ddd-wordpress/` file is loaded; anything that reaches a
WordPress function fails loudly. Delete the line once `packages/ddd-core/src`
carries the classes. (The package's own test bootstrap does the same thing.)

Register the bundle in `config/bundles.php`:

```php
return [
    // ...
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    TangibleDDD\Symfony\Bundle\TangibleDddBundle::class => ['all' => true],
];
```

## 2. Configure

`config/packages/tangible_ddd.yaml`, every key shown with its default except the
required `consumer`:

```yaml
tangible_ddd:
    consumer:
        prefix: txp               # [a-z0-9_]+; names integration actions and ledger keys. Never change it.
        namespace_root: App       # send() and Event::prefix() resolve the consumer by namespace
        version: '%env(default::APP_VERSION)%'
        label: null
    connection: default           # the DoctrineBundle connection the repositories use
    connection_service: null      # or an explicit DBAL Connection service id
    table_prefix: ''              # ddd_outbox, ddd_dlq, ddd_relay_pauses, ddd_delivery_ledger
    transaction:
        nested: reject            # reject | savepoint (only for test suites that wrap each test in a transaction)
        entity_manager: null      # e.g. doctrine.orm.default_entity_manager: flushed before COMMIT
    relay:                        # relay budget (register 5.1): submission failures only
        batch_size: 50
        lease_seconds: 300
        max_attempts: 5           # then the row goes to ddd_dlq
        base_retry_delay_seconds: 60
        retry_multiplier: 2.0
        max_retry_delay_seconds: 3600
        idle_sleep_seconds: 1
    delivery:                     # handler budget per (subscriber, fact), counted in the ledger
        budget: 5
        retry_delay_ms: 30000
        retry_multiplier: 2.0
        max_retry_delay_ms: 3600000
    messenger:
        transport: ddd_facts
        failure_transport: ddd_failed
        bus: messenger.bus.default          # must NOT carry doctrine_transaction (compilation fails if it does)
        configure_transports: true          # prepend the two transports below
        dsn: null                           # default doctrine://<connection>?queue_name=ddd_facts&auto_setup=false
        failure_dsn: null
    facts: []                     # extra fact classes, only for rows written without a class
    self_handling:
        classes: []               # self-handling commands/queries NOT registered by resource loading
        locate_all: false         # true: every class-named service is injectable into handle() (keeps them all compiled)
    process_entry: null           # IProcessEntry service id (the process runner arrives in wave 3)
    audit:
        sink: null                # IAuditSink service id (default NullAuditSink)
        policy: null              # IAuditPolicy service id (default AuditEverything)
```

With `configure_transports: true` the bundle prepends this Messenger config, so
the handler retry strategy equals the delivery budget:

```yaml
framework:
    messenger:
        transports:
            ddd_facts:
                dsn: 'doctrine://default?queue_name=ddd_facts&auto_setup=false'
                retry_strategy: { max_retries: 4, delay: 30000, multiplier: 2, max_delay: 3600000 }
                failure_transport: ddd_failed
            ddd_failed:
                dsn: 'doctrine://default?queue_name=ddd_failed&auto_setup=false'
```

Keep `doctrine://<the same connection>`: that is what makes the relay hand-off
exactly-once (the Messenger insert and the outbox `accepted` update commit in
one transaction). With any other transport the relay falls back to
at-least-once and your listeners must be idempotent anyway.

Do not add `doctrine_transaction` to the bus in `tangible_ddd.messenger.bus`.
Every DDD command opens its own transaction; a message-wide transaction would
turn them into savepoints.

## 3. Schema

The bundle ships plain SQL, not migrations (register X9):

```bash
bin/console ddd:schema:dump            # prints the DDL with tangible_ddd.table_prefix
bin/console ddd:schema:dump --prefix=  # or with an explicit prefix
```

Paste the output into a Doctrine migration (`$this->addSql(...)` per statement)
or your SQL migration tool. The statements are idempotent. Then create the
Messenger table:

```bash
bin/console messenger:setup-transports
```

It must exist before the relay runs: the relay inserts into it inside a
transaction, where Messenger's `auto_setup` cannot work (hence
`auto_setup=false` in the DSN).

## 4. Write code

Commands and handlers follow the naming convention
`App\...\Commands\XCommand` → `App\...\CommandHandlers\XHandler`. Handlers
implementing `ICommandHandler` are autoconfigured; they may stay private.

```php
namespace App\Tenancy\Commands;

final class AcceptInviteCommand implements ICommand, ITransactionalCommand {
    use CommandBusAware;
    public function __construct(public readonly string $inviteId) {}
}

namespace App\Tenancy\CommandHandlers;

final class AcceptInviteHandler implements ICommandHandler {
    public function __construct(private InviteRepository $invites, private EventsUnitOfWork $events) {}

    public function handle(ICommand $command): void {
        $invite = $this->invites->get($command->inviteId);
        $invite->accept();
        $this->invites->save($invite);
        $this->events->record(new MembershipGranted($invite->teamId(), $invite->userId()));
    }
}

(new AcceptInviteCommand($id))->send();   // DBAL transaction; the fact lands in ddd_outbox
```

Self-handling commands inject any service by type into `handle()`, private
services included, and may return a value:

```php
final class RenameTeam extends SelfHandlingCommand implements ITransactionalCommand {
    public function __construct(public readonly string $teamId, public readonly string $name) {}
    protected function handle(TeamRepository $teams): array { /* ... */ return ['renamed' => true]; }
}
```

The `handle()` locator is built at compile time from the `handle()` parameter
types of the self-handling classes the bundle knows, like Symfony's controller
argument locator, so unused private services are still removed. It knows the
classes your resource loading registers (`App\: resource: ../src/`, the
default): do not `exclude` your command directories, or list those classes in
`tangible_ddd.self_handling.classes`. A `handle()` type the locator does not
hold fails at dispatch with a "service not found" error.

Facts are integration events (`IAnnouncesIntegration` + `IntegrationBehaviour`
for self-publishers). Their consumer prefix comes from the namespace root, so no
`prefix()` override is needed.

**Integration listeners** replace `integration_listener()`. They are discovered
at compile time and built only when a matching fact is delivered:

```php
use TangibleDDD\Symfony\DependencyInjection\Attribute\AsIntegrationListener;
use TangibleDDD\Runtime\Delivery\SubscriberPriority;

#[AsIntegrationListener]                         // or (event: SomeMarker::class)
final class OrderToyJobOnMembership {
    public function event_class(): string { return MembershipGranted::class; }   // a constant
    public function translate(IIntegrationEvent $e): ?ICommand { return new OrderToyJob($e->teamId); }
}

#[AsIntegrationListener(event: RequestsNotification::class)]   // D2: every fact implementing the marker
#[SubscriberPriority(20)]
final class NotifyOnAnyRequest { /* ... */ }
```

Once core ships `IntegrationTranslator` (wave 2), its subclasses are
autoconfigured without the attribute. Do not extend the 0.6
`IntegrationListener` on Symfony: its constructor calls a WordPress function.

**In-transaction reactions** (synchronous, their exception rolls the command
back):

```php
#[AsDomainEventListener(event: InviteAccepted::class)]
final class CreateMembership { public function __invoke(InviteAccepted $e): void { /* ... */ } }
```

**Processes** are tagged `ddd.long_process`; their `#[StartsOn]` / `#[Awaits]`
attributes are read at compile time (ignition at priority 50, resume at 99).
They need `tangible_ddd.process_entry` (the wave-3 runner).

**Machine actors** (D5): the audit actor is the Security user, else the console
operator (`DDD_OPERATOR`, else the OS user), else Cli/System. A machine
authenticator sets it explicitly:

```php
$actorContext->runAs(new Actor(ActorKind::Machine, $runnerHost, 'runner'), fn () => $command->send());
```

## 5. Run the workers

On a **direct** (non-pooled) Postgres connection, e.g. Neon's non `-pooler`
endpoint:

```bash
bin/console ddd:relay --time-limit=3600          # loop; supervisor restarts it
bin/console ddd:relay --once --limit=100         # one step (cron, deploy hooks, tests)
bin/console messenger:consume ddd_facts --time-limit=3600
```

`ddd:relay` survives transient database errors: it logs the DBAL exception,
backs off 1, 2, 4 ... 30 s and carries on; after 10 failed steps in a row (or
at once with `--once`) it exits non-zero, and the supervisor restarts it. A
row whose submission kills the process is not re-claimed forever: each
re-claim of an expired lease counts one attempt, and at `max_attempts` the row
goes to `ddd_dlq`.

Handler failures retry through Messenger with the delivery budget; each retry
runs only the subscribers the ledger has not recorded as delivered. The worker
resets the DDD runtime (correlation scope, unit of work, actor) after every
message and logs a leak at CRITICAL.

## 6. Tests in the app

Run DDD tests **without** a per-test transaction wrapper (DAMA and similar): the
code under test owns its transactions. If you must keep a wrapper, set
`tangible_ddd.transaction.nested: savepoint` in the test environment and keep
relay/delivery tests out of it.

## What is not here yet

Process lock, process store, wakeups, `LISTEN`/`NOTIFY` relay wakeup, the pooler
warning, `ddd:ops:*` and the effect journal are wave 3 and 4. The act bracket,
outbox bus and relay step are bundle-local stand-ins until core ships their
port-based forms (round 3 switches the same service ids).
