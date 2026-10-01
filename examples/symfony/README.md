# tangible/ddd on Symfony 7.4 (ddd-symfony)

How a Symfony app (TXP is the first) installs and configures `TangibleDddBundle`.
State: wave 4 of the extraction. Nothing is published; the app consumes the
packages through Composer path repositories.

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
- compile-time discovery of listeners, processes and fact-ignited workflows (no
  constructor side effects), marker-interface subscriptions, worker reset, and
  audit actors;
- long-running processes on a Postgres advisory lock, with keyed and any-of
  awaits, durable alarms (`ddd_wakeups`), external effects with a result
  journal, and `LISTEN`/`NOTIFY` wakeup of the relay (sections 5-9).

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
        version: '%env(default::APP_VERSION)%'   # unset = '0.0.0'
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
        max_attempts: 5           # then the row goes to ddd_dlq
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
        failure_transport: ddd_failed
        bus: messenger.bus.default          # must NOT carry doctrine_transaction (compilation fails if it does)
        configure_transports: true          # prepend the two transports below
        dsn: null                           # default doctrine://<connection>?queue_name=ddd_facts&auto_setup=false
        failure_dsn: null
    facts: []                     # extra fact classes, only for rows written without a class
    self_handling:
        classes: []               # self-handling commands/queries NOT registered by resource loading
        locate_all: false         # true: every class-named service is injectable into handle() (keeps them all compiled)
    process_entry: null           # IProcessEntry service id; default: the bundle's ProcessRunner
    audit:
        sink: null                # IAuditSink service id (default NullAuditSink)
        policy: null              # IAuditPolicy service id (default AuditEverything)
```

With `configure_transports: true` the bundle prepends this Messenger config, so
the handler retry strategy equals the delivery budget (it also adds
`ddd_wakeups` on the same connection with `max_retries: 0`):

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
implementing `ICommandHandler` (or `IReturningCommandHandler`, whose
`handle()` value `send()` returns) are autoconfigured; they may stay private.

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

**Processes** (`LongProcess` subclasses your resource loading registers) are
autoconfigured with `ddd.long_process`; their `#[StartsOn]` / `#[Awaits]`
attributes are read at compile time (ignition at priority 50, resume at 99).
The bundle's `ProcessRunner` is the process entry. See section 7.

**Machine actors** (D5): the audit actor is the Security user, else the console
operator (`DDD_OPERATOR`, else the OS user), else Cli/System. A machine
authenticator sets it explicitly:

```php
$actorContext->run_as(new Actor(ActorKind::Machine, $runnerHost, 'runner'), fn () => $command->send());
```

## 5. Run the workers

On a **direct** (non-pooled) Postgres connection, e.g. Neon's non `-pooler`
endpoint:

```bash
bin/console ddd:relay --time-limit=3600          # loop; supervisor restarts it
bin/console ddd:relay --once --limit=100         # one step (cron, deploy hooks, tests)
bin/console messenger:consume ddd_facts ddd_wakeups --time-limit=3600
```

`ddd:relay` does three things per step: it relays due outbox rows to
`ddd_facts`, projects due wakeup intents (`ddd_wakeups` rows: process
continuations, alarms, retries) to the `ddd_wakeups` transport, and re-queues
stranded `scheduled` processes. When a step finds nothing it waits in
`LISTEN` (D14): an outbox append or a wakeup intent sends `NOTIFY` in its own
transaction, so the relay wakes when that transaction commits and never for a
rolled-back one. A lost notification costs at most `idle_sleep_seconds`, the
poll interval (`relay.listen: false` turns NOTIFY off and only polls).

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

## 6. External effects (D1)

A command that calls something outside the database (Stripe, Cloudflare)
implements `IExternalEffectCommand`. The bus runs it as act bracket →
`EffectMiddleware` → transaction: `perform()` runs **outside** any
transaction and its result is stored in `ddd_effect_journal` under
`idempotency_key()` at once; `record()` then runs **inside** the command's
transaction. A retry under the same key (a redelivered fact, a re-run process
step, a second dispatch) finds the journaled result and goes straight to
`record()`: `perform()` is not called again.

```php
final class ChargeCustomer extends SelfHandlingCommand implements IExternalEffectCommand {
    public function __construct(public readonly string $customerId, public readonly int $amount, public readonly string $key) {}

    public function idempotency_key(): string { return $this->key; }

    public function perform(): EffectResult {            // no transaction open here
        $charge = $this->stripe()->charges->create([...], ['idempotency_key' => $this->key]);
        return new EffectResult(['amount' => $this->amount], $charge->id);
    }

    public function record(EffectResult $r): void {      // inside the transaction
        $this->event(new CustomerCharged($this->customerId, (string) $r->external_ref));
    }

    public function failure_command(\Throwable $last): ?ICommand {
        return new FlagChargeFailed($this->customerId);  // fired once when a listener's handler budget is spent
    }

    protected function handle(): void { throw new \LogicException('runs through EffectMiddleware'); }
}
```

- Keys: inside a process step use `$this->step_ref('charge')` (stable across
  re-runs of the step); for a listener, derive it from the fact in
  `translate()` (`Correlation::current_fact()->event_id`, section 9).
- Failure command: fired by the core delivery invoker when the listener's
  handler budget (`delivery.budget`) is spent, never from a Messenger failure
  event. Inside a process step the step's `#[RetryStep]` policy governs and the
  failure command is not used.
- Repair: a repair command calls `IEffectJournal::invalidate($key, $reason)`
  (service `tangible_ddd.effect_journal`) in its own transaction, then
  re-dispatches; only then does `perform()` run again.

## 7. Processes, keyed awaits and alarms (D3, D7)

```php
#[StartsOn(MembershipGranted::class)]            // one process per fact (the ignition gate holds under redelivery)
#[Awaits(JobFinished::class)]                    // one #[Awaits] per fact class any await waits for
final class ProvisionApp extends LongProcess {

    public function __construct(public readonly string $appId) { parent::__construct(null); }

    public static function from_event(MembershipGranted $e): ?static { return new static($e->appId); }

    protected function order(): Result {
        $job = $this->step_ref('job');           // D13: uuid5(process, step index, purpose)
        return new Result(
            commands: [new OrderJob($this->appId, $job)],
            // persisted with the checkpoint and the alarm BEFORE OrderJob dispatches
            await: AwaitEvent::keyed(JobFinished::class, $job, timeout_seconds: 1800),
        );
    }

    #[RetryStep(attempts: 2, backoff_seconds: 30)]
    protected function charge(mixed $payload, JobFinished $done): Result {
        if (!$done->ok) { throw new \RuntimeException('job failed'); }   // retried, then compensated
        return new Result(commands: [new ChargeCustomer($this->appId, 500, $this->step_ref('charge'))]);
    }

    #[Compensates('order')]
    protected function cancel(\Throwable $cause, mixed $checkpoint): Result {
        return new Result(commands: [new CancelJob($this->appId)]);
    }
}
```

- The fact answering a keyed await implements `IAwaitKeyed`
  (`await_key(): ?string`, e.g. the job id). `ddd_process_waits` stores one row
  per (fact class, key), so the answer reaches only the process that minted
  the key; a key nobody waits for is "unheard" (`resume_with_outcome()`
  reports it) and is acked without error.
- Any-of with cancellation:
  `AwaitAny::of(AwaitEvent::keyed(JobFinished::class, $job))->cancelled_by(new AwaitEvent(AppDestroyed::class, ['app_id' => $id]))`.
  The first answer resumes the next step with that fact; a cancellation fact
  compensates every process it names. `->within(3600)` / `->until($instant)`
  add an alarm.
- Dynamic set: `AwaitAll::keyed(ChildPurged::class, $childIds, 3600)` waits for
  every key computed at step time; an empty set does not suspend; the next step
  receives the `AwaitAll` (`gathered()`).
- Register-then-check: a process implementing `IPrecheckAwait` answers
  `already_satisfied($await)` after the await committed and the step's commands
  dispatched; `PrecheckSatisfied::with($value)` resumes in place (the alarm is
  cancelled), so a fact that committed before the suspension is not missed.
- Alarms (D7): `timeout_seconds`, `AwaitAny::until()` and
  `AwaitAlarm::at(new \DateTimeImmutable('2026-10-04T12:00:00Z'))` /
  `AwaitAlarm::after(25 * 3600)` (no fact, just time) are one `timeout` row in
  `ddd_wakeups` with an absolute UTC `due_at`, fixed at suspension. There is no
  upper bound and no chain of short timers; a worker restart changes nothing.
  `on_timeout` is `fail` (compensate the completed steps) or `proceed` (the
  next step receives `null`).
- `start()` from a web request persists the process and a `Continue` intent in
  the caller's transaction; the first step runs in a worker
  (`process.inband_start: false`, the default).
- Repairs: `bin/console ddd:ops:stranded` lists stranded processes;
  `--resume=<id>` re-runs the stranded step with the same deterministic command
  ids, `--fail=<id> --reason=...` fails it (core's repair commands).

## 8. Workflows started by facts (D10)

A behaviour workflow handler that a fact starts implements `IStartsFromFact`
(the `StartsFromFacts` trait gives the defaults) and declares its facts with
`#[StartsOn]`. Registered as a service (resource loading), it is
autoconfigured and gets one ignition subscriber per fact. The dedup key goes
through `ddd_workflow_ignitions` (`DbalWorkflowIgnitionLedger`): exactly one
workflow per key, whatever the redeliveries or concurrent workers.

```php
#[StartsOn(CronEntryDue::class)]
final class NightlyReport extends WorkflowHandler implements IStartsFromFact, IContinuesWorkflows {
    use StartsFromFacts;
    use ReschedulesThroughWakeups;   // reschedule() = a durable ddd_wakeups intent (W1)

    public function workflow_from_fact(IIntegrationEvent $fact): ?BehaviourWorkflow {
        return new BehaviourWorkflow(null, 0, 'nightly-report', [new BuildReportConfig()]);   // null declines
    }

    public function ignition_key(IIntegrationEvent $fact, string $eventId): string {
        // default (trait): once per fact, uuid5(event_id, kind); here: once per (workflow, minute)
        return WorkflowIgnitionKey::per_minute($this->workflow_kind() . ':' . $fact->entry, new \DateTimeImmutable($fact->due_at));
    }
    // get_workflows(), execute_one(), generate_work_items(): as for any WorkflowHandler
}
```

The claim, the workflow save and the attach commit together; the start
(`start_ignited()`, by default `handle_workflow()`) runs after the commit. A
start that throws is retried by the fact's redelivery, which restarts the
attached workflow (it must tolerate a re-run).

With `ReschedulesThroughWakeups`, a run that reaches its resource limits
(25 s or 80 % of `memory_limit`), a step that failed with retries left and a
fork of failed items continue later through `ddd_wakeups`, like process
wakeups: `ddd:relay` projects the due intent, `messenger:consume ddd_wakeups`
runs `continue_workflow()` under a per-workflow lock, and a failing
continuation shows in `ddd:ops:list --layer=wakeup`. Behaviour config classes
in your resource-loaded namespaces are registered at boot (list others under
`tangible_ddd.workflow.behaviour_types`), so no handler has to call
`register_type()` itself.

```yaml
tangible_ddd:
  workflow:
    stale_start_seconds: 900     # a start marker younger than this is "in flight"
  messenger:
    redeliver_timeout_seconds: 3600   # a dead worker's fact comes back after this long
```

A workflow whose worker died mid-start restarts on the fact's redelivery, so
after `max(redeliver_timeout_seconds, stale_start_seconds)`. Lower both for
user-facing workflows. `ddd:ops:list --layer=workflow` lists failed items,
failed workflows and start markers older than `stale_start_seconds`.

## 8a. Several consumers in one app

A reusable bounded context (its own bundle) can be its own consumer, as each
WordPress plugin is: its own tables (a table prefix or a Postgres schema),
outbox, relay, ledger, processes, wakeups, journal and transports.

```yaml
tangible_ddd:
  consumers:
    txp: { namespace_root: App }            # the primary consumer (first): today's service ids and tables
    billing:
      bundle: Acme\Billing\AcmeBillingBundle
      schema: billing
```

Classes belong to the consumer whose namespace root contains them (the
longest root wins), and their port interfaces (`IOutboxStore`,
`ITransactionBoundary`, `ProcessRunner`, ...) autowire to that consumer's
services. A `billing` listener of an `App` fact receives it through a copy
on billing's own transport (`ddd_facts_billing`), exactly once (billing's
ledger). Run `ddd:relay` (or `ddd:relay --consumer=billing`) and
`messenger:consume ddd_facts ddd_wakeups ddd_facts_billing ddd_wakeups_billing`;
migrate each consumer from its own history:
`ddd:schema:dump --consumer=billing --since=NNN`.

## 9. Cause, process id and step index (D13)

| Where | What you can read |
|---|---|
| a listener's `translate()` (the fact scope) | `Correlation::current_fact()` → `FactRef{event_id, event_class, correlation_id}`; put what the handler needs (the event id, a key derived from it) in the command |
| the translated command's handler | `Correlation::peek()->cause->id` is the command id, deterministic: `DeterministicCommandId::for_fact($eventId, $subscriberId)` (`current_fact()` is null inside an act) |
| a process step | `$this->get_id()` (process id), `$this->current_step_index()`, `$this->step_ref('purpose')` (uuid5 over class, id, step index and purpose: the same on a re-run) |
| a step command's handler | `Correlation::peek()->cause->id` is the command id, `DeterministicCommandId::for_step($prefix, $processId, $stepIndex, $ordinal)`; pass the process id, step index or ref in the command when the handler needs them |
| anywhere | `Uuid::v5($namespaceUuid, $name)` (`TangibleDDD\Domain\Shared\Uuid`) for your own deterministic ids |

Use these for job ids and notification dedup: a redelivered fact or a re-run
step produces the same ids, so idempotent handlers and the D1 journal absorb
the repeat.

## 10. Tests in the app

Run DDD tests **without** a per-test transaction wrapper (DAMA and similar): the
code under test owns its transactions. If you must keep a wrapper, set
`tangible_ddd.transaction.nested: savepoint` in the test environment and keep
relay/delivery tests out of it.

The bundle's own kernel tests show the whole path on Postgres 16:
`packages/ddd-symfony/tests/Kernel/ReferenceScenarioTest.php` (a fact-started
saga with a keyed await, an alarm, a D1 effect retried with its journaled
result and a compensation), `WorkflowIgnitionTest.php` (D10) and
`PostCommitWakeupTest.php` / `PostCommitPollFallbackTest.php` (D14). To move
time in a test, replace the `tangible_ddd.clock` service with a
`TangibleDDD\Runtime\FrozenClock` (the reference test does it in a compiler
pass).
