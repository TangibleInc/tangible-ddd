# Tangible DDD

Tangible DDD is a Domain-Driven Design runtime. It gives each consumer a command/query boundary, transactional event publication, durable asynchronous delivery, causal tracing and long-running orchestration, without making consumers share one database identity.

From 0.7 it ships as four packages in this repository:

| Package | For |
|---|---|
| `tangible/ddd` (this root) | WordPress plugins. It bundles `ddd-core` and `ddd-wp`. This README is its guide. |
| `tangible/ddd-core` | The host-neutral runtime, plus a PDO default for plain PHP. Guide: [examples/plain-php-durable/README.md](examples/plain-php-durable/README.md) |
| `tangible/ddd-symfony` | Symfony 7.4 on Postgres 16. Guide: [examples/symfony/README.md](examples/symfony/README.md) |
| `tangible/ddd-conformance` | The shared scenario suite (development only) |

0.7.0 is prepared and not released. See [CHANGELOG.md](CHANGELOG.md) for what changed since 0.6.x and how to migrate. Rolling a site back to 0.6.x is covered by [docs/runbooks/rollback.md](docs/runbooks/rollback.md).

## Using tangible-ddd 0.7 on WordPress

### Requirements

- PHP 8.2 or newer
- WordPress with Action Scheduler (`woocommerce/action-scheduler`, installed by Composer)
- MySQL 8 (MariaDB is not a claimed target)
- A Symfony Dependency Injection container for each top-level consumer

### Install and wire

```bash
composer require tangible/ddd:^0.7
wp ddd init --prefix=acme_orders --namespace='Acme\Orders'
```

Keep requiring `tangible/ddd`, never `tangible/ddd-core` or `tangible/ddd-wp`: on WordPress, core always comes from the winning copy. Run `wp ddd init` from the plugin directory. It creates the DI and table scaffolding and prints the bootstrap snippet to add to your plugin file. The wiring contract (lifecycle, `boot()`, container, middleware order, tables) is unchanged from 0.6, and [docs/wiring-a-consumer.md](docs/wiring-a-consumer.md) describes it. A 0.6 consumer runs on 0.7 without code changes.

### Commands

Commands enter the command bus. A command opens a wpdb transaction only if it implements `ITransactionalCommand`. Being on the bus is not an opt-in to a transaction. A plain command has a handler named by convention (`Commands\XCommand` → `CommandHandlers\XHandler`). A self-handling command has its `handle()` dependencies injected from the consumer's container.

```php
namespace Acme\Orders\Application\Commands;

final class PlaceOrderCommand implements ICommand, ITransactionalCommand {
  use CommandBusAware;

  public function __construct(public readonly int $customer_id, public readonly int $total_cents) {}
}

namespace Acme\Orders\Application\CommandHandlers;

final class PlaceOrderHandler implements IReturningCommandHandler {   // 0.7: handle() may return a receipt
  public function __construct(private readonly OrderRepository $orders) {}

  public function handle(ICommand $command): mixed {
    $order = Order::place($command->customer_id, $command->total_cents);   // records OrderPlaced
    $this->orders->save($order);
    return ['order_id' => $order->get_id()];
  }
}

$receipt = (new PlaceOrderCommand(7, 4200))->send();
```

`ICommandHandler::handle(): void` stays valid. A receipt is computed before the in-transaction reactions run, so it cannot carry what a reaction creates. Read that back with a query. A command or a synchronous domain-event handler never dispatches another command.

Also new in 0.7, on every host:

- `AggregateRoot` and `AggregateRootRepository` (its final `save()` persists, then collects the recorded events) for aggregates whose identity is not an `int`;
- `NotPermittedException`, the 403 family;
- `#[Audit(false)]` and `#[Audit(parameters: false)]` on a command class, plus `#[Sensitive]` and `#[NotAudited]` on its properties, to control the audit row.

### Facts

A domain event recorded by an aggregate is published synchronously, inside the command's unit of work. A fact (an integration event) crosses a consistency boundary through the transactional outbox. Action Scheduler then delivers it on the fact's hook.

```php
final class OrderPlaced extends DomainEvent implements IAnnouncesIntegration {
  public function __construct(public readonly int $order_id) {}
  public function payload(): array { return ['order_id' => $this->order_id]; }
  public function to_integration(): OrderPlacedFact { return new OrderPlacedFact($this->order_id); }
}

final class OrderPlacedFact extends IntegrationEvent {
  public function __construct(public readonly int $order_id = 0) {}
  public function delay(): int { return 0; }   // > 0: due that many seconds after the commit, once
}
```

- The constructor parameter names and their types are the wire schema. Treat hook names and payload keys as a cross-plugin API.
- A delay applies **once**: 0.6 applied it twice.
- Type a field that can hold binary data, or a large text, as `LargeString`. A fact whose Action Scheduler args would exceed 8000 bytes is relayed by reference to its outbox row instead of being refused, as it was in 0.6.

### Listeners

An `IntegrationListener` under `Application\IntegrationListeners` translates one fact into an optional command. All work belongs in the command's handler.

```php
namespace Acme\Orders\Application\IntegrationListeners;

final class ReserveStockOnOrderPlaced extends IntegrationListener {
  protected function get_event_class(): string { return OrderPlacedFact::class; }

  protected function get_command(IIntegrationEvent $event): ?ICommand {
    return new ReserveStockCommand($event->order_id);
  }
}
```

What 0.7 adds, once the consumer is at schema v8:

- **Isolation.** Each DDD-registered callback runs behind a per-subscriber delivery ledger. If one listener throws, the error is logged and recorded, and the rest of the hook's callbacks still run. Raw `add_action` callbacks are outside this guarantee.
- **Retries are opt-in.** By default a failing listener gets one attempt, as in 0.6. The ledger then marks it exhausted, and `wp ddd ops` lists it. A listener that declares `#[Retries(n)]` (`TangibleDDD\WordPress\Retries`) gets n + 1 attempts through `{prefix}_ddd_redeliver`, with backoff 30 s × 2ⁿ capped at 3600 s. The option `{prefix}_ddd_delivery_attempts` sets the number for a whole consumer, and the filter `tangible_ddd_delivery_attempts` has the last word. Process ignition and resume always keep 5 attempts. This lands in wave 5: see the [CHANGELOG](CHANGELOG.md). Opt in only when the command can run again for the same fact.

  ```php
  #[Retries(4)]                                   // 5 attempts in all
  final class ReserveStockOnOrderPlaced extends IntegrationListener { /* ... */ }
  ```
- **The fact's identity.** Inside a listener, `Correlation::current_fact()` returns the `FactRef` (`event_id`, `event_class`, `correlation_id`) of the fact being delivered. Use it, or `Uuid::v5()`, to derive idempotency keys.

`integration_listener()` and `integration_action()` remain the function forms.

### Processes

A `LongProcess` is a series of steps that run in declaration order. Each step returns a `Result`, which can carry a payload for the next step, commands to dispatch, an await to suspend on, and a checkpoint for compensation. Register process classes privately, tagged `ddd.long_process`, and call `DDDCompilerPasses::register()` before `compile()` ([docs/wiring-a-consumer.md](docs/wiring-a-consumer.md)).

```php
#[StartsOn(OrderPlacedFact::class)]          // exactly one process per fact, however often it is delivered
#[Awaits(PaymentReceivedFact::class)]
final class FulfilOrderProcess extends LongProcess {
  public function __construct(public readonly int $order_id) { parent::__construct(null); }

  public static function from_event(OrderPlacedFact $e): ?static { return new static($e->order_id); }

  protected function request_payment(): Result {
    return new Result(
      commands: [new RequestPaymentCommand($this->order_id)],
      // the await commits BEFORE the command dispatches; on timeout the completed steps compensate
      await: new AwaitEvent(PaymentReceivedFact::class, ['order_id' => $this->order_id], timeout_seconds: 3600),
    );
  }

  #[RetryStep(attempts: 2, backoff_seconds: 30)]
  protected function ship(mixed $payload, PaymentReceivedFact $paid): Result {
    return new Result(commands: [new ShipOrderCommand($this->order_id, $paid->amount_cents)]);
  }

  #[Compensates('request_payment')]
  protected function cancel(\Throwable $cause, mixed $checkpoint): Result {
    return new Result(commands: [new CancelOrderCommand($this->order_id)]);
  }
}
```

What 0.7 guarantees on WordPress:

- A process step never runs without the process lock. A contended wake is re-queued.
- `#[StartsOn]` ignition happens once per (process class, fact), including after a dead-letter replay.
- Every save is version-fenced.
- A process stranded mid-step shows up in the operator view, with resume and fail repairs.
- A process whose class no longer decodes is quarantined (`failed`, with `quarantine_reason`), and the worker continues.
- `#[RetryStep]` re-runs a failed step before the process compensates.
- `$this->step_ref('purpose')` gives a key that stays the same when the step re-runs.
- `ProcessRunner::start()` inside a command still throws on WordPress. Announce a fact and let `#[StartsOn]` react instead.

### Awaits and alarms

On WordPress, use these:

- `AwaitEvent`, unkeyed, matched by criteria;
- `AwaitAll` with a key extractor;
- `timeout_seconds` on either of them. The timeout is a durable intent with an absolute due time. It is projected to Action Scheduler on the 0.6 hooks with the 0.6 arguments, and a relay tick re-projects it if its action goes missing.

`AwaitAlarm::at()` and `AwaitAlarm::after()` (no fact, just time) also run on WordPress, but a 0.6 winner cannot decode them. Avoid them while a rollback to 0.6 is still possible.

The D3 awaits are not supported on WordPress: `AwaitEvent::keyed()`, `AwaitAny`, `AwaitAll::keyed()` and `IPrecheckAwait`. Their conformance cells are `-` on wp, and a 0.6 winner could not decode their state. They run on Symfony and plain PHP.

### Workflows

`BehaviourWorkflow` runs configurable, repeatable behaviour routines over work items, through a `WorkflowHandler` that you subclass. You implement `get_workflows()`, `generate_work_items()`, `execute_one()` and `reschedule()`. On WordPress, `reschedule()` is usually a delayed fact that maps back to the handler's command, as tangible-cred's `BehaviourWorkflowReschedule` does. Its delay now applies once. Fact-ignited workflows (`IStartsFromFact`, `WorkflowIgniter`, D10) are not wired on WordPress.

### External effects

External effects (`IExternalEffectCommand`, `EffectMiddleware`, the effect journal, D1) are not wired on WordPress. A command that calls an external API keeps its own idempotency, as in 0.6. The Symfony and plain-PHP guides show D1.

### Operator view and CLI

```sh
wp ddd ops                                    # every layer: relay, delivery, wakeup, process
wp ddd ops --layer=delivery --format=json
wp ddd ops --consumer=acme_orders --rearm=timeout:42:3            # re-arm an exhausted wakeup
wp ddd ops --consumer=acme_orders --abandon='<subscriber id> @ <event id>'
wp ddd ops --consumer=acme_orders --resume-stranded=42
wp ddd ops --consumer=acme_orders --fail-stranded=42 --reason='card expired' --compensate
wp ddd relay --once                           # one relay tick: outbox batch, wakeup re-projection, stranded scan
wp ddd drain --before-rollback                # before any rollback to 0.6.x (docs/runbooks/rollback.md)
```

Each row lists attempts against budget, the last error and the repairs that apply. There is no daemon: Action Scheduler drives delivery, and `wp ddd relay --once` from cron adds the re-projection and the stranded scan.

## Runtime model

- Commands enter the command bus. Its middleware owns correlation, audit, transaction, domain-event publication and the terminal handler. The stock wpdb transaction middleware opens a database transaction only for commands that implement `ITransactionalCommand`.
- Queries use a read-only bus, without the command transaction and the audit bracket.
- Domain events are synchronous and stay inside the originating unit of work. Integration events cross a consistency boundary through the transactional outbox and Action Scheduler.
- `BehaviourWorkflow` runs configurable, repeatable behaviour routines over work items. `LongProcess` models business lifecycles written by developers: they can schedule, suspend, await integration events, resume and compensate.
- Long-process definitions are compiled into `LongProcessCatalog`, so dumped production containers discover the same processes as development containers.
- Correlation and causation metadata survive cross-plugin handoffs. Opening an exact correlation gathers every registered consumer and stitches their recorded fragments together, without a shared write table.
- Declared aggregate touches build a rebuildable Biography read model. The touches table is never a write-side authority.

## Consumer ownership

Every plugin may bundle its own Composer copy. Each copy registers at `plugins_loaded:0`, and at priority `1` the loader initializes only the newest registered version. From 0.7 the loader entry is version-unique, so Composer's dedup cannot hide a newer copy. The winner then serves every `TangibleDDD\` class itself and reports a mixed load instead of hiding it. `Tangible_DDD_Versions::instance()->winner()` names the winning copy. Top-level consumers boot against the winner and keep their own namespace root, table prefix, container and storage.

A consumer module is a strict namespace descendant that contributes commands, queries, listeners and long processes through a separate compiled container, while sharing its host consumer's runtime identity. See [Consumer modules](docs/consumer-modules.md).

## Consumer-scoped storage

For a prefix such as `acme_orders`, the framework maintains these WordPress tables, each also prefixed with the site's table prefix:

- `acme_orders_integration_outbox`, `acme_orders_integration_dlq`
- `acme_orders_long_processes`
- `acme_orders_command_audit`
- `acme_orders_touches`
- `acme_orders_behaviour_workflows`, `acme_orders_behaviour_workflows_meta`, `acme_orders_behaviour_workflow_items`
- from schema v8 (0.7): `acme_orders_ddd_wakeups`, `acme_orders_ddd_delivery_ledger`, `acme_orders_ddd_relay_pauses`

Schema v8 is additive only, and a 0.6 copy tolerates it.

## Dashboard

The winning copy registers **Tangible DDD** at the `tangible-dddash` admin page. The dashboard discovers top-level consumers and reads each one's audit, outbox, process, workflow, touch and trace data. Exact traces are unified across participating consumers and keep consumer provenance. Biography stays scoped to the selected aggregate's owner. The live view uses WordPress Heartbeat to show new trace pieces as workers finish them.

## Documentation

- [CHANGELOG.md](CHANGELOG.md): 0.7.0 changes, migration from 0.6.x
- [Rollback runbook](docs/runbooks/rollback.md)
- [Symfony guide](examples/symfony/README.md) and [plain-PHP guide](examples/plain-php-durable/README.md)
- [Documentation map](docs/README.md)
- [Wiring a consumer](docs/wiring-a-consumer.md)
- [Consumer design interview](docs/consumer-design-interview.md)
- [Consumer modules](docs/consumer-modules.md)
- [Release and migration ledger](docs/migration-0.2-to-0.3.md)
- [Extraction contract register](docs/extraction/contract-register.md)
- [Canonical agent skill](.claude/skills/tangible-ddd/SKILL.md)

Historical specs and plans are kept as a record of design decisions, and the documentation map labels them as historical. When a historical record disagrees with the installed package, the current source and tests win.
