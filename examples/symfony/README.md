# Using tangible-ddd 0.7 on Symfony 7.4 (ddd-symfony)

How a Symfony app installs and configures `TangibleDddBundle`. TXP is the first such app. This guide describes the integration branch after waves 1-4 and the house-style rename. Wave 5 is still in progress: see [the CHANGELOG](../../CHANGELOG.md), "Still landing". Nothing is published, so the app consumes the packages through Composer path repositories.

What the bundle gives you, all on one Doctrine DBAL connection to Postgres 16:

- the Tactician command bus in this order: act bracket, effect, transaction, domain events, self-executing, handler. The query bus has no act bracket;
- `DbalTransactionBoundary`, which rejects nested transactions by default;
- the transactional outbox (`ddd_outbox`, `ddd_dlq`), relay pauses and the per-subscriber delivery ledger. All of it is plain SQL that you put in your own migrations;
- `ddd:relay`, which hands due facts to the Messenger `ddd_facts` Doctrine transport in the same transaction as the outbox update;
- a Messenger handler that delivers each fact to its subscribers in priority order: listeners, then process ignition, then resume. The ledger runs each subscriber at most once per fact, even when Messenger delivers the message twice;
- compile-time discovery of listeners, processes and fact-ignited workflows (no constructor side effects), marker-interface subscriptions, worker reset, and audit actors;
- long-running processes on a Postgres advisory lock, with keyed and any-of awaits and durable alarms (`ddd_wakeups`);
- external effects with a result journal;
- `LISTEN`/`NOTIFY` wakeup of the relay;
- one operator view across every retry layer (sections 5-10).

## 1. Install

In the app's `composer.json`. Paths are relative to the app, and the monorepo checkout here is `../tangible-ddd`:

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

- `league/tactician ^2.0-rc1` must be restated in the root manifest, because Composer honours the RC flag only there (register O20).
- `*@dev` accepts the branch version the path repository reports (`dev-extraction/ddd-packages` or a wave branch).

Register the bundle in `config/bundles.php`:

```php
return [
  // ...
  Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
  TangibleDDD\Symfony\Bundle\TangibleDddBundle::class => ['all' => true],
];
```

## 2. Configure

`config/packages/tangible_ddd.yaml`. Every key is shown with its default, except `consumer`, which is required:

```yaml
tangible_ddd:
    consumer:
        prefix: txp               # [a-z0-9_]+; names integration actions and ledger keys. Never change it.
        namespace_root: App       # send() and Event::prefix() resolve the consumer by namespace
        version: '%env(default::APP_VERSION)%'   # unset or empty = '0.0.0'
        label: null
    connection: default           # the DoctrineBundle connection the repositories use
    connection_service: null      # or an explicit DBAL Connection service id
    table_prefix: ''              # prefixes every ddd_* table
    transaction:
        nested: reject            # reject | savepoint (only for test suites that wrap each test in a transaction)
        entity_manager: null      # e.g. doctrine.orm.default_entity_manager: flushed before COMMIT; cleared, or reset when closed, after a rollback
    relay:                        # relay budget (register 5.1): submission failures only
        batch_size: 50
        lease_seconds: 300
        max_attempts: 5           # then the row goes to ddd_dlq (an expired-lease re-claim counts as an attempt)
        base_retry_delay_seconds: 60
        retry_multiplier: 2.0
        max_retry_delay_seconds: 3600
        idle_sleep_seconds: 1     # poll interval of ddd:relay (the D14 fallback)
        listen: true              # D14: NOTIFY at commit, ddd:relay LISTENs
    process:
        inband_start: false       # false: start() persists + a Continue intent, the first step runs in a worker
        pooled_connection: warn   # warn | refuse: advisory lock and LISTEN on a pooled DSN
        stranded_after_seconds: 900
        wakeup_lease_seconds: 300
        stranded_scan_seconds: 60
    delivery:                     # handler budget per (subscriber, fact), counted in the ledger
        budget: 5
        retry_delay_ms: 30000
        retry_multiplier: 2.0
        max_retry_delay_ms: 3600000
    messenger:
        transport: ddd_facts
        wakeup_transport: ddd_wakeups       # due wakeup intents (Messenger retries off; the intent row owns them)
        wakeup_dsn: null
        failure_transport: ddd_failed
        bus: messenger.bus.default          # must NOT carry doctrine_transaction (compilation fails if it does)
        configure_transports: true          # prepend the transports below
        dsn: null                           # default doctrine://<connection>?queue_name=ddd_facts&auto_setup=false
        failure_dsn: null
    facts: []                     # extra fact classes, only for rows written without a class
    self_handling:
        classes: []               # self-handling commands/queries NOT registered by resource loading
        locate_all: false         # true: every class-named service is injectable into handle() (keeps them all compiled)
    process_entry: null           # IProcessEntry service id; default: the bundle's ProcessRunner
    audit:
        sink: null                # IAuditSink service id (default NullAuditSink)
        policy: null              # IAuditPolicy service id (default AttributeAuditPolicy: honours #[Audit] and the two lists)
        not_audited: []           # command classes, parents or marker interfaces never audited (D12)
        without_parameters: []    # ... audited without their parameters (D12)
```

With `configure_transports: true` the bundle prepends the Messenger config below, so that the handler retry strategy equals the delivery budget. It also adds `ddd_wakeups` on the same connection, with `max_retries: 0`:

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

Keep `doctrine://<the same connection>`. That is what makes the relay hand-off exactly-once: the Messenger insert and the outbox `accepted` update commit in one transaction. With any other transport the relay falls back to at-least-once, and your listeners must be idempotent anyway.

Do not add `doctrine_transaction` to the bus in `tangible_ddd.messenger.bus`. Every DDD command opens its own transaction, and a message-wide transaction would turn them into savepoints.

## 3. Schema

The bundle ships plain SQL, not migrations (register X9):

```bash
bin/console ddd:schema:dump               # prints the DDL with tangible_ddd.table_prefix
bin/console ddd:schema:dump --prefix=     # or with an explicit prefix
bin/console ddd:schema:dump --since=008   # only the files after 008: your next migration after an upgrade
```

Paste the output into a Doctrine migration (`$this->addSql(...)` per statement) or into your SQL migration tool. The statements are idempotent. The schema only grows: a shipped file is never edited, and every change is a new numbered file listed in `schema/postgres/released.txt` (L5). Then create the Messenger tables:

```bash
bin/console messenger:setup-transports
```

They must exist before the relay runs. The relay inserts into them inside a transaction, where Messenger's `auto_setup` cannot work, hence `auto_setup=false` in the DSN.

## 4. Commands, facts and listeners

**Commands and handlers** follow the naming convention `App\...\Commands\XCommand` → `App\...\CommandHandlers\XHandler`. Handlers that implement `ICommandHandler` are autoconfigured and may stay private. So are handlers that implement `IReturningCommandHandler`, whose `handle()` value `send()` returns (L1).

```php
namespace App\Tenancy\Commands;

final class AcceptInviteCommand implements ICommand, ITransactionalCommand {
  use CommandBusAware;
  public function __construct(public readonly string $invite_id) {}
}

namespace App\Tenancy\CommandHandlers;

final class AcceptInviteHandler implements IReturningCommandHandler {
  public function __construct(private readonly InviteRepository $invites) {}

  public function handle(ICommand $command): mixed {
    $invite = $this->invites->find($command->invite_id) ?? throw new InviteNotFound($command->invite_id);
    $invite->accept();                  // records MembershipGranted
    $this->invites->save($invite);      // AggregateRootRepository: persist, then collect the events
    return ['membership_id' => $invite->membership_id()];
  }
}

$receipt = (new AcceptInviteCommand($id))->send();   // DBAL transaction; the fact lands in ddd_outbox
```

- A receipt is computed before the in-transaction reactions run, so it cannot carry what a reaction creates.
- Aggregates with a uuid or another non-integer identity extend `AggregateRoot` (L2). Their repositories extend `AggregateRootRepository` and implement `aggregate_class()` and `persist()`.
- `NotPermittedException` (L7) is the 403 family. `PersistenceConflict` is what a unique violation at the ORM flush is rethrown as (L8). `PersistenceConflict::find_in($e)` finds it in an exception chain.
- `#[Audit(false)]` and `#[Audit(parameters: false)]` on a command class, plus `#[Sensitive]` and `#[NotAudited]` on its properties, control the audit row.

Self-handling commands get any service injected into `handle()` by type, private services included, and may return a value:

```php
final class RenameTeam extends SelfHandlingCommand implements ITransactionalCommand {
  public function __construct(public readonly string $team_id, public readonly string $name) {}
  protected function handle(TeamRepository $teams): array { /* ... */ return ['renamed' => true]; }
}
```

The `handle()` locator is built at compile time from the `handle()` parameter types of the self-handling classes the bundle knows, much like Symfony's controller argument locator, so unused private services are still removed. The bundle knows the classes your resource loading registers (`App\: resource: ../src/`, the default). So do not `exclude` your command directories, or else list those classes in `tangible_ddd.self_handling.classes`. A `handle()` type the locator does not hold fails at dispatch with a "service not found" error.

**Facts** are integration events: `IAnnouncesIntegration`, plus `IntegrationBehaviour` for self-publishers. Their consumer prefix comes from the namespace root, so they need no `prefix()` override. Type a binary or large field as `LargeString` (D6, 4 MiB by default). The outbox refuses a payload that is over 8 MiB or not JSON-encodable.

**Integration listeners** replace `integration_listener()`. A subclass of `IntegrationTranslator` is autoconfigured. A class with the same public pair, `event_class()` and `translate()`, needs `#[AsIntegrationListener]`. Listeners are discovered at compile time and built only when a matching fact is delivered:

```php
use TangibleDDD\Application\EventHandlers\IntegrationTranslator;
use TangibleDDD\Runtime\Delivery\SubscriberPriority;
use TangibleDDD\Symfony\DependencyInjection\Attribute\AsIntegrationListener;

final class OrderToyJobOnMembership extends IntegrationTranslator {
  protected function get_event_class(): string { return MembershipGranted::class; }

  protected function get_command(IIntegrationEvent $e): ?ICommand {
    $fact = Correlation::current_fact();        // FactRef{event_id, event_class, correlation_id}
    return new OrderToyJob($e->team_id, Uuid::v5(self::JOB_NAMESPACE, $fact->event_id));
  }
}

#[AsIntegrationListener(event: RequestsNotification::class)]   // D2: every fact implementing the marker
#[SubscriberPriority(20)]
final class NotifyOnAnyRequest {
  public function event_class(): string { return RequestsNotification::class; }
  public function translate(IIntegrationEvent $e): ?ICommand { /* ... */ }
}
```

Do not extend the WordPress `IntegrationListener` on Symfony, because its constructor calls a WordPress function. The translated command is sent under the deterministic id `DeterministicCommandId::for_fact($event_id, $subscriber_id)`. A redelivered fact therefore produces the same command id, and an idempotent handler absorbs it.

**In-transaction reactions** are synchronous, and their exception rolls the command back:

```php
#[AsDomainEventListener(event: InviteAccepted::class)]
final class CreateMembership {
  public function __invoke(InviteAccepted $e): void { /* ... */ }
}
```

**Processes** are `LongProcess` subclasses that your resource loading registers. They are autoconfigured with `ddd.long_process`, and their `#[StartsOn]` / `#[Awaits]` attributes are read at compile time: ignition runs at priority 50, resume at 99. The bundle's `ProcessRunner` is the process entry. See section 7.

**Machine actors** (D5). The audit actor is the Security user. Failing that, it is the console operator (`DDD_OPERATOR`, else the OS user). Failing that, it is Cli or System. A machine authenticator sets the actor explicitly:

```php
$actor_context->run_as(new Actor(ActorKind::Machine, $runner_host, 'runner'), fn () => $command->send());
```

## 5. Run the workers

Run the workers on a **direct** (non-pooled) Postgres connection, for example Neon's non-`-pooler` endpoint:

```bash
bin/console ddd:relay --time-limit=3600          # loop; supervisor restarts it
bin/console ddd:relay --once --limit=100         # one step (cron, deploy hooks, tests)
bin/console messenger:consume ddd_facts ddd_wakeups --time-limit=3600
```

`ddd:relay` does three things per step:

- it relays due outbox rows to `ddd_facts`;
- it projects due wakeup intents (`ddd_wakeups` rows: process continuations, alarms, retries) to the `ddd_wakeups` transport;
- it re-queues stranded `scheduled` processes.

When a step finds nothing, the relay waits in `LISTEN` (D14). An outbox append or a wakeup intent sends `NOTIFY` in its own transaction, so the relay wakes when that transaction commits, and never for one that rolled back. A lost notification costs at most `idle_sleep_seconds`, the poll interval. `relay.listen: false` turns NOTIFY off, and the relay then only polls.

`ddd:relay` survives transient database errors. It logs the DBAL exception, backs off 1, 2, 4 ... 30 s and carries on. After 10 failed steps in a row it exits non-zero, and so does `--once` after one, and the supervisor restarts it. A row whose submission kills the process is not re-claimed forever: each re-claim of an expired lease counts one attempt, and at `max_attempts` the row goes to `ddd_dlq`.

Handler failures retry through Messenger with the delivery budget. Each retry runs only the subscribers that the ledger has not recorded as delivered. After every message the worker resets the DDD runtime (correlation scope, unit of work, actor), and it logs any leak at CRITICAL.

## 6. External effects (D1)

A command that calls something outside the database, such as Stripe or Cloudflare, implements `IExternalEffectCommand`. The bus runs it through act bracket → `EffectMiddleware` → transaction:

- `perform()` runs **outside** any transaction. Its result is stored at once in `ddd_effect_journal`, under `idempotency_key()`.
- `record()` then runs **inside** the command's transaction.
- A retry under the same key finds the journaled result and goes straight to `record()`, so `perform()` is not called again. The retry can be a redelivered fact, a re-run process step or a second dispatch.
- Dispatching an effect inside an open transaction throws `EffectInsideTransaction`.

```php
final class ChargeCustomer extends SelfHandlingCommand implements IExternalEffectCommand {
  public function __construct(public readonly string $customer_id, public readonly int $amount, public readonly string $key) {}

  public function idempotency_key(): string { return $this->key; }

  public function perform(): EffectResult {             // no transaction open here
    $charge = StripeGateway::client()->charges->create([/* ... */], ['idempotency_key' => $this->key]);
    return new EffectResult(['amount' => $this->amount], $charge->id);
  }

  public function record(EffectResult $r): void {       // inside the transaction
    $this->event(new CustomerCharged($this->customer_id, (string) $r->external_ref));
  }

  public function failure_command(\Throwable $last): ?ICommand {
    return new FlagChargeFailed($this->customer_id);   // fired once when a listener's handler budget is spent
  }

  protected function handle(): void { throw new \LogicException('runs through EffectMiddleware'); }
}
```

- **Keys.** Inside a process step, use `$this->step_ref('charge')`, which is stable across re-runs of the step. For a listener, derive the key from the fact in `translate()` (`Correlation::current_fact()->event_id`, section 9).
- **Dependencies.** `perform()` and `record()` receive no services. `StripeGateway::client()` above stands for however your app reaches its client. A handler-class shape for effects (TXP demand E1) is landing in wave 5.
- **Failure command.** The core delivery invoker fires it, under a deterministic command id, when the listener's handler budget (`delivery.budget`) is spent. It never fires from a Messenger failure event. Inside a process step, the step's `#[RetryStep]` policy governs and the failure command is not used.
- **Repair.** A repair command calls `IEffectJournal::invalidate($key, $reason)` (service `tangible_ddd.effect_journal`) in its own transaction, then re-dispatches. Only then does `perform()` run again.

## 7. Processes, keyed awaits and alarms (D3, D7)

```php
#[StartsOn(MembershipGranted::class)]            // one process per fact (the ignition gate holds under redelivery)
#[Awaits(JobFinished::class)]                    // one #[Awaits] per fact class any await waits for
final class ProvisionApp extends LongProcess {

  public function __construct(public readonly string $app_id) { parent::__construct(null); }

  public static function from_event(MembershipGranted $e): ?static { return new static($e->app_id); }

  protected function order(): Result {
    $job = $this->step_ref('job');               // D13: uuid5(process, step index, purpose)
    return new Result(
      commands: [new OrderJob($this->app_id, $job)],
      // persisted with the checkpoint and the alarm BEFORE OrderJob dispatches
      await: AwaitEvent::keyed(JobFinished::class, $job, timeout_seconds: 1800),
    );
  }

  #[RetryStep(attempts: 2, backoff_seconds: 30)]
  protected function charge(mixed $payload, JobFinished $done): Result {
    if (!$done->ok) { throw new \RuntimeException('job failed'); }   // retried, then compensated
    return new Result(commands: [new ChargeCustomer($this->app_id, 500, $this->step_ref('charge'))]);
  }

  #[Compensates('order')]
  protected function cancel(\Throwable $cause, mixed $checkpoint): Result {
    return new Result(commands: [new CancelJob($this->app_id)]);
  }
}
```

- **Keyed awaits.** The fact that answers a keyed await implements `IAwaitKeyed` (`await_key(): ?string`, for example the job id). `ddd_process_waits` stores one row per (fact class, key), so the answer reaches only the process that minted the key. A key nobody waits for is "unheard": `ProcessRunner::resume_with_outcome()` reports it through `ResumeReport::is_unheard()`, and the fact is acked without error.
- **Any-of with cancellation.** `AwaitAny::of(AwaitEvent::keyed(JobFinished::class, $job))->cancelled_by(new AwaitEvent(AppDestroyed::class, ['app_id' => $id]))`. The first answer resumes the next step with that fact. A cancellation fact compensates every process it names. `->within(3600)` or `->until($instant)` adds an alarm.
- **Dynamic set.** `AwaitAll::keyed(ChildPurged::class, $child_ids, 3600)` waits for every key computed at step time. An empty set does not suspend. The next step receives the `AwaitAll` (`gathered()`, `missing()`).
- **Register-then-check.** A process that implements `IPrecheckAwait` answers `already_satisfied($await)`, which runs after the await committed and the step's commands dispatched. Returning `PrecheckSatisfied::with($value)` resumes in place and cancels the alarm, so a fact that committed before the suspension is not missed.
- **Alarms (D7).** `timeout_seconds`, `AwaitAny::until()`, `AwaitAlarm::at(new \DateTimeImmutable('2026-10-04T12:00:00Z'))` and `AwaitAlarm::after(25 * 3600)` (no fact, just time) each become one `timeout` row in `ddd_wakeups`. The row has an absolute UTC `due_at`, fixed at suspension. There is no upper bound and no chain of short timers, and a worker restart changes nothing. `on_timeout` is either `fail` (compensate the completed steps) or `proceed` (the next step receives `null`).
- **Starting from a web request.** `start()` persists the process and a `Continue` intent in the caller's transaction, and the first step runs in a worker (`process.inband_start: false`, the default, `StartMode::Deferred`). A `#[StartsOn]` ignition runs its first step in the fact worker.
- **Contention.** A contended wakeup is re-queued on its own intent budget (10 attempts, 2 s × 2ⁿ, capped at 300 s). A contended fact resume waits up to 5 s for the lock. After that it is a failed attempt of the resume subscriber, and so spends the delivery budget (TXP demand AW2, landing in wave 5). Keep step commands short, and give every keyed await an alarm.
- **Repairs.** `bin/console ddd:ops:stranded` lists stranded processes and exhausted wakeups. `--resume=<id>` re-runs the stranded step with the same deterministic command ids. `--fail=<id> --reason=...` fails it, and `--rearm=<key>` re-arms an exhausted intent. These dispatch core's `ResumeStrandedProcess` and `FailStrandedProcess`.

## 8. Workflows started by facts (D10)

A behaviour-workflow handler that a fact starts implements `IStartsFromFact`, using the `StartsFromFacts` trait for the defaults, and declares its facts with `#[StartsOn]`. Registered as a service through resource loading, it is autoconfigured and gets one ignition subscriber per fact. The dedup key goes through `ddd_workflow_ignitions` (`DbalWorkflowIgnitionLedger`): exactly one workflow exists per key, whatever the redeliveries or concurrent workers.

```php
#[StartsOn(CronEntryDue::class)]
final class NightlyReport extends WorkflowHandler implements IStartsFromFact {
  use StartsFromFacts;

  public function workflow_from_fact(IIntegrationEvent $fact): ?BehaviourWorkflow {
    return new BehaviourWorkflow(null, 0, 'nightly-report', [new BuildReportConfig()]);   // null declines
  }

  public function ignition_key(IIntegrationEvent $fact, string $event_id): string {
    // default (trait): once per fact, WorkflowIgnitionKey::for_fact(); here: once per (workflow, minute)
    return WorkflowIgnitionKey::per_minute($this->workflow_kind() . ':' . $fact->entry, new \DateTimeImmutable($fact->due_at));
  }
  // get_workflows(), execute_one(), generate_work_items(), reschedule(): as for any WorkflowHandler
}
```

The claim, the workflow save and the attach commit together. The start (`start_ignited()`, by default `handle_workflow()`) runs after the commit. If a start throws, the fact's redelivery retries it, and the retry restarts the attached workflow, which must therefore tolerate a re-run. While a start is in flight, another delivery of the fact fails with `WorkflowStartPending` and is retried. `reschedule()` has no durable Symfony implementation yet (W1, landing in wave 5), so a workflow should finish within one start.

## 9. Cause, process id and step index (D13)

| Where | What you can read |
|---|---|
| a listener's `translate()` (the fact scope) | `Correlation::current_fact()` → `FactRef{event_id, event_class, correlation_id}`. Put what the handler needs (the event id, a key derived from it) in the command. |
| the translated command's handler | `Correlation::peek()->cause->id` is the command id, which is deterministic: `DeterministicCommandId::for_fact($event_id, $subscriber_id)`. `current_fact()` is null inside an act. |
| a process step | `$this->get_id()` (the process id), `$this->current_step_index()`, and `$this->step_ref('purpose')` (uuid5 over class, id, step index and purpose, the same on a re-run) |
| a step command's handler | `Correlation::peek()->cause->id` is the command id: `DeterministicCommandId::for_step($prefix, $process_id, $step_index, $ordinal)`. Pass the process id, step index or ref in the command when the handler needs them. |
| anywhere | `Uuid::v5($namespace_uuid, $name)` (`TangibleDDD\Domain\Shared\Uuid`) for your own deterministic ids |

Use these for job ids and notification dedup. A redelivered fact or a re-run step produces the same ids, so idempotent handlers and the D1 journal absorb the repeat.

## 10. Operator view (D9)

One `IOperatorView` merges every retry layer of the consumer: the relay DLQ and retrying rows, delivery-ledger pairs, wakeup intents, stranded and quarantined processes, and the Messenger failure transport. Each `OperatorItem` carries attempts against budget, the last error and the repairs that apply.

```bash
bin/console ddd:ops:list                         # every layer
bin/console ddd:ops:list --layer=delivery --format=json
bin/console ddd:ops:dlq:list                     # relay dead letters, oldest first
bin/console ddd:ops:dlq:retry <event id>         # pending or dead-lettered rows (--force for others, never a leased one)
bin/console ddd:ops:dlq:replay <DLQ id>          # keeps the event id, so subscribers already in the ledger are skipped
bin/console ddd:ops:dlq:discard <DLQ id>
bin/console ddd:ops:pause 'widget_*' --for=600   # pause relaying of matching event types
bin/console ddd:ops:resume 'widget_*'
bin/console ddd:ops:stranded --resume=42         # or --fail=42 --reason=..., --rearm=<intent key>
```

`ddd:ops:list` names the command that carries out each repair. The `workflow` layer has no Symfony source yet (W5, landing in wave 5).

## 11. Tests in the app

Run DDD tests **without** a per-test transaction wrapper (DAMA and similar), because the code under test owns its transactions. If you must keep a wrapper, set `tangible_ddd.transaction.nested: savepoint` in the test environment, and keep relay and delivery tests out of it.

The bundle's own kernel tests show the whole path on Postgres 16:

- `packages/ddd-symfony/tests/Kernel/ReferenceScenarioTest.php`: a fact-started saga with a keyed await, an alarm, a D1 effect retried with its journaled result, and a compensation;
- `WorkflowIgnitionTest.php`: D10;
- `PostCommitWakeupTest.php` and `PostCommitPollFallbackTest.php`: D14.

To move time in a test, replace the `tangible_ddd.clock` service with a `TangibleDDD\Runtime\FrozenClock`. The reference test does it in a compiler pass.
