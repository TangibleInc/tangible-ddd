# Changelog

All notable changes to `tangible/ddd` and the packages split out of it. One version line covers every package.

## 0.7.0 (unreleased)

Prepared on `extraction/ddd-packages`. Nothing is tagged, and no package is published yet: the split mirrors come from `.github/workflows/split.yml`, which runs only by hand and pushes nothing.

This entry covers what the integration branch holds today. These items are still landing and get entries here when they merge:

- effect journal and step retries (D1);
- `AwaitAny` and keyed awaits (D3);
- large payloads (D6);
- long alarms (D7);
- workflow ignition dedup (D10);
- `IAuditPolicy` (D12);
- the stranded-process repairs;
- the 7.3 rollback fixtures.

### The package split

The runtime that shipped as `tangible/ddd` 0.6.x is now four Composer packages in one repository, each in its own directory under `packages/`:

| Package | What it is | Who requires it |
|---|---|---|
| `tangible/ddd` (repository root) | The WordPress distribution: the loader, the self-consume shim and the `compat/` alias map, with `packages/ddd-core` and `packages/ddd-wp` from the same commit. Its manifest `replace`s `tangible/ddd-core` and `tangible/ddd-wp`. | WordPress plugins, unchanged |
| `tangible/ddd-core` | The host-neutral runtime: domain, CQRS over Tactician, events, outbox, relay, delivery, processes and every port, plus in-memory doubles and a PDO default (`TangibleDDD\Defaults\Pdo`) over a connection the host already owns. Its dependencies are only `league/tactician`, `psr/container` and `psr/log`. | plain-PHP hosts, `ddd-wp`, `ddd-symfony` |
| `tangible/ddd-wp` | The WordPress adapter: wpdb stores, the Action Scheduler relay and wakeups, hook facades, the procedural `TangibleDDD\WordPress\*` API, the admin dashboard, WP-CLI and migrations. It is a layering artifact. WordPress plugins do not require it. | `tangible/ddd` only |
| `tangible/ddd-symfony` | The Symfony host: a bundle, Doctrine DBAL stores on Postgres, a Messenger relay and delivery, advisory-lock processes, `ddd:relay`, `ddd:ops:*`, `ddd:schema:dump`. It is not in the WordPress artifact. | Symfony applications |

`tangible/ddd-conformance` (the shared scenario suite) is development-only and never ships.

Every FQCN consumers name is kept. Classes moved between directories, not namespaces, and the 17 classes that had WordPress and portable halves were split so that the 0.6.5 constructor of each stays callable exactly as compiled. That includes the containers shipped inside tangible-lms 0.12.0, tangible-quiz 0.7.0 and tangible-certificates 0.3.1. Interfaces that consumers implement (`IDDDConfig`, `IOutboxRepository`, `IProcessRepository`, `IBehaviourWorkflowRepository`, `IOutboxPublisher`, `ICommandHandler`, `IQueryHandler`, ...) have no new abstract methods. Persisted and subscribed strings (hook names, Action Scheduler groups and argument keys, option and table names, stored status values, envelope keys) are unchanged.

The release artifact of `tangible/ddd` contains no `tests/`, `docs/`, `tools/`, `examples/`, `packages/ddd-symfony/`, `packages/ddd-conformance/` or package `tests/`. `tests/Compat/release-artifact.sh` checks this on `git archive`.

### Fixed

These three 0.6.x bugs are fixed here. The same fixes, in their schema-free form, are prepared on `hotfix/0.6.7`, which is not released.

- **A NULL or failed `GET_LOCK` ran a process step unlocked.** `(string) $acquired === '0'` let a NULL result through. Now only a definite acquisition enters the critical section. A timeout, a NULL or a query error means "not acquired": no step runs, nothing is saved, and the wake is re-queued instead of lost. On 0.6.7 the failed Action Scheduler action stays visible for a manual retry instead.
- **`#[StartsOn]` ignition was check-then-insert.** Two deliveries of one fact could both ignite a process. Now exactly one ignited process row exists per `(process class, event id)`, enforced by `UNIQUE (process_class, ignition_key)`. The losing delivery returns quietly without running a step. A dead-letter replay keeps the fact's event id, so it no longer double-ignites either. On 0.6.7 a named lock covers the re-check and insert, and replay still mints a new id. Manual starts are never deduplicated.
- **Delayed facts were delayed twice.** The outbox row already carried `scheduled_at = now + delay`, and the Action Scheduler publisher added the delay again on every retry. The due time is now absolute UTC on the row and honoured once (`max(now, due)`), and retries are gated by `next_attempt_at` only. Rows written by older code are not delayed again. **Behaviour change: delays halve to their declared value.** Action Scheduler actions queued before the upgrade keep the doubled delay.

### Changed

- PHP 8.2 is the floor in the manifests and the plugin header. Symfony 7.4 already required it.
- `symfony/yaml` is a `require`, not a `require-dev`. Without it the self-consumer silently disappeared. `psr/log` (`^1|^2|^3`) is required too: runtime loggers are PSR-3.
- The loader runs through a version-unique Composer `files` entry, `loader/tangible-ddd-0_7_0.php`. Composer's cross-vendor dedup can therefore no longer suppress a newer copy behind an older one, and newest-wins holds whichever plugin's autoloader runs first. The winner's autoloader serves every `TangibleDDD\` class from its own `packages/ddd-core` and `packages/ddd-wp`, consults the `compat/` map, and reports instead of hiding:
  - a fall-through to another copy;
  - a class an older copy loaded before the winner booted (mixed load);
  - a 0.2.x copy on the site, raised as `TANGIBLE_DDD_UNSUPPORTED_VERSION` under `WP_DEBUG` and logged otherwise.
- WordPress schema version 8 is additive only:
  - the tables `{prefix}_ddd_wakeups` and `{prefix}_ddd_delivery_ledger`;
  - the nullable `long_processes` columns `ignition_key` (unique with `process_class`, backfilled from ignition-path rows; duplicates are reported, never deleted), `quarantine_reason`, `version` and `start_path`;
  - outbox `claim_token`;
  - relay pause rows, read alongside the old option until it drains.

  Outbox rows keep the status `completed`. Quarantined processes are `failed` with `quarantine_reason` set.
- A satisfied await cancels its timeout intent, so the projected Action Scheduler action is unscheduled. A copy of the action that still fires is a no-op.
- `RetryDeliveryCommand` refuses a leased row, and any row that is not `pending` or `dlq`. 0.6 reset any row. Only the administration port's explicit `force` overrides the status check.

### Added

- `wp ddd relay --once`: one relay tick (outbox batch, wakeup re-projection, stranded scan). There is no daemon; hosts schedule it.
- `wp ddd ops`: the operator view across the relay, delivery, wakeup and process layers, with the applicable repairs.
- `wp ddd drain --before-rollback`: drains work only 0.7 understands before you downgrade (see below).
- The ports and portable runtime under `TangibleDDD\Runtime\`, the in-memory doubles, the PDO default for plain PHP on MySQL 8 (`DurableRuntime::compose()` and `Drain::runOnce()` over your own `PDO`, with plain `CREATE TABLE IF NOT EXISTS` files in `packages/ddd-core/schema/mysql8/` that you apply yourself), and the Symfony host on Postgres 16. There is no bundled database daemon, migrator, DSN parser or connection factory.

### Supported combinations

- WordPress with `tangible/ddd` 0.7.0 alone, beside any 0.6.2 to 0.6.7 copy in either load order, beside the same 0.7.0 in another plugin, and with `tangible-ddd` activated as a plugin: supported. The newest copy wins and every `TangibleDDD\` class loads from it, including next to plugins that use the Jetpack Autoloader.
- MySQL 8 and Postgres 16 are claimed. MariaDB is not claimed and not tested.
- `ddd-core` and `ddd-wp` from different plugins or versions: unsupported. The winner always serves both from its own copy, and `replace` keeps them out of a single vendor tree.
- `TangibleDDD\Defaults\Pdo` under WordPress: unsupported. `ddd-wp` never uses it.
- 0.1.x, and any 0.2.x copy beside a 0.6+ consumer: unsupported (reported, never silent).
- `ddd-symfony` workers on a pooled Postgres endpoint: unsupported (boot warning). Use a direct endpoint for `ddd:relay` and `messenger:consume`.

### Migrating a WordPress plugin from 0.6.x

1. **Keep requiring `tangible/ddd`** and change the constraint, for example `"tangible/ddd": "^0.7"`. Do not require `tangible/ddd-core` or `tangible/ddd-wp` directly. The root package replaces both, and a WordPress plugin that bundles `ddd-core` without `tangible/ddd` is unsupported, because on WordPress core always comes from the winning distribution.
2. **Run PHP 8.2 or later** and let Composer install `symfony/yaml` and `psr/log`. They are now runtime dependencies, so a `--no-dev` build must include them. As before, roots that use `minimum-stability: stable` restate `"league/tactician": "^2.0-rc1"`.
3. **No code changes are expected.** Your `IDDDConfig`, handlers, listeners, repositories, processes and YAML service definitions load unchanged, and a container compiled against 0.6.5 keeps working.
   - Code that `require`s files under `vendor/tangible/ddd/ddd-src/` or `ddd-wordpress/` directly should stop. The sources now live in `packages/ddd-core/src`, `packages/ddd-wp/src` and `packages/ddd-wp/wordpress`.
   - `ddd-wordpress/self/index.php` is kept as a forwarding shim.
4. **Let the schema migrate.** Version 8 runs through the existing ledger on the next `install_tables()` and only adds tables and nullable columns. Pending Action Scheduler continuations and timeouts get their intent rows backfilled.
5. **Check every delayed fact.** Delays now apply once (see Fixed). If you tuned a delay to the doubled behaviour, halve your compensation. tangible-cred's `EndpointAuthRefresh` and `BehaviourWorkflowReschedule` are the known cases. Hooks on `{prefix}_outbox_publish_external` must not add `delay_seconds` themselves.
6. **Before rolling back to 0.6.x, run `wp ddd drain --before-rollback`.** 0.7 writes only what 0.6 can read on the shared tables (outbox rows stay `completed`, quarantined processes are `failed`), and 0.6 tolerates the newer schema version. Pending `{prefix}_ddd_redeliver` actions are 0.7-only, though, and are lost on a rollback unless you drain them first.
7. **Know which version your site reports.** While an older copy loads first, `Infra\Config::version()` and the dashboard can still show that copy's version (`TANGIBLE_DDD_VERSION` is first-defined-wins). `Tangible_DDD_Versions::instance()->winner()` is authoritative.
