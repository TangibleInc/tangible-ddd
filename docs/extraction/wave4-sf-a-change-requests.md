# Wave 4: sf-a change requests

Author: sf-a (wave 4, symfony part A). Branch `wave4/sf-a`. Owned paths: `packages/ddd-symfony/**` and this file. Binding inputs: [contract-register.md](contract-register.md) (3.8, 3.10, 5.1, section 8 wave 4), [wave3-notes.md](wave3-notes.md) (L4, L5, L6, L8, WP8-10), [wave3-sf-conformance3-change-requests.md](wave3-sf-conformance3-change-requests.md) (open items).

Every item is additive: new classes, an optional trailing constructor parameter, a widened parameter type, new services and console commands, a new schema file. No ratified core interface was changed and nothing outside `packages/ddd-symfony` was edited. `packages/ddd-symfony/composer.json` gains one **require-dev** entry (`doctrine/orm: ^3.3`) for the L6/L8 kernel tests; the runtime `require` is unchanged and the ORM stays optional.

## What this round did

| Item | Where | Test |
|---|---|---|
| L6 (correctness) | `Persistence\EntityManagerSession`, `DbalTransactionBoundary` `afterRollback` | `tests/Kernel/OrmTransactionRollbackTest` (ORM 3, DoctrineBundle, Postgres 16; failed first, see below), `tests/Integration/Persistence/DbalTransactionBoundaryTest`, `tests/Unit/Persistence/EntityManagerSessionTest` |
| L8 | `Persistence\PersistenceConflict`, `DbalTransactionBoundary` | `OrmTransactionRollbackTest::test_a_unique_violation_at_the_flush_is_a_persistence_conflict`, `DbalTransactionBoundaryTest` (3 cases) |
| L4 | `TangibleDddBundle` config, `Runtime\SymfonyConsumerConfig`, `Runtime\Factory::auditEnvironment`, README | `tests/Unit/Bundle/ConsumerVersionTest`, `tests/Kernel/ConsumerVersionEnvTest` |
| L5 | `schema/postgres/released.txt`, `Persistence\PostgresSchema`, `ddd:schema:dump --since`, README | `tests/Unit/Persistence/PostgresSchemaTest` (6 new cases), `tests/Integration/Persistence/PostgresSchemaApplyTest`, `BundleWiringTest` |
| D1 journal | `Persistence\DbalEffectJournal`, `schema/postgres/009_effect_journal.sql` | `tests/Integration/Persistence/DbalEffectJournalTest`, `BundleWiringTest` |
| D9 | `Ops\DbalLedgerOperatorSource`, `Ops\DbalWakeupOperatorSource`, `Ops\MessengerFailureTransportSource`, service `tangible_ddd.operator_view` (core `PortOperatorView`), `ddd:ops:list`, `ddd:ops:dlq:discard` | `tests/Kernel/OperatorViewTest`, `tests/Integration/Ops/DbalOperatorSourcesTest`, `tests/Unit/Ops/MessengerFailureTransportSourceTest`, `OpsCommandsTest` |
| WP8-10 (sf side) | `Ops\CoreStrandedRepairs`, `StrandedCommand` `repairs`, bundle registration of the core commands | `tests/Unit/Ops/CoreStrandedRepairsTest`, `ProcessWiringTest::test_ops_stranded_repairs_run_through_the_bundle_wiring` |

**L6 reproduction.** Before the fix, `OrmTransactionRollbackTest` failed 4 of 4 on Postgres 16: (1) a failed act's `persist()` was flushed by the next act (rows `['a', 'b']` instead of `['b']`); (2) a failed act's change to a managed entity was flushed by the next act (`'renamed'` committed); (3) after a unique violation at the flush, the next act failed with `Doctrine\ORM\Exception\EntityManagerClosed`; (4) the registry handed the application a closed manager. All four pass after the fix.

## CR sf-a-1 (L6): `DbalTransactionBoundary` after-rollback hook (additive)

- **What.** An optional trailing constructor parameter `?callable $afterRollback = null` (after `$logger`). It runs once after every rollback the run performs: the work or `$beforeCommit` threw, the aborted-transaction probe failed, or COMMIT failed. It never runs after a commit. If it throws, the failure is logged at `error` and the act's own exception is still the one thrown.
- **New class `EntityManagerSession`** (service `tangible_ddd.entity_manager_session`, created only when `transaction.entity_manager` is set). `flush()` flushes the registry's current manager. `reset()` calls `clear()` on an open manager. When the failed flush closed the manager, `reset()` calls `resetManager($name)` through the `doctrine` registry: DoctrineBundle managers are lazy, so handlers keep a working instance. The manager name comes from `getManagerNames()` by service id, or by instance for an alias. There is no ORM or doctrine/persistence type dependency: the manager and the registry are duck-typed. Without a registry, a closed manager is logged at `error` and cannot be replaced.
- **Behaviour change.** After a failed act, the configured EntityManager is cleared, so every entity is detached, including entities loaded before the act. In `nested: savepoint` mode the outer transaction's unflushed ORM changes are cleared too. The `entity_manager` service may still be any service with `flush()`. A service without `flush()` now fails at its first use with `InvalidArgumentException`; before, it was a call error.
- **Request (conformance owner).** wave3-notes asks for "a conformance case" for L6. It needs an ORM-like unit-of-work seam on the host fixture (for example an optional `UnitOfWorkHost` with `schedule(row)` / `flushedRows()`). sf-a did not add it because `ddd-conformance` is outside its paths. The sf kernel test is the proof for now.

## CR sf-a-2 (L8): `PersistenceConflict` (sf class; core request)

- **What.** A unique violation (`Doctrine\DBAL\Exception\UniqueConstraintViolationException` anywhere in the chain) raised by `$beforeCommit` (the ORM flush) is rethrown as `TangibleDDD\Symfony\Persistence\PersistenceConflict` after rollback. It has code 409, the DBAL exception as previous, and `public readonly ?string $constraint`, parsed from the Postgres message. A unique violation inside the work itself (a DBAL statement the handler issues) is rethrown unchanged, so the handler sees it at the statement.
- **Behaviour change.** Code that caught `UniqueConstraintViolationException` around `send()` with an EntityManager configured must catch `PersistenceConflict`, or read `getPrevious()`.
- **Request (core).** Core has no conflict exception today: L7 adds only `NotPermittedException`. Please add a library-wide 409-family base next to it, for example `TangibleDDD\Application\Exceptions\ConflictException extends \RuntimeException`. `PersistenceConflict` then re-parents to it, which is additive (it is final and extends `\RuntimeException` now). Until then, TXP maps `PersistenceConflict` to 409.

## CR sf-a-3 (L4): consumer version normalisation (additive)

- `SymfonyConsumerConfig::__construct()` `$version` is now `?string` (widened). `null` or `''` becomes `SymfonyConsumerConfig::DEFAULT_VERSION` (`'0.0.0'`). Also new: `SymfonyConsumerConfig::normaliseVersion()`.
- The bundle config `consumer.version` normalises `null` / `''` at processing time. An env placeholder that resolves to null at runtime (`'%env(default::APP_VERSION)%'` with the variable unset) is normalised by the constructor. The audit environment service is now built by `Factory::auditEnvironment()`, so its `app` key is `'0.0.0'` too. Before, a null version was a `TypeError` at the first command.
- **Request (examples owner).** `examples/symfony/README.md` line 69 shows `version: '%env(default::APP_VERSION)%'`. Add "unset = '0.0.0'" to its comment. At line 76 the `entity_manager` comment should read "flushed before COMMIT; cleared, or reset when closed, after a rollback". The package README is already updated.

## CR sf-a-4 (L5, contract): append-only schema evolution

- **Rule (register edit requested, X9 / section 3 schema notes).** A shipped sf schema file never changes. `schema/postgres/released.txt` lists every file with `PostgresSchema::digest()`: the sha256 of the file's statements, ignoring comments and whitespace. `PostgresSchemaTest` fails when any of these holds:
  - a listed file's statements changed;
  - a file is missing from the list or out of order;
  - files are not numbered contiguously (`NNN_name.sql`);
  - a statement is not idempotent. The allowed forms are `CREATE [UNIQUE] TABLE|INDEX IF NOT EXISTS` and `ALTER TABLE ... ADD COLUMN IF NOT EXISTS | DROP CONSTRAINT IF EXISTS | ALTER COLUMN`; `DROP TABLE` / `DROP INDEX` are refused.

  A schema change is the next numbered file plus its appended line.
- **New API (additive).** `PostgresSchema::released()`, `PostgresSchema::digest(string $path)`, and `render(string $prefix = '', ?int $since = null)` (new optional parameter). `ddd:schema:dump --since=NNN` prints only the files after NNN, which is the host's next migration.
- **Behaviour change.** Dump output now heads each file with `-- tangible/ddd-symfony schema/postgres/<file>`. It is a comment line, so `statements()` and hosts that run the SQL are unaffected.
- **For the other wave-4 sf work (`process_waits` any-of, any column addition).** It must ship as `010_...sql` and later (`ALTER TABLE ... ADD COLUMN IF NOT EXISTS`) with a `released.txt` line. It must not edit `006_process_waits.sql`, or the gate fails after merge. If two branches both add `010_*`, whoever merges second renumbers.
- `009_effect_journal.sql` is in `released.txt` from this round, because TXP process-kernel builds on it right after round 2. If review changes it before the merge, regenerate its line. After the merge it is frozen.

## CR sf-a-5 (D1): `DbalEffectJournal` (additive)

- `IEffectJournal` on `{prefix}ddd_effect_journal` (009), on the domain connection. Writes join the caller's transaction when one is open, so `invalidate()` commits or rolls back with the repair command, and are autocommit otherwise.
- Operations:
  - `find()` returns the result while the row is not invalidated.
  - `store()` upserts, which overwrites the result and clears an invalidation.
  - `invalidate()` marks the row (`invalidated_at`, `invalidation_reason`, `invalidations + 1`); an unknown or already invalidated key is a no-op.
- `result_json` is TEXT, not JSONB, so `EffectResult::$data` round-trips exactly (JSONB reorders keys).
- Storage and decode failures are `\RuntimeException` with the DBAL exception as previous. The journal has no failure-command trigger of its own (register 5.1: the core invoker fires it).
- Service `tangible_ddd.effect_journal`, alias `IEffectJournal`. Core's `EffectMiddleware` (wave 4, core) is not wired by this branch, because it is not on the integration branch yet. **Request (whoever wires `EffectMiddleware` on sf, after core-effects merges):** put it between `tangible_ddd.middleware.act_bracket` and `tangible_ddd.middleware.transaction` with this journal. The sf `effect.journal-reuse` conformance cell needs the same wiring in `SfHostFixture`.

## CR sf-a-6 (D9): merged operator view and `ddd:ops:list` (additive)

- Service `tangible_ddd.operator_view` (alias `IOperatorView`) is core `PortOperatorView` with these sources:
  - `IOutboxAdministration` for `relay`;
  - `IProcessStore::findStranded` for `process` (`running` rows only, with repairs `resume_stranded`, `fail_stranded`);
  - `DbalLedgerOperatorSource` for `delivery`: undelivered pairs that failed or are exhausted, with the handler budget and key `subscriber@event_id`; no repair label, because Messenger retries a failing pair and an exhausted one has had its compensation;
  - `DbalWakeupOperatorSource` for `wakeup`: the consumer's intents that failed, budget 10, `rearm` when exhausted;
  - `MessengerFailureTransportSource` for `transport`. Its key is the transport message id and attempts are the Messenger retries + 1. It has no budget, because the ledger counts it. Repairs are `messenger:failed:retry|remove <transport>`. Other consumers' messages are left out, and a non-listable receiver or no failure transport contributes nothing.
- `ddd:ops:list [--layer=] [--limit=] [--format=table|json]`; JSON rows are `OperatorItem::toArray()`. `ddd:ops:dlq:discard <dlq-id>...` carries out the `discard` label that `PortOperatorView` already puts on relay items (`IOutboxAdministration::discard`).
- **Open (not a change request).** The sf `findStranded()` does not probe the process lock. wp checks `IS_FREE_LOCK` (WP8-10 "Also"), but sf does not. A wake that holds the advisory lock longer than `stranded_after_seconds` with no live intent therefore shows as stranded. `--fail` is still refused while the lock is held. A `pg_try_advisory_lock` probe in `findStranded()` would close it; it belongs with the core-scanner switch (wave3-sf-conformance3 open item 2).

## CR sf-a-7 (WP8-10, sf side): `ddd:ops:stranded --resume|--fail` dispatch core's repair commands

- `Ops\CoreStrandedRepairs` builds `TangibleDDD\Application\Process\ResumeStrandedProcess` / `FailStrandedProcess` from their constructors and dispatches them on `tangible_ddd.command_bus`. The first parameter gets the process id, and a parameter named `reason` gets `--reason`.
- `StrandedCommand` takes an optional trailing `?CoreStrandedRepairs $repairs = null`. The guard is a runtime `class_exists`: while core does not ship the classes, `--resume` / `--fail` keep the inline repair (Continue intent; locked, version-fenced fail). A kernel test covers that path.
- The bundle makes the core commands dispatchable once they exist. A `SelfHandlingCommand` joins the `handle()` locator's classes. A plain command whose namespace fits the naming convention gets its handler registered (autowired, tagged).
- **Request (core-process).** Use these FQCNs and the constructor shape `(int $processId, ..., string $reason)`, or tell the merge agent the real ones; `CoreStrandedRepairs::RESUME` / `FAIL` are the only place to change. A self-handling command or a `Commands` / `CommandHandlers` namespace pair keeps the bus mapping working. After the merge, `ProcessWiringTest::test_ops_stranded_repairs_run_through_the_bundle_wiring` exercises the core path and may need its comment updated.
