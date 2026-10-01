# Changelog

All notable changes to `tangible/ddd` and the packages split out of it. One version line covers every package.

## 0.7.0 (unreleased)

Prepared on `extraction/ddd-packages`. Nothing is tagged, and no package is published yet. The split mirrors come from `.github/workflows/split.yml`, which runs only by hand and pushes nothing.

This entry describes the integration branch as of the house-style rename (`1b5ffe3`). Waves 1-4 of the extraction are merged and gated by then: the fixes, the package split, the durable contracts on three hosts, and the TXP process demands. Wave 5 is the round in progress. The coordinator decisions already taken for it are included here: the WordPress listener redelivery default and the naming. The other wave-5 items are listed under "Still landing", and each one gets its own line here when it merges.

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

`tangible/ddd-conformance` is the shared scenario suite: 47 scenario ids, run on in-memory, pdo (MySQL 8), wp (WordPress + MySQL 8) and sf (Postgres 16). It is for development only and never ships.

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
- **WordPress delivery isolation**, once a consumer is at schema v8. Every callback registered through DDD (`integration_listener()`, `IntegrationListener`, `integration_action()`, and the process ignition and resume subscribers) now runs behind the per-subscriber delivery ledger. If one callback throws, it is logged, and the rest of the hook's callbacks still run. A callback the ledger already recorded as delivered is skipped when the fact comes again. Raw `add_action` callbacks on integration hooks are outside this guarantee. Hand-fired payloads without `__event_id` keep the 0.6 semantics.
- **WordPress listener redelivery: one attempt by default (wave 5).** A DDD-registered listener that throws is not retried by default. That is the 0.6 behaviour: one attempt, the error logged and recorded in the ledger. A listener opts in to the handler budget: 5 attempts, backoff 30 s × 2ⁿ capped at 3600 s, through the new `{prefix}_ddd_redeliver` hook. Process ignition and resume are not listeners and are unaffected. `wave5/wp-redelivery-default` implements this and names the opt-in. The ddd-symfony and plain-PHP delivery budgets are unchanged (5 by default; `tangible_ddd.delivery.budget` on Symfony).
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
| Workflows (D10) | `IStartsFromFact` with the `StartsFromFacts` trait, `WorkflowIgniter`, `WorkflowIgnitionKey::per_minute()` / `for_fact()`, and `IWorkflowIgnitionLedger`: one workflow per dedup key, whatever the redeliveries | Symfony, plain PHP (not WordPress) |
| Effects (D1) | `IExternalEffectCommand` (`idempotency_key()`, `perform()`, `record()`, `failure_command()`), `EffectResult`, `EffectMiddleware`, `IEffectJournal::invalidate()`. `perform()` runs outside any transaction, and `EffectInsideTransaction` refuses it inside one. A retry reuses the journaled result. The failure command fires once when a listener's handler budget is spent. | Symfony, plain PHP (not WordPress) |
| Audit (D8, D12) | `#[Audit(false)]`, `#[Audit(parameters: false)]`, `AttributeAuditPolicy`, `#[Sensitive]`, `#[NotAudited]` | all |
| Large payloads (D6) | `LargeString` (4 MiB default cap) for binary or large fields in facts and process state; `UndecodableLargeString` quarantines | all |
| Operator view (D9) | `IOperatorView::list()` over the `Layer`s `relay`, `delivery`, `wakeup`, `process`, `workflow` and `transport`, as `OperatorItem`s with their repairs | all |
| WordPress CLI | `wp ddd relay --once` (one relay tick: outbox batch, wakeup re-projection, stranded scan; there is no daemon); `wp ddd ops` (`--layer`, `--format`, and the repairs `--rearm`, `--abandon`, `--resume-stranded`, `--fail-stranded`); `wp ddd drain --before-rollback` | WordPress |
| Symfony console | `ddd:relay` (loop or `--once`, with `LISTEN`/`NOTIFY` wakeup, D14), `ddd:ops:list`, `ddd:ops:stranded`, `ddd:ops:dlq:list` / `retry` / `replay` / `discard`, `ddd:ops:pause` / `resume`, `ddd:schema:dump` | Symfony |
| Plain PHP | `DurableRuntime::compose()` and `drain()` (a core `Drain::run_once()`) over your own `PDO` or `IHostConnection`, with plain `CREATE TABLE IF NOT EXISTS` files in `packages/ddd-core/schema/mysql8/` that you apply yourself (`SchemaSql::statements()`, checked by `SchemaCheck`). `PdoOperatorView::to_arrays()` and `repair()`. There is no bundled database daemon, migrator, DSN parser or connection factory. | plain PHP on MySQL 8 |

Both SQL schemas only grow. A shipped file is never edited, and every change is a new numbered file (`schema/mysql8/released.txt`, `schema/postgres/released.txt`).

### Naming

Every API added in 0.7 follows the house style. Methods and properties are snake_case. Accessors are bare nouns with no `get_` prefix (`status()`, `event_id`, `last_error()`). Predicates start with `is_` or `has_` (`is_unheard()`, `is_satisfied()`). Lookups are `find()` / `find_*()`, and keyed lookups end in `_of()` (`version_of()`, `event_class_of()`). Interfaces are I-prefixed.

The 0.6 API keeps its names: `get_id()`, `get_event_class()`, `get_command()`, `get_by_*()` and the rest. Framework callbacks keep the framework's names, such as `__invoke()`, Symfony `process()` and `getConfigTreeBuilder()`. The full rename of the extraction-era names (402 members) is in `docs/extraction/naming/table.json`.

### Still landing in wave 5

These items close the TXP process-kernel demands. Each gets its line above when it merges:

- AW1 (the event id of the fact that resumed a step), AW2 (lock contention in a resume must not spend the answer's delivery budget), AW3 (unheard keyed answers are logged on Symfony);
- E1 (effects get their dependencies through a handler class), E2 (an effect that was performed but never recorded is visible), E3 (which failure command ran);
- L9 (`AggregateRootRepository` removal with event harvesting), L10 (a core conflict exception, 409);
- W1 (a durable `reschedule()` for workflows on Symfony), W2 (behaviour config types registered at boot), W3 (`workflow.stale_start_seconds` and the transport redelivery timeout), W4 (deterministic ids for work-item commands), W5 (a workflow source in the Symfony operator view);
- the opt-in name of the WordPress listener redelivery budget (see Changed).

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
4. **Let the schema migrate.** Version 8 runs through the existing migration ledger on the next `install_tables()`, and it only adds tables and nullable columns. Intent rows are backfilled for pending Action Scheduler continuations and timeouts.
5. **Check every delayed fact.** Delays now apply once (see Fixed). If you tuned a delay to the doubled behaviour, halve your compensation. tangible-cred's `EndpointAuthRefresh` and `BehaviourWorkflowReschedule` are the known cases, and the table under Fixed shows their new timing.
6. **Decide which listeners should retry.** By default a failing listener runs once, as in 0.6, and it no longer aborts the other callbacks on its hook. Opt a listener in to the 5-attempt budget only if its command can run again for the same fact. On WordPress a retried listener sends its command again, and that command gets a new command id.
7. **Schedule the relay tick if you rely on it.** Action Scheduler still drives delivery. `wp ddd relay --once` from cron also re-projects wakeups whose actions went missing, and runs the stranded scan.
8. **Stay inside what WordPress supports.**
   - Keyed awaits, `AwaitAny`, dynamic `AwaitAll`, external effects (D1) and fact-ignited workflows (D10) are not wired on WordPress, and a 0.6 winner could not decode their state after a rollback. Keep to `AwaitEvent` (with or without `timeout_seconds`) and extractor-keyed `AwaitAll`.
   - `AwaitAlarm` runs on WordPress (`process.alarm-long`), but 0.6 does not know the class. A 0.6 winner reads a process suspended on one as having no await, and the rollback fixtures do not cover what its alarm then does. Avoid it while a rollback to 0.6 is still possible.
9. **Before rolling back to 0.6.x, run `wp ddd drain --before-rollback`.** 0.7 writes only what 0.6 can read on the shared tables (outbox rows stay `completed`, quarantined processes are `failed`), and 0.6 tolerates the newer schema version. Some work is 0.7-only and is lost on a rollback unless you drain it first: pending `{prefix}_ddd_redeliver` and `{prefix}_ddd_wakeup` actions, and facts relayed by reference. Processes waiting at an `#[Async]` step stall under 0.6 until you roll forward. The steps are in [docs/runbooks/rollback.md](docs/runbooks/rollback.md).
10. **Know which version your site reports.** While an older copy loads first, `Infra\Config::version()` and the dashboard can still show that copy's version, because `TANGIBLE_DDD_VERSION` keeps the value of the first copy that defined it. `Tangible_DDD_Versions::instance()->winner()` is authoritative.
