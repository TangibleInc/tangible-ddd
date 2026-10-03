# Changelog

All notable changes to `tangible/ddd` and the packages split out of it. One version line covers every package.

## 0.7.0 (unreleased)

Prepared on `extraction/ddd-packages`. Nothing is tagged, and no package is published yet. The split mirrors come from `.github/workflows/split.yml`, which runs only by hand and pushes nothing.

This entry describes the integration branch at `dfa514a`. All five waves of the extraction are merged and gated: the fixes (wave 1), the package split (wave 2), the durable contracts on three hosts (wave 3), the TXP process demands (wave 4), and the TXP process-kernel demands, the WordPress listener redelivery default and the house-style naming (wave 5). Wave-5 items are marked "(wave 5)" below.

Guides for using 0.7 on each host:

- WordPress: [README.md](README.md)
- Symfony: [examples/symfony/README.md](examples/symfony/README.md)
- plain PHP with PDO: [examples/plain-php-durable/README.md](examples/plain-php-durable/README.md)

Rolling a WordPress site back to 0.6.x: [docs/runbooks/rollback.md](docs/runbooks/rollback.md).

### The package split

The runtime that shipped as `tangible/ddd` 0.6.x is now four Composer packages in one repository. Each lives in its own directory under `packages/`:

| Package | What it is | Who requires it |
|---|---|---|
| `tangible/ddd` (repository root) | The WordPress distribution: the loader, the self-consume shim and the `compat/` alias map, plus `packages/ddd-core` and `packages/ddd-wp` from the same commit. Its manifest `replace`s `tangible/ddd-core` and `tangible/ddd-wp`. | WordPress plugins, unchanged |
| `tangible/ddd-core` | The host-neutral runtime: domain, CQRS over Tactician, events, outbox, relay, delivery, processes, workflows, effects and every port. It also carries in-memory doubles (`TangibleDDD\Testing`) and a PDO default (`TangibleDDD\Defaults\Pdo`) that runs over a connection the host already owns. It depends only on `league/tactician`, `psr/container` and `psr/log`. | plain-PHP hosts, `ddd-wp`, `ddd-symfony` |
| `tangible/ddd-wp` | The WordPress adapter: wpdb stores, the Action Scheduler transport and wakeups, hook facades, the procedural `TangibleDDD\WordPress\*` API, the admin dashboard, WP-CLI and migrations. It exists only as a layer. WordPress plugins do not require it. | `tangible/ddd` only |
| `tangible/ddd-symfony` | The Symfony 7.4 host: a bundle, Doctrine DBAL stores on Postgres 16, a Messenger relay and delivery, processes on an advisory lock, `ddd:relay`, `ddd:ops:*` and `ddd:schema:dump`. It is not part of the WordPress artifact. | Symfony applications |

`tangible/ddd-conformance` is the shared scenario suite: 53 scenario ids, run on in-memory, pdo (MySQL 8), wp (WordPress + MySQL 8) and sf (Postgres 16). It is for development only and never ships.

Every FQCN that consumers name is kept. Classes moved between directories, not between namespaces. 17 classes had a WordPress half and a portable half, and they were split so that each one's 0.6.5 constructor stays callable exactly as compiled code calls it. That includes the containers shipped inside tangible-lms 0.12.0, tangible-quiz 0.7.0 and tangible-certificates 0.3.1. Interfaces that consumers implement gained no abstract methods: `IDDDConfig`, `IOutboxRepository`, `IProcessRepository`, `IBehaviourWorkflowRepository`, `IOutboxPublisher`, `ICommandHandler`, `IQueryHandler`, `IAwaitMechanism` and the rest. Persisted and subscribed strings are unchanged: hook names, Action Scheduler groups and argument keys, option and table names, stored status values, and envelope keys.

The release artifact of `tangible/ddd` contains none of `tests/`, `docs/`, `tools/`, `examples/`, `packages/ddd-symfony/`, `packages/ddd-conformance/` or any package `tests/`. `tests/Compat/release-artifact.sh` checks this on `git archive`.

### Fixed

These three 0.6.x bugs are fixed here. The same fixes, in a form that needs no schema change, are prepared on `hotfix/0.6.7`. That branch is not released.

- **A NULL or failed `GET_LOCK` ran a process step unlocked.** The old check was `(string) $acquired === '0'`, which let a NULL result through. Now only a definite acquisition enters the critical section. A timeout, a NULL or a query error all mean "not acquired": no step runs and nothing is saved. On 0.7 the wake is re-queued instead of lost (a ResumeRetry intent). On 0.6.7 the failed Action Scheduler action stays visible for a manual retry instead.
  - *Consumer impact:* a contended process now waits its turn where it used to run unlocked. Any consumer with processes is affected; cred is the one that ignites them by fact.
- **`#[StartsOn]` ignition was check-then-insert.** Two deliveries of one fact could both ignite a process. Now exactly one ignited process row exists per `(process class, event id)`, enforced by `UNIQUE (process_class, ignition_key)`. The losing delivery returns quietly without running a step. A dead-letter replay keeps the fact's event id, so it no longer double-ignites either. On 0.6.7 a named lock covers the re-check and the insert, and replay still mints a new id. Manual `start()` calls are never deduplicated.
  - *Consumer impact:* cred is the only consumer that ignites processes from facts. A redelivered or replayed fact no longer starts a second process.
- **Delayed facts were delayed twice.** The outbox row already carried `scheduled_at = now + delay`, and the Action Scheduler publisher added the delay again, and again on every relay retry. The due time is now absolute UTC on the row and is honoured once (`max(now, due)`). Retries are gated by `next_attempt_at` only. Rows written by older code are not delayed again. 0.7 writes `delay_seconds = 0`, so a 0.6 winner relays those rows on time too. **Behaviour change: delays halve to their declared value.** Action Scheduler actions queued before the upgrade keep the doubled delay.
  - *Consumer impact:* only tangible-cred emits delayed facts. Its call sites at `9459b949` work out as follows:

    | cred fact | Raised with | Effective delay on 0.6.x | On 0.7 |
    |---|---|---|---|
    | `EndpointAuthRefresh` (auth not ready yet) | `delay = 60` | about 120 s, plus 60 s per relay retry | 60 s |
    | `EndpointAuthRefresh` (token refresh) | `delay = max(1, expires_at − now − max(30, min_ttl_seconds))` | twice the time to the refresh point. A token that lives 3600 s with the 30 s skew was refreshed after about 7140 s, roughly an hour **after** it expired | 3570 s, 30 s **before** expiry |
    | `BehaviourWorkflowReschedule` (resource limits, `WorkflowHandler::$reschedule_interval`) | 5 s | 10 s | 5 s |
    | `BehaviourWorkflowReschedule` (forked failed items, `$fork_delay_seconds`) | 30 s | 60 s | 30 s |
    | `BehaviourWorkflowReschedule` (user-triggered retry) | `hours × 3600 + minutes × 60` from the behaviour config | twice the configured interval | the configured interval |
    | `EndpointAuthRefresh` / `BehaviourWorkflowReschedule` with delay 0 | 0 | immediate | immediate (unchanged) |

    Register O10 still stands: before any release, ask cred's owner whether any of these values were tuned to compensate for the doubled delay. If one was, halve the compensation. Hooks on `{prefix}_outbox_publish_external` must not add `delay_seconds` themselves.

### Changed

- PHP 8.2 is the floor in the manifests and in the plugin header. Symfony 7.4 already required it.
- `symfony/yaml` is now a `require`, not a `require-dev`. Without it the self-consumer silently disappeared. `psr/log` (`^1|^2|^3`) is required as well, because runtime loggers are PSR-3.
- The loader runs through a version-unique Composer `files` entry, `loader/tangible-ddd-0_7_0.php`. Composer's cross-vendor dedup can therefore no longer hide a newer copy behind an older one, and newest-wins holds whichever plugin's autoloader runs first. The winner's autoloader serves every `TangibleDDD\` class from its own `packages/ddd-core` and `packages/ddd-wp`, and consults the `compat/` map. It reports these cases instead of hiding them:
  - a fall-through to another copy;
  - a class an older copy loaded before the winner booted (a mixed load);
  - a 0.2.x copy on the site, raised as `TANGIBLE_DDD_UNSUPPORTED_VERSION` under `WP_DEBUG` and logged otherwise.
- WordPress schema version 8 is additive only. It adds:
  - the tables `{prefix}_ddd_wakeups`, `{prefix}_ddd_delivery_ledger` and `{prefix}_ddd_relay_pauses`;
  - the nullable `long_processes` columns `ignition_key` (unique together with `process_class`, backfilled from ignition-path rows; duplicates are reported, never deleted), `quarantine_reason`, `version` and `start_path`;
  - the outbox column `claim_token`.

  Relay pauses are read from both the new rows and the old option until the option drains. Outbox rows keep the status `completed`. Quarantined processes are `failed` with `quarantine_reason` set.
- **WordPress schema version 9 (wave 5)** is additive too. It adds one nullable column, `fact` (`LONGTEXT NULL`), on `{prefix}_ddd_wakeups`, which holds a parked answer (AW2, under Added). A consumer at v9 gets `WpdbParkingScheduler`; at v8 it keeps `WpdbWakeupScheduler`. 0.6 never reads the table.
- **WordPress on a fresh database (wave 5).** On a fresh install, the first request used to wire no `#[StartsOn]` / `#[Awaits]` hook and no outbox hook: `register_hooks()` (init:2) cached "tables absent" before `ddd_maybe_migrate()` created them at init:3. The probe cache now lives in `WpSchema` and is forgotten after `install_tables()` (`WpSchema::forget_tables()`), and `ddd_maybe_migrate()` fires the new action `TangibleDDD\WordPress\TABLES_INSTALLED_ACTION` (`'tangible_ddd_tables_installed'`, argument: the consumer's `IDDDConfig`). Whatever was closed at init:2 is wired in the same request, once, when its consumer's tables are announced. A migration that runs later (admin_init, WP-CLI) is covered the same way.
- **WordPress delivery isolation**, once a consumer is at schema v8. Every callback registered through DDD (`integration_listener()`, `IntegrationListener`, `integration_action()`, and the process ignition and resume subscribers) now runs behind the per-subscriber delivery ledger. If one callback throws, it is logged, and the rest of the hook's callbacks still run. A callback the ledger already recorded as delivered is skipped when the fact comes again. Raw `add_action` callbacks on integration hooks are outside this guarantee. Hand-fired payloads without `__event_id` keep the 0.6 semantics.
- **WordPress listener redelivery: one attempt by default (wave 5).** A DDD-registered listener that throws is not retried by default, which is the 0.6 behaviour. The ledger records the pair as failed (attempt 1, with the error) and then exhausted, and its on-exhausted compensation runs once. `wp ddd ops` lists the pair against that listener's real budget. No `{prefix}_ddd_redeliver` is scheduled for it.
  - "Listener" covers `integration_action()`, `integration_listener()` / `IntegrationListener`, and any `SubscriptionRegistrar` or custom subscriber.
  - Process ignition, process resume and workflow ignition keep the budget of 5.
  - A listener opts in to retries. The retries run through `{prefix}_ddd_redeliver`, with backoff 30 s × 2ⁿ capped at 3600 s. The opt-in is resolved per delivery, in this order:
    1. `#[TangibleDDD\WordPress\Retries(n)]` on the listener class, method, function or closure gives n + 1 attempts;
    2. else the option `{prefix}_ddd_delivery_attempts`, for the whole consumer;
    3. else one attempt.

    The filter `tangible_ddd_delivery_attempts` (`$attempts, $subscriber_id, $prefix`) then has the last word. The result is never below 1.
  - `WpLedgeredDelivery::budget($prefix, $subscriber_id)` resolves it. The constants are `LISTENER_ATTEMPTS` (1), `ATTEMPTS_OPTION` and `ATTEMPTS_FILTER`. `BUDGET` (5) is the kernel budget.
  - The ddd-symfony and plain-PHP delivery budgets are unchanged: 5 by default, `tangible_ddd.delivery.budget` on Symfony.
- A satisfied await cancels its timeout intent, so the projected Action Scheduler action is unscheduled. A copy of the action that still fires is a no-op.
- `RetryDeliveryCommand` refuses a leased row, and any row that is not `pending` or `dlq`. 0.6 reset any row. Only the administration port's explicit `force` overrides the status check.
- An expired relay lease that is claimed again counts as one relay attempt. A row whose submitter keeps dying is dead-lettered at claim once it reaches `max_attempts`, instead of being re-claimed forever. This applies on every host.
- Facts over Action Scheduler's 8000-byte args limit are relayed by reference to their outbox row (`WpLargeEnvelope`) instead of being refused. Small envelopes keep the 0.6 action shape byte for byte.
- A wake of a quarantined process completes its Action Scheduler action, and the process row records the reason. It used to fail the action, and nothing could ever retry it.
- Audit rows mask more parameters:
  - any key containing `password`, `passwd` or `secret`;
  - any key ending in `token`;
  - the keys `private_key`, `secret_key`, `apikey`, `pem` and `credentials`.

  PEM values are redacted under any key. Binary strings are summarised without their content.
- Outbox appends refuse a payload that is not JSON-encodable (`UnencodablePayload`) or is larger than 8 MiB encoded (`PayloadTooLarge`). The 0.6 repository form of the outbox is unchanged.
- In a process step, the checkpoint of a suspending step is persisted with its await. Its compensation now receives that checkpoint instead of `null`.
- 0.6-shaped awaits keep first-wins: an unkeyed `AwaitEvent`, an extractor-keyed `AwaitAll`, or a consumer's own mechanism. The new keyed awaits and `AwaitAny` take a fact in every suspended process that accepts it (see Added).
- **A contended fact resume is parked, not failed (wave 5, AW2), on every host.** When a resume cannot take the process lock, the runner stores the fact in a ResumeRetry wakeup intent and the `resume:` subscriber returns normally, so the ledger marks it delivered. The answer never spends its delivery budget and is never dead-lettered while its process waits. The wake re-reads the process under the lock and resumes it only if it still waits at that step for that fact. A wake that keeps meeting the lock is retried on the wake budget (2 s × 2ⁿ, capped at 300 s) and, from the 10th failure (`WakeRetryPolicy::BUDGET`), reported exhausted in the `wakeup` operator layer. On the core drain (plain PHP, in-memory) and on ddd-symfony it is still retried at the cap, so it is never dropped. On ddd-symfony that is the HC5-1 fix: an exhausted retryable wake used to be skipped by `claim_due()` until an operator re-armed it. On WordPress an exhausted wake waits for `wp ddd ops --rearm`. The production schedulers opt in through the marker `ICarriesFacts`: `DbalParkingScheduler` (sf), `PdoParkingJobStore` (pdo), `WpdbParkingScheduler` (wp at schema v9), and `InMemoryParkingScheduler` in the in-memory doubles. `InMemoryWakeupScheduler` keeps the wave-3 behaviour, a failed resume subscriber.
- **A recorded effect is not recorded again (wave 5, E2).** With a journal that tracks entry states (`ITracksEffectState`: the sf, pdo and in-memory journals; WordPress has no effect journal), re-dispatching an effect whose entry is `recorded` returns the journaled result without calling `record()`. An entry that is only `performed` reuses the result and runs `record()` again. Only `invalidate()` makes it perform again.
- **Work-item commands get deterministic ids (wave 5, W4).** The first command an item's `execute_one()` dispatches gets `DeterministicCommandId::for_item()` as its id (it was random). A crash re-run of the item sends the same id, so an idempotent handler absorbs it.
- **`PersistenceConflict` is a `ConflictException` (wave 5, L10).** The ddd-symfony class now extends core `ConflictException` (a `BusinessConstraintException`, so `\Exception`), and an edge maps it to 409. It is **no longer a `\RuntimeException`**: a `catch (\RuntimeException)` around a save stops catching it. ddd-symfony has no such catch block; check your own.
- `DbalWakeupScheduler`, `PdoJobStore`, `WpdbWakeupScheduler` and `InMemoryWakeupScheduler` are no longer `final`, so the parking subclasses can extend them (wave 5).

### Added

The ports and the portable runtime live under `TangibleDDD\Runtime\`. Each host has adapters for them. The table names the main APIs. The per-host guides show them in use.

| Area | What | Hosts |
|---|---|---|
| Commands | `IReturningCommandHandler`, a plain handler whose `handle()` value comes back out of `send()` (D11, L1). `AggregateRoot` and `AggregateRootRepository::save()` for aggregates without integer identity (L2). `NotPermittedException`, the 403 family (L7). | all |
| Facts and listeners | `IntegrationTranslator`, the host-neutral listener (`event_class()`, `translate()`), which the WordPress `IntegrationListener` now extends; a D2 marker interface as the subscribed class; `#[SubscriberPriority]`. `Correlation::current_fact()` returns a `FactRef` (`event_id`, `event_class`, `correlation_id`) inside a listener. | all (D2 markers and `#[SubscriberPriority]`: not on WordPress) |
| Deterministic ids | `DeterministicCommandId::for_fact()` and `for_step()` (D13), `LongProcess::step_ref()`, and `Uuid::v5()` | all |
| Processes | `StartMode::Deferred`, so `start()` inside a command commits atomically with it (the default on Symfony and plain PHP); the per-process lock fails closed; version-fenced saves; the stranded scan and the `ResumeStrandedProcess` / `FailStrandedProcess` repairs; `#[RetryStep(attempts, backoff_seconds)]`; quarantine of undecodable rows (`failed` + `quarantine_reason`) | all |
| Awaits (D3) | `AwaitEvent::keyed()` with `IAwaitKeyed::await_key()`; `AwaitAny::of()->cancelled_by()->within()` / `until()`; `AwaitAll::keyed()` over a key set computed at step time; `IPrecheckAwait::already_satisfied()` with `PrecheckSatisfied::with()`; `ProcessRunner::resume_with_outcome()` returning a `ResumeReport` (`is_unheard()`) | Symfony, plain PHP, in-memory (not WordPress) |
| Alarms (D7) | `AwaitAlarm::at()` / `after()` and `timeout_seconds` on any await, stored as one durable intent with an absolute UTC due time and no upper bound | all (see migration step 8 for `AwaitAlarm` on WordPress) |
| Workflows (D10) | `IStartsFromFact` with the `StartsFromFacts` trait, `WorkflowIgniter`, `WorkflowIgnitionKey::per_minute()` / `for_fact()`, and `IWorkflowIgnitionLedger`: one workflow per dedup key, whatever the redeliveries | Symfony, where the bundle subscribes them. Plain PHP has the stores, and the host calls `WorkflowIgniter::ignite()` itself. Not WordPress. |
| Effects (D1) | `IExternalEffectCommand` (`idempotency_key()`, `perform()`, `record()`, `failure_command()`), `EffectResult`, `EffectMiddleware`, `IEffectJournal::invalidate()`. `perform()` runs outside any transaction, and `EffectInsideTransaction` refuses it inside one. A retry reuses the journaled result. The failure command fires once when a listener's handler budget is spent. | Symfony, plain PHP (not WordPress) |
| Audit (D8, D12) | `#[Audit(false)]`, `#[Audit(parameters: false)]`, `AttributeAuditPolicy`, `#[Sensitive]`, `#[NotAudited]` | all |
| Large payloads (D6) | `LargeString` (4 MiB default cap) for binary or large fields in facts and process state; `UndecodableLargeString` quarantines | all |
| Operator view (D9) | `IOperatorView::list()` over the `Layer`s `relay`, `delivery`, `wakeup`, `process`, `workflow` and `transport`, as `OperatorItem`s with their repairs | all |
| WordPress CLI | `wp ddd relay --once` (one relay tick: outbox batch, wakeup re-projection, stranded scan; there is no daemon); `wp ddd ops` (`--layer`, `--format`, and the repairs `--rearm`, `--abandon`, `--resume-stranded`, `--fail-stranded`); `wp ddd drain --before-rollback` | WordPress |
| Symfony console | `ddd:relay` (loop or `--once`, with `LISTEN`/`NOTIFY` wakeup, D14), `ddd:ops:list`, `ddd:ops:stranded`, `ddd:ops:dlq:list` / `retry` / `replay` / `discard`, `ddd:ops:pause` / `resume`, `ddd:schema:dump` | Symfony |
| Conflicts (L10, wave 5) | `TangibleDDD\Domain\Exceptions\ConflictException`, the 409 family, a `BusinessConstraintException` and a sibling of `NotPermittedException` (403). ddd-symfony's `PersistenceConflict` extends it (see Changed). | all |
| Removal (L9, wave 5) | `AggregateRootRepository::remove()`: the class check of `save()`, then your `delete()`, then the aggregate's recorded events are collected. `remove()` is final. `delete()` is protected and not abstract; the default throws `\LogicException` before anything is collected. | all |
| Resume cause (AW1, wave 5) | `LongProcess::resumed_by_event_id()`: the event id of the fact that resumed the current step (for an `AwaitAll`, the fact that completed the gather). Null in a step no fact resumed. Stored in the `steps` JSON every host already persists whole, so a `#[RetryStep]` re-run, an `#[Async]` continuation and a parked resume read the same id. | all |
| Parked answers (AW2, wave 5) | `ICarriesFacts` (scheduler marker), `WakeupIntent::$fact` and `WakeupIntent::resume_fact()`, `ResumeReport::$deferred`, `ProcessRunner::resume_with_outcome($event, $event_id)`; the parking schedulers listed under Changed. Storage: sf schema 011 (`ddd_wakeups.fact`), pdo schema 011 (side table `ddd_job_facts`, because MySQL 8 has no `ADD COLUMN IF NOT EXISTS`), wp schema v9 (`{prefix}_ddd_wakeups.fact`). | all |
| Unheard answers (AW3, wave 5) | A keyed answer no suspended process takes is logged and noted on its ledger pair (`unheard_at`); an accepted fact no subscriber is wired for is noted on its outbox row and listed in the `relay` layer. Schema 010. | Symfony |
| Handler-class effects (E1, wave 5) | `IEffectCommand` (the effect as data: `idempotency_key()`, `failure_command()`), which `IExternalEffectCommand` now extends; `IExternalEffectHandler` (`perform()`, `record()`), a service with dependencies that `EffectMiddleware` locates through the command handler locator and mapping; `NoEffectHandler` when none is found, before anything is performed. sf autoconfigures handlers (`EffectHandlersPass`); pdo resolves them from the `$handlers` array or the naming convention. A self-contained `IExternalEffectCommand` is unchanged. | Symfony, plain PHP |
| Effect states (E2, wave 5) | `EffectState` (`performed`, `recorded`), `EffectEntry`, `ITracksEffectState` (`mark_recorded()`, `find_entry()`, `find_unrecorded()`) on the sf, pdo and in-memory journals; the operator layer `Layer::Effect` (`effect`) with the source `UnrecordedEffects` (entries performed more than 300 s ago and not recorded, repair `invalidate`). Storage: sf schema 011 (`ddd_effect_journal.recorded_at`), pdo schema 010 (side table `ddd_effect_recorded`). | Symfony, plain PHP |
| Effect failure record (E3, wave 5) | The failure command an exhausted pair sent, and when (`ddd_delivery_ledger.failure_command`, `failure_command_at`, schema 010) | Symfony |
| Effect repair command (wave 5) | `ddd:ops:effects:invalidate <key>... [--reason=]`, each key in its own transaction on the primary consumer; exit 1 when a key has no live entry. pdo: the `PdoOperatorView` repair `invalidate`. | Symfony, plain PHP |
| Behaviour types (W2, wave 5) | `IBehaviourTypes` and `BehaviourTypes`, a registry the host provides through `HostDefaults`; `BaseBehaviourConfig::register_type()` writes to it and `hand_over_types()` moves include-time registrations into it. sf fills it at compile time from autoconfigured `BaseBehaviourConfig` subclasses and `tangible_ddd.workflow.behaviour_types`; pdo `DurableRuntime::behaviour_types()`. On WordPress, `register_type()` keeps working through the process-wide fallback. | all (registry service: Symfony, plain PHP) |
| Work-item ids (W4, wave 5) | `DeterministicCommandId::for_item($consumer, $workflow_id, $behaviour_idx, $phase, $item_key, $ordinal = 0)` and `WORKFLOW_NAMESPACE`; `WorkflowHandler::item_command_id()` for an item's further commands | all |
| Workflows on Symfony (W1, W3, W5, wave 5) | `IContinuesWorkflows` with the `ReschedulesThroughWakeups` trait: `reschedule()` is a durable `ddd_wakeups` intent run under a per-workflow lock. `workflow.stale_start_seconds`, `workflow.stale_claim_seconds`, `messenger.redeliver_timeout_seconds`. The `workflow` operator layer lists failed items, failed workflows and stale start markers. | Symfony |
| Several consumers (wave 5) | `tangible_ddd.consumers` (a map; exactly one of `consumer` and `consumers` is required). Each consumer has its own tables (a table prefix, or a Postgres schema through `[schema.]prefix`), outbox, relay, ledger, processes, wakeups, journal and transports. A fact reaches another consumer's subscribers through an addressed copy on that consumer's transport (`FactAudience`, `ConsumerRouter`), once, under its ledger. `ddd:relay`, `ddd:ops:list` and `ddd:schema:dump` take `--consumer=`. The single-consumer `consumer:` configuration builds the same services as before. | Symfony |
| Test bootstrap (wave 5) | `TangibleDDD\WordPress\wire_unbooted($root)` (`packages/ddd-wp/wordpress/unbooted.php`): an explicit API for a test bootstrap that stubs WordPress and never fires `plugins_loaded`. The loader does not call it: the bootstrap `require`s the file and calls `wire_unbooted()` with the root of its `tangible/ddd` copy. It then wires `HostDefaults` on the first miss, guarded to the winning copy's classes. It does nothing under real WordPress (`WPINC` defined), without `add_action`, or once `plugins_loaded` fired. | WordPress tests |
| Plain PHP | `DurableRuntime::compose()` and `drain()` (a core `Drain::run_once()`) over your own `PDO` or `IHostConnection`, with plain `CREATE TABLE IF NOT EXISTS` files in `packages/ddd-core/schema/mysql8/` that you apply yourself (`SchemaSql::statements()`, checked by `SchemaCheck`). `PdoOperatorView::to_arrays()` and `repair()`. There is no bundled database daemon, migrator, DSN parser or connection factory. | plain PHP on MySQL 8 |

Both SQL schemas only grow. A shipped file is never edited, and every change is a new numbered file (`schema/mysql8/released.txt`, `schema/postgres/released.txt`).

### Naming

Every API added in 0.7 follows the house style. Methods and properties are snake_case. Accessors prefer a bare noun (`status()`, `event_id`, `last_error()`): drop `get_` where the name reads fine without it, and keep it where the bare noun would be ambiguous, would read like a verb, or would break a family of existing names. That part is a preference, not a rule. Predicates start with `is_` or `has_` (`is_unheard()`, `is_satisfied()`). Lookups are `find()` / `find_*()`, and keyed lookups end in `_of()` (`version_of()`, `event_class_of()`). Interfaces are I-prefixed.

The 0.6 API keeps its names: `get_id()`, `get_event_class()`, `get_command()`, `get_by_*()` and the rest. Framework callbacks keep the framework's names, such as `__invoke()`, Symfony `process()` and `getConfigTreeBuilder()`. The full rename of the extraction-era names (402 members) is in `docs/extraction/naming/table.json`.

### Supported combinations

- WordPress with `tangible/ddd` 0.7.0: supported in each of these setups. The newest copy wins and every `TangibleDDD\` class loads from it, including next to plugins that use the Jetpack Autoloader.
  - 0.7.0 alone;
  - beside any 0.6.2 to 0.6.7 copy, in either load order;
  - beside the same 0.7.0 in another plugin;
  - with `tangible-ddd` activated as a plugin.
- MySQL 8 and Postgres 16 are claimed. MariaDB is not claimed and not tested.
- `ddd-core` and `ddd-wp` from different plugins or versions: unsupported. The winner always serves both from its own copy, and `replace` keeps them out of a single vendor tree.
- `TangibleDDD\Defaults\Pdo` under WordPress: unsupported. `ddd-wp` never uses it.
- 0.1.x anywhere, and any 0.2.x copy beside a 0.6+ consumer: unsupported, and reported rather than silent.
- `ddd-symfony` workers on a pooled Postgres endpoint: unsupported (boot warning, or a refusal with `process.pooled_connection: refuse`). Use a direct endpoint for `ddd:relay` and `messenger:consume`.

### Migrating a WordPress plugin from 0.6.x

1. **Keep requiring `tangible/ddd`** and change the constraint, for example `"tangible/ddd": "^0.7"`. Do not require `tangible/ddd-core` or `tangible/ddd-wp` directly. The root package replaces both. A WordPress plugin that bundles `ddd-core` without `tangible/ddd` is unsupported, because on WordPress core always comes from the winning distribution.
2. **Run PHP 8.2 or later**, and let Composer install `symfony/yaml` and `psr/log`. They are runtime dependencies now, so a `--no-dev` build must include them. As before, roots that use `minimum-stability: stable` must restate `"league/tactician": "^2.0-rc1"`.
3. **No code changes are expected.** Your `IDDDConfig`, handlers, listeners, repositories, processes and YAML service definitions load unchanged, and a container compiled against 0.6.5 keeps working.
   - Code that `require`s files under `vendor/tangible/ddd/ddd-src/` or `ddd-wordpress/` directly should stop. The sources now live in `packages/ddd-core/src`, `packages/ddd-wp/src` and `packages/ddd-wp/wordpress`.
   - `ddd-wordpress/self/index.php` is kept as a forwarding shim.
4. **Let the schema migrate.** Versions 8 and 9 run through the existing migration ledger on the next `install_tables()`, and they only add tables and nullable columns. Intent rows are backfilled for pending Action Scheduler continuations and timeouts. If your plugin wires something of its own once the DDD tables exist, hook `TangibleDDD\WordPress\TABLES_INSTALLED_ACTION` rather than probing the tables at `init`.
5. **Check every delayed fact.** Delays now apply once (see Fixed). If you tuned a delay to the doubled behaviour, halve your compensation. tangible-cred's `EndpointAuthRefresh` and `BehaviourWorkflowReschedule` are the known cases, and the table under Fixed shows their new timing.
6. **Decide which listeners should retry.** By default a failing listener runs once, as in 0.6, and it no longer aborts the other callbacks on its hook. Opt a listener in with `#[Retries(n)]`, or a whole consumer with the `{prefix}_ddd_delivery_attempts` option (see Changed), but only if its command can run again for the same fact. On WordPress a retried listener sends its command again, and that command gets a new command id.
7. **Schedule the relay tick if you rely on it.** Action Scheduler still drives delivery. `wp ddd relay --once` from cron also re-projects wakeups whose actions went missing, and runs the stranded scan.
8. **Stay inside what WordPress supports.**
   - Keyed awaits, `AwaitAny`, dynamic `AwaitAll`, external effects (D1) and fact-ignited workflows (D10) are not wired on WordPress, and a 0.6 winner could not decode their state after a rollback. Keep to `AwaitEvent` (with or without `timeout_seconds`) and extractor-keyed `AwaitAll`.
   - `AwaitAlarm` runs on WordPress (`process.alarm-long`), but 0.6 does not know the class. A 0.6 winner reads a process suspended on one as having no await, and the rollback fixtures do not cover what its alarm then does. Avoid it while a rollback to 0.6 is still possible.
9. **Before rolling back to 0.6.x, run `wp ddd drain --before-rollback`.** 0.7 writes only what 0.6 can read on the shared tables (outbox rows stay `completed`, quarantined processes are `failed`), and 0.6 tolerates the newer schema version. Some work is 0.7-only and is lost on a rollback unless you drain it first: pending `{prefix}_ddd_redeliver` and `{prefix}_ddd_wakeup` actions, and facts relayed by reference. At schema v9 a `{prefix}_ddd_wakeup` action can be the only copy of a parked answer, because its fact's resume subscriber was already marked delivered. Processes waiting at an `#[Async]` step stall under 0.6 until you roll forward. The steps are in [docs/runbooks/rollback.md](docs/runbooks/rollback.md).
10. **Know which version your site reports.** While an older copy loads first, `Infra\Config::version()` and the dashboard can still show that copy's version, because `TANGIBLE_DDD_VERSION` keeps the value of the first copy that defined it. `Tangible_DDD_Versions::instance()->winner()` is authoritative.
11. **Test bootstraps that stub WordPress.** A bootstrap that defines `add_action` before `vendor/autoload.php` and never fires `plugins_loaded` gets no `HostDefaults` wiring from the loader. Require `packages/ddd-wp/wordpress/unbooted.php` from your `tangible/ddd` copy and call `TangibleDDD\WordPress\wire_unbooted($root)` with that copy's root.

### Migrating a Symfony app or a plain-PHP host from an earlier 0.7 build

Nothing was released before 0.7.0, so this only concerns apps that track the integration branch, such as TXP.

1. **Apply the new schema files.** ddd-symfony: `bin/console ddd:schema:dump --since=009` prints `010_delivery_notes.sql` and `011_effect_states_and_facts.sql`. Without 011 the effect journal fails on `recorded_at`. Plain PHP: apply `packages/ddd-core/schema/mysql8/010_effect_recorded.sql` and `011_job_facts.sql`. `SchemaCheck` names any table that is missing. Effect entries stored before the new schema have no recorded mark, so they list as unrecorded in the `effect` layer once they are older than 300 s.
2. **Check `catch (\RuntimeException)` around saves.** `PersistenceConflict` is a `ConflictException` now and no longer a `\RuntimeException` (L10).
3. **Rename a repository's own `remove()` to `delete()`.** `AggregateRootRepository::remove()` is final (L9). A subclass that declared a public `remove()` must rename it to a protected `delete()`, and callers keep calling `remove()`, which now also collects the aggregate's events.
4. **Drop hand-written `register_type()` calls in handler constructors.** On ddd-symfony, behaviour config classes from your resource-loaded namespaces are registered at boot (W2). List any others under `tangible_ddd.workflow.behaviour_types`.
5. **Expect a contended answer to be parked.** A test that asserted a contended fact resume spends the delivery budget or is dead-lettered (TXP's `ProcessLockContentionTest`) now sees the answer parked and the process resumed once the lock frees (AW2).
