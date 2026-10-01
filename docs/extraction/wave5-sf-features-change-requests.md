# Wave 5 sf-features: change requests

Author: sf-features (wave 5), branch `wave5/sf-features`. Every item below is
additive. No ratified interface changed, and the 0.6 frozen API (R1-R5) is
untouched. The single-consumer `consumer:` configuration builds the same
service ids and wiring as before. The 427 tests that existed before this
round still pass (two of them asserted the schema file list or the
`--since` output and were updated for schema 010), together with this round's 42 new
tests (469 in all).

## Additive changes made in ddd-symfony (for ratification)

| Id | Change | Why |
|---|---|---|
| CR-W5SF-1 | `IntegrationFactMessage`: optional 7th constructor argument `?string $to` (private) and `recipient(): string` (the raiser when absent, so messages serialized before wave 5 still route). `IntegrationFactHandler` checks `recipient()` instead of `consumer`. | Cross-consumer delivery: a copy of a fact addressed to the consumer whose subscribers it is for. |
| CR-W5SF-2 | `MessengerFactTransport`: optional 7th argument `list<FactAudience> $audiences`. For each audience whose compiled map subscribes to the fact's class, a copy goes to that consumer's transport before the raiser's own message. New `FactAudience` value. New `ConsumerRouter` (one Messenger handler per message class, dispatching by consumer). It is only wired when several consumers are configured. | Multi-consumer, item 6. |
| CR-W5SF-3 | Schema `010_delivery_notes.sql` (append-only, listed in `released.txt`): `ddd_outbox.unheard_at`, and on `ddd_delivery_ledger` the columns `unheard_at`, `failure_command` and `failure_command_at`. | AW3, E3. |
| CR-W5SF-4 | `TableNames` (sf, `ITableNames`) replaces core `PrefixedTableNames` in every sf store and accepts `[schema.]prefix`. `PostgresSchema::render()` qualifies table references but not index or constraint names, starts with `CREATE SCHEMA IF NOT EXISTS`, and takes `?int $until`. `statements()` and `apply()` take `$since` and `$until`. There is a new `head()`. A prefix with a dot used to be refused; nothing else changes. | A consumer can live in its own Postgres schema. |
| CR-W5SF-5 | New sf methods, not on any port: `DbalPostgresOutboxStore::class_of_action()` / `note_unheard()`, `OutboxFactClassResolver::class_of_action()`, `DbalDeliveryLedger::note_unheard()` / `note_failure_command()`, `DbalBehaviourWorkflowRepository::find()` (null for an unknown id), `CompiledSubscriptionRegistry::has_subscribers()`, `LazyProcessEntry::resume_with_outcome()`. Optional trailing constructor arguments: `CompiledSubscriptionRegistry` (`?DeliveryNotes`), `Relay` (`?ISubscriberProbe`), `HostDefaultsInstaller` (`array $behaviour_types`), `RelayCommand` (`array $lanes`), `SchemaDumpCommand` (`array $consumers`). `PostgresListenWaiter` takes `string\|list<string>` prefixes. | AW3, E3, W1, W2, multi-consumer. |
| CR-W5SF-6 | Workflow continuations (W1) are `ddd_wakeups` intents of kind `continue` with **no process id** and the key `workflow:{handler class}:{workflow id}:{idx}:{phase}#{n}`. `WorkflowWakeTarget` routes them before the process runner and runs them under `LockKey("{prefix}/workflow", '', id)`. They use the core `WakeKind::Continue` because the kind is a frozen core enum and a schema CHECK. Only sf schedules them, so mem, pdo and wp drains never see one. | W1 without a core change. |
| CR-W5SF-7 | Bundle config: `consumer` is no longer `isRequired()`. Exactly one of `consumer` and the new `consumers` map is required, which is validated. New keys: `workflow.stale_start_seconds`, `workflow.stale_claim_seconds`, `workflow.behaviour_types`, `messenger.redeliver_timeout_seconds` (set as `options.redeliver_timeout` on a `doctrine://` facts transport). New parameters: `tangible_ddd.consumers`, `tangible_ddd.behaviour_types`. Non-primary consumer services are `tangible_ddd.consumer.{name}.*`. | W2, W3, multi-consumer. |
| CR-W5SF-8 | New autoconfiguration tags: `tangible_ddd.continues_workflow` (`IContinuesWorkflows`) and `tangible_ddd.behaviour_config` (`BaseBehaviourConfig` subclasses, removed from the container after `BehaviourTypePass` reads their type). New passes: `BehaviourTypePass` and `ConsumerAssignmentPass` (before removal; it does nothing with one consumer). | W1, W2, multi-consumer. |
| CR-W5SF-9 | `ddd:relay --consumer=`, `ddd:ops:list --consumer=` (a consumer column with several consumers), `ddd:schema:dump --consumer=`. An unknown consumer exits 1 (`ddd:ops:list`: 2, INVALID, like an unknown layer). | Multi-consumer. |

## Requests to other owners

### core (wave5/core-correctness had not merged into `extraction/ddd-packages` at the time of writing; task item 7)

- **CR-W5SF-R1, L10.** Once `Domain\Exceptions\ConflictException` is on the
  integration branch, `TangibleDDD\Symfony\Persistence\PersistenceConflict`
  re-parents to it (today: `final class ... extends \RuntimeException`). This is
  a one-line change in sf, but it is a behaviour change for callers:
  `ConflictException` is a `BusinessConstraintException` (`\Exception`), so a
  `catch (\RuntimeException)` around a save stops catching it. sf has no such
  catch site. TXP should check its own. Not done here because the class does
  not exist yet on the base branch.
- **CR-W5SF-R2, W2 registry.** Once `IBehaviourTypes` / `BehaviourTypes` and
  `BaseBehaviourConfig::hand_over_types()` land, `HostDefaultsInstaller`
  builds one `BehaviourTypes` from `tangible_ddd.behaviour_types`, provides it
  through `HostDefaults::provide(IBehaviourTypes::class, ...)`, and calls
  `hand_over_types()`. The compile-time map and the autoconfiguration are
  already in place (`BehaviourTypePass`). Today they are registered through
  the existing static `register_type()` facade, which the new core keeps.
- **CR-W5SF-R3, AW3 follow-up.** `ResumeReport` cannot tell a
  precheck-absorbed answer from a misrouted one. The core open item
  "absorbed-route record" would let sf raise a `DddSignal` for unheard keyed
  answers that were not absorbed. Until then sf only logs and notes them, as
  the TXP rollup asks. Also, `IProcessEntry::resume()` returns void. sf reaches
  the report through `LazyProcessEntry` → `ProcessRunner::resume_with_outcome()`.
  An additive `IReportsResume` port (`resume_with_outcome(): ResumeReport`)
  would let any `process_entry` override report too.
- **CR-W5SF-R4, follow-ups of core-correctness for sf** (recorded in
  wave5-core-correctness-change-requests.md, not done in this round): E1
  (effect middleware with the handler locator and mapping;
  `IExternalEffectHandler` autoconfiguration), E2 (`recorded_at` on
  `ddd_effect_journal` as schema 011, `ITracksEffectState`, the `effect`
  operator layer), and AW2 (a `fact` column on `ddd_wakeups`,
  `DbalWakeupScheduler implements ICarriesFacts`).
- **CR-W5SF-R5, optional.** A core `WakeKind::Workflow` (and the CHECK value)
  would replace CR-W5SF-6's key-prefix routing. It is not needed for
  correctness.

### conformance

- **CR-W5SF-R6.** The multi-consumer guarantees are host tests
  (`tests/Kernel/MultiConsumerTest`). If wp and pdo should claim the same
  thing for several consumers, a shared scenario could be added:
  `delivery.cross-consumer-once`, a fact of consumer A delivered once to
  consumer B's subscriber under redelivery, and a poison copy in A that does
  not hold B. There is no host fixture for two consumers yet.

### packaging

- None. `packages/ddd-symfony/composer.json` is unchanged:
  `symfony/service-contracts` (for `#[Required]`) comes with
  `symfony/framework-bundle`.

## Known limits (not requests)

- `ddd:ops:dlq:*`, `ddd:ops:pause`, `ddd:ops:resume` and `ddd:ops:stranded`
  act on the primary consumer only. They do not take `--consumer` yet.
- Messenger tables are shared (`messenger_messages`, by queue name), and the
  LISTEN waiter is on the primary consumer's connection. A consumer on
  another *database* gets the poll fallback.
- The EntityManager (`transaction.entity_manager`) is bound only to the
  boundaries of consumers on the primary consumer's connection.
- A fact copy for a consumer on another connection is not inside the relay
  transaction. A failure after it was sent retries the fact, and the
  receiving ledger absorbs the duplicate.
