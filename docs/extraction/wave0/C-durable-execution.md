# Wave 0 / C: durable execution (outbox, relay, processes, workflows, locking, scheduling, repair)

- Role: C-durable-execution (read-only investigation)
- Source: `/Users/titustc/tgbl/repos/tangible-ddd`, branch `extraction/ddd-packages`, HEAD `598858c` (0.6.6). Working tree clean.
- Baseline re-run for this report (2026-10-01): `vendor/bin/phpunit` gives 628 tests, 2166 assertions, OK (9 PHPUnit deprecations). The integration suite was not run (it needs the ddev WP database, see `tests/Integration/bootstrap.php:24-37`).
- Every `path:line` below is relative to the source root and was read in code, not taken from docs.
- Operator decisions treated as fixed: no bundled MySQL runtime, worker daemon or migration framework in core. The host supplies the connection (a PDO it already has). Core ships ports, in-memory doubles, at most a thin PDO adapter plus schema SQL, and a `runOnce()` entry point the host schedules. MySQL 8 is tested; MariaDB is not claimed. Postgres is tested through ddd-symfony. The three bugs are fixed on the extraction branch, and a 0.6.x hotfix branch is prepared but not released. Tactician stays.

Owner vocabulary: **core** (framework-agnostic ports and policy), **wp** (ddd-wp adapter), **symfony** (ddd-symfony, DBAL + Messenger), **pdo-default** (thin PDO adapter in or beside core, using the host's connection), **packaging** (distribution, loader, schema delivery), **legacy** (0.6.x compatibility surface and hotfix).

---

## 1. What exists today (component map)

| Component | Location | Runtime coupling |
|---|---|---|
| Transaction boundary | `ddd-src/Application/Persistence/TransactionMiddleware.php:28-54` | `wpdb` raw `START TRANSACTION/COMMIT/ROLLBACK`, results unchecked |
| Outbox write (bus) | `ddd-src/Infra/Services/OutboxIntegrationEventBus.php:29-78` → `OutboxRepository::write` `ddd-src/Infra/Persistence/OutboxRepository.php:22-63` | `global $wpdb`, `wp_json_encode`, `is_multisite` |
| Relay | `ddd-src/Infra/Services/OutboxProcessor.php:45-125` | `has_action`, `error_log`, `wp_json_encode` |
| Relay scheduling | `ddd-wordpress/hooks.php:187-235` (AS recurring action every `processor_interval_seconds`, default 30) | Action Scheduler |
| Transport publisher | `ddd-src/Infra/Services/ActionSchedulerOutboxPublisher.php:18-35`, `RoutingOutboxPublisher.php:31-78` | AS, WP filters |
| Delivery to listeners | `ddd-wordpress/integration-events.php:77-104` (listeners, priority 10), `ProcessRunner::register_start` priority 50 (`ProcessRunner.php:148-180`), `register_event` priority 99 (`ProcessRunner.php:77-96`) | one AS action per fact, one `do_action` for all subscribers |
| Process runner | `ddd-src/Application/Process/ProcessRunner.php` (767 lines) | `add_action`, `as_*`, `global $wpdb` (GET_LOCK) |
| Process persistence | `ddd-src/Infra/Persistence/ProcessRepository.php:27-119`, schema `ddd-wordpress/tables.php:105-140` | `wpdb` |
| Continuation / timeout hooks | `ddd-wordpress/hooks.php:147-182` | AS |
| Behaviour workflows | `ddd-src/Application/BehaviourWorkflows/WorkflowHandler.php` (runs as a command handler; `reschedule()` is abstract, consumer-owned, line 107) | consumer-chosen scheduler |
| Work-item ledger | `ddd-src/Infra/Persistence/WorkItemRepository.php:76-127`, schema `tables.php:301-328` | `wpdb` |
| Repair commands | `ddd-src/Application/CommandHandlers/{RetryDelivery,ReplayDeadLetter,DiscardDeadLetter,PurgeOutbox}Handler.php` | `global $wpdb`, not `ITransactionalCommand` |
| Generic WP lock helpers | `ddd-wordpress/locking.php:36-154` (options/object-cache TTL lock, not used by the runner) | WP options |
| CLI | `ddd-wordpress/cli/class-ddd-command.php` has only `init` (scaffolder) and `announce` (`:80-128`). There is no relay, repair or stuck-process CLI | WP-CLI |

---

## 2. Transaction and crash boundaries (current code)

"Tx" means a DB transaction on the single `$wpdb` connection. Every repository write outside `TransactionMiddleware` autocommits.

### 2.1 Command → domain rows → outbox

| Step | What commits | Crash after this step leaves | Evidence |
|---|---|---|---|
| `START TRANSACTION` (only for `ITransactionalCommand`) | nothing | nothing | `TransactionMiddleware.php:30-39` |
| handler writes aggregates; `DomainEventsPublishMiddleware` drains domain events → reactions → `OutboxIntegrationEventBus::publish` → `OutboxRepository::write` INSERT | nothing yet (inside tx) | rollback by server on disconnect; clean | `DomainEventsPublishMiddleware.php:14-34`, `OutboxRepository.php:60` |
| `COMMIT` | domain rows + outbox row atomically | pending outbox row; fine | `TransactionMiddleware.php:43` |
| audit finalisation (CorrelationMiddleware, outside the tx) | audit row | committed business result without audit row | `di/tactician.yaml:29-36` |

Defects in this boundary:
- `wpdb::query()` returns `false` on error and does not throw, so a failed `COMMIT` or `START TRANSACTION` is reported as success (`TransactionMiddleware.php:39,43`).
- `OutboxRepository::write` ignores the `wpdb->insert` result (`OutboxRepository.php:60-62`). A failed outbox insert inside a committed tx loses the fact silently while the domain change commits. InnoDB rolls back only the failed statement, not the tx.
- Non-transactional commands still write the outbox (`DomainEventsPublishMiddleware` is unconditional), so domain rows and outbox rows autocommit separately. A crash between them gives a domain change with no fact, or the reverse.
- `if (!$this->wpdb) return $next($command)` (`TransactionMiddleware.php:34-36`) runs a transactional command without a transaction instead of failing.
- `START TRANSACTION` inside an already-open MySQL transaction implicitly commits the outer one. Nothing detects a caller-owned transaction (spec section 1 already asks for join-or-reject).

### 2.2 Relay: outbox → transport

| Step | What commits | Crash after this step leaves | Evidence |
|---|---|---|---|
| `release_stale_locks` UPDATE | autocommit | nothing harmful | `OutboxProcessor.php:47`, `OutboxRepository.php:273-284` |
| claim: `START TRANSACTION`; `SELECT … FOR UPDATE SKIP LOCKED`; UPDATE `locked_until=now+300, locked_by`; `COMMIT` | lease on N rows | rows are leased for 300 s, then re-claimable | `OutboxRepository.php:94-128` (lease hard-coded at `:77`, ignoring `OutboxConfig::lock_timeout_seconds`) |
| per row: `publisher->publish()` (AS INSERT into `actionscheduler_actions`, autocommit) | AS action | **AS action exists, outbox row still pending.** It is republished after lease expiry, giving a duplicate delivery with the same `event_id` | `OutboxProcessor.php:72`, `ActionSchedulerOutboxPublisher.php:21-34` |
| `mark_completed` UPDATE `WHERE event_id=?` | row completed | fine | `OutboxRepository.php:144-157` |
| on throw: `mark_failed` (read, then UPDATE attempts+1, backoff) or `move_to_dlq` (INSERT DLQ, then UPDATE status) | two autocommits | DLQ row inserted while the outbox row is still pending. It is retried later and can produce a second DLQ row, or it completes while the DLQ row stays | `OutboxRepository.php:159-196`, `:231-271` |

"Completed" means **enqueued in AS**, not handled (`OutboxProcessor.php:72-73`).

### 2.3 Delivery: AS action → listeners, ignition, resume

A single AS action per fact runs `do_action($integration_action, [$wrapped])`, and every subscriber of that fact on that site runs inside it, in priority order 10 (listeners), 50 (ignition), 99 (resume). Each listener's command opens its own tx.

| Crash or throw point | Result | Evidence |
|---|---|---|
| a priority-10 listener throws | later callbacks (other listeners, ignition, resume) **never run**. AS marks the action `failed` and does not retry it. The fact is "completed" in the outbox | `integration-events.php:88-104` (no catch), `integration-events.php:53-63` (rethrow); AS `ActionScheduler_Abstract_QueueRunner.php:105-113` (`handle_action_error`, no retry) |
| PHP dies mid-action | AS `mark_failures` flags the action failed after `action_scheduler_failure_period` (300 s). No retry | `vendor/woocommerce/action-scheduler/classes/ActionScheduler_QueueCleaner.php:206-207` |
| listener command committed, then a later callback fails | partial fan-out: some subscribers applied, others lost | same |

### 2.4 Process lifecycle

| Path | Order of effects | Crash window | Evidence |
|---|---|---|---|
| Ignition | `has_ignition` SELECT → `mark_ignited_by` → `start()` → INSERT process (autocommit) → lock → run first step inline | two concurrent deliveries of one event both pass the SELECT (bug 2) | `ProcessRunner.php:166-175`, `ProcessRepository.php:90-100` |
| `start()` | `save()` INSERT **before** the lock, then `with_process` (lock + scope) → `run()` | crash after INSERT: row `status=running` and no job exists to resume it. **Stranded** | `ProcessRunner.php:229-233`, `LongProcess.php:267-273` |
| forward step | invoke step → `dispatch_commands` (each command its own tx) → `record_checkpoint`/`advance_step` → `save` | crash after commands commit, before save: the step re-runs on any re-entry and its commands dispatch again. At-least-once step effects, and currently nothing re-enters (row stays `running`) | `ProcessRunner.php:465-488`, `:619-628` |
| suspend | commands dispatched **before** the suspended state is saved; then `save(suspended)`; then `as_schedule_single_action(timeout)` | (a) crash between save and AS insert: suspended with **no timeout alarm**. (b) if the awaited fact could arrive before the save (synchronous or post-commit wakeup, D14), the resume finds no waiting row: a missed wake | `ProcessRunner.php:473-478`, `:633-656` |
| `#[Async]` / resource reschedule | `save(status=scheduled)` → `as_enqueue_async_action(process_continue)` | crash between: `scheduled` with no action. **Stranded forever** | `ProcessRunner.php:661-670` |
| continuation | `find` + status guard **outside** the lock → lock → save running → run | a duplicate continuation re-runs from a stale object | `ProcessRunner.php:266-285` |
| resume | `find_waiting_for` **outside** the lock; the mechanism taken from the stale object; no re-read under the lock | lost AwaitAll tallies; a late event can resurrect a timed-out or compensated process (section 4) | `ProcessRunner.php:290-326` |
| timeout | lock → re-read → status and step-index guard → proceed or compensate | correct re-read pattern, the only one in the runner | `ProcessRunner.php:332-388` |
| compensation | each undo step: invoke → dispatch → `advance_compensation` → save | same at-least-once re-run window as forward steps | `ProcessRunner.php:516-586` |
| failure | `run()` catches → `fail` → save → `ProcessFailed` infra event → rethrow → AS action failed | visible only as a `failed` row and an AS failure | `ProcessRunner.php:433-441` |

### 2.5 Behaviour workflows

`WorkflowHandler::handle` runs **inside a command** (it is an `ICommandHandler`). If the driving command is `ITransactionalCommand`, the whole chunk, up to `max_execution_seconds` = 25 s (`RescheduleAware.php:10`), runs inside one DB transaction together with any external effects `execute_one` performs. A crash rolls back the ledger but not the effects, so items are re-executed. If the command is not transactional, each `item_repo->save` autocommits after `execute_one` (`WorkflowHandler.php:276-280`), and the window is one item. There is no per-workflow lock: two concurrent runs of the same workflow (a reschedule plus a manual trigger) both execute pending items. `persist_meta` deletes then re-inserts every meta row (`BehaviourWorkflowRepository.php:145-160`), which is non-atomic outside a tx. The ledger's check-then-insert is backstopped by `UNIQUE uniq_item` (`tables.php:322`), but the insert result is unchecked (`WorkItemRepository.php:116-119`).

### 2.6 Repair commands

None is `ITransactionalCommand` (`Commands/*Command.php extend Command`, `Command.php:20` implements only `ICommand`).
- `ReplayDeadLetterHandler`: INSERT a new outbox row, then DELETE the DLQ row, as two autocommits (`ReplayDeadLetterHandler.php:32-58`). A crash between them duplicates on the next replay. It mints a **new** `event_id` with `wp_generate_uuid4()` (`:33`), and hard-codes `max_attempts=5`, `sequence=0`, `queue=null` (`:38-49`).
- `RetryDeliveryHandler`: resets any row by id to pending with no status guard (`RetryDeliveryHandler.php:21-32`). It also works on `completed` rows (redelivery) and on rows currently leased by a worker (it clears `locked_by`, which gives a concurrent double publish). On a `dlq` row it leaves the DLQ row in place.
- `get_stats` counts `WHERE resolved_at IS NULL` on the DLQ table (`OutboxRepository.php:316-318`), but the DLQ schema has no `resolved_at` column (`tables.php:73-90`, no migration adds it). The query errors, `get_var` returns null, and `dlq_unresolved` always reads 0.

---

## 3. Retry and DLQ ownership (current vs. required)

| Layer | Current owner | Budget | Terminal store | Operator surface | Gap |
|---|---|---|---|---|---|
| Outbox → transport submission | `OutboxProcessor` | `max_attempts` per row (default 5), backoff 60 s × 2^n capped at 3600 s (`OutboxConfig.php:13-18`, `OutboxRepository.php:168-173`) | `{prefix}_integration_dlq` | WP dashboard + Retry/Replay/Discard commands | non-atomic DLQ move; replay changes identity; no fencing |
| Listener / ignition / resume execution (WP) | nobody: an AS action fails once, terminally | 1 | AS `failed` actions (AS admin only) | none in DDD dashboard or CLI | a whole-fact fan-out is lost on one listener's failure |
| Process step failure | runner → compensation (no step retry) | 0 retries | `long_processes.status=failed` | dashboard ProcessQuery | stranded `running`/`scheduled` rows are indistinguishable from live ones |
| Process lock contention | throws "delivery will be retried" (`ProcessRunner.php:392,400`) | 0 (AS does not retry) | AS failed action | none | the comment is false; the wake is lost until timeout |
| Workflow item | `WorkflowHandler::$max_retries=3` + consumer `reschedule` | 3 per result | `is_failed` + fork | dashboard WorkflowQuery | depends on consumer scheduler |
| Symfony (future) | Messenger retry strategy + failure transport | per transport | failure transport | must be built | review finding 7: one budget, one DLQ view (D9) |

Required split for the port set: the relay owns **submission** retries. The **delivery runner** (AS in wp, the jobs table in pdo-default, Messenger in symfony) owns handler retries **per subscriber**, not per fact. Both must feed one operator view (D9): relay DLQ, delivery failures, stuck processes.

---

## 4. Race scenarios (verified against code)

| # | Scenario | Interleaving | Outcome today | Evidence |
|---|---|---|---|---|
| R1 | Two relays, one stalls past the lease | A claims, then takes more than 300 s (external publisher); B re-claims and publishes; A finishes | double publish; A's `mark_completed`/`mark_failed` writes with no ownership check; `mark_failed` can reset B's completion to pending with a stale attempt count | `OutboxRepository.php:77,144-196` (WHERE `event_id` only) |
| R2 | Crash after AS enqueue, before `mark_completed` | — | same `event_id` delivered twice; the listener must be idempotent | `OutboxProcessor.php:72-73` |
| R3 | AwaitAll, two awaited facts delivered concurrently | both workers read tally T0 outside the lock; W1 saves T0+A; W2 (stale object) saves T0+B | tally A lost; the process waits until timeout, then fails or proceeds wrongly | `ProcessRunner.php:292-316` |
| R4 | Timeout fires, then a late event | W1 read "suspended" before the lock; timeout runs under the lock and compensates to `failed`; W1 acquires the lock and advances the stale object | a **failed or compensated process is resurrected** and forward steps run after compensation | `ProcessRunner.php:292-318` vs `:345-386` |
| R5 | Duplicate continuation | two `process_continue` actions for one process (retry, manual run, `#[Async]` plus a resource reschedule) | both pass the status check outside the lock and run the same step twice | `ProcessRunner.php:266-284` |
| R6 | Duplicate ignition | two AS runners deliver the same fact (R2 duplicate), or a replay | both `has_ignition` → false, and two sagas start | `ProcessRunner.php:168-175` (bug 2) |
| R7 | GET_LOCK error | `GET_LOCK` returns NULL (error, killed thread, some proxies) → `(string)null === ''` | enters the section **unlocked**; R3/R4 become unserialized | `ProcessRunner.php:397-401` (bug 1) |
| R8 | Cross-consumer / cross-site lock collision | lock name is `ddd_process_<id>`; GET_LOCK names are **server-global** | cred process 7 and datastream process 7 (or subsite 2's process 7, or another install on a shared MySQL server) serialize on one lock; 5 s timeout, then a lost wake | `ProcessRunner.php:396` |
| R9 | Concurrent pause holders | two operators `set_pause` at once | read-modify-write on one option; one hold lost | `OutboxRepository.php:341-354` |
| R10 | `is_unique` cancel vs a leased row | publish of a unique event cancels "pending" rows, including one currently leased and being published | row set `cancelled`, then the relay overwrites it to `completed`; the cancel is ineffective. The payload signature is ignored, so every pending unique row of the type is cancelled | `OutboxRepository.php:286-300` |
| R11 | Awaited fact arrives before the suspend is saved | step dispatches a command whose fact is relayed before `save(suspended)` | missed wake. Latent today (relay is ≥ one AS tick later); becomes real with D14 post-commit wakeup or a sync transport | `ProcessRunner.php:473-478` |
| R12 | Insert failure in `ProcessRepository::save` | duplicate key (after an ignition unique index is added) or other DB error | `insert_id` 0 → `set_id(0)`; steps keep running and every later UPDATE `WHERE id=0` is a no-op | `ProcessRepository.php:58-63` |

---

## 5. The three bugs: precise location and fix sketch

### Bug 1: GET_LOCK NULL treated as acquired

- **Location:** `ddd-src/Application/Process/ProcessRunner.php:394-408`, the check at `:399`: `if ((string) $acquired === '0')`. `GET_LOCK` returns `1` (acquired), `0` (timeout) or `NULL` (error). `wpdb::get_var` also returns `null` on a query error. `(string) null` is `''`, so an error enters the critical section unlocked.
- **Masking in tests:** `tests/wp-stubs.php:23` `get_var()` returns `null`, and `tests/Unit/Process/AwaitTimeoutTest.php:23-25` documents "returns null (treated as acquired)". About 13 test files construct `ProcessRunner` against that stub (`grep -l "new ProcessRunner" tests`). A correct check fails them all unless the stub changes.
- **Hotfix (0.6.x, minimal):**
  ```php
  $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $name));
  if ($acquired === null || (string) $acquired !== '1') {
    throw new LockingException("Process lock $name not acquired (" . ($acquired === null ? 'error: ' . $wpdb->last_error : 'timeout') . ')');
  }
  ```
  Also: `tests/wp-stubs.php` `get_var` returns `'1'` for queries containing `GET_LOCK`/`RELEASE_LOCK`, and new unit cases cover `null` and `'0'`. Correct the false comment "delivery will be retried" (`:392,400`); a failed lock today loses the wake (section 3). Do **not** rename the lock in the hotfix: old and new code running side by side during a deploy would take different names and lose mutual exclusion.
- **Extraction fix:** replace `global $wpdb` with an `IProcessLock` port (section 7.4). Key = (consumer prefix, blog/tenant, process id). MySQL name `ddd:` + sha1 truncated to 64 chars (the GET_LOCK name limit). Explicit handle, explicit re-entrancy (the nested acquisition at `:345` + `:364` is balanced today only because MySQL 5.7+ GET_LOCK is re-entrant per connection). On contention, the wake must be **re-queued** (delivery-runner retry), not thrown into the void.
- **Test that proves it:** unit: a spy `wpdb` returning `null` and then `'0'` → no step runs, no `save`, the exception propagates. Integration (MySQL 8): connection 2 holds `GET_LOCK('ddd_process_N')`; the runner on connection 1 throws after 5 s and the row is unchanged.

### Bug 2: ignition check-then-insert

- **Location:** `ProcessRunner.php:166-175` (`has_ignition` then `start()`), `ProcessRepository.php:90-100` (plain SELECT), `ProcessRepository.php:58-63` (INSERT, result unchecked), schema `ddd-wordpress/tables.php:131` `KEY idx_ignition (ignited_by_event_id)`, which is **not unique** and does not include `process_class`.
- **Hotfix (0.6.x, schema-free):** after bug 1 is fixed, serialize on a named lock `ddd_ign_` + md5(prefix|process_class|event_id). Inside it: re-check `has_ignition` and INSERT (save). Release it, then run under the process lock. This needs a small split of `start()` into persist and run; `start()` already does save-then-`with_process` (`:231-233`). No schema change, so it is safe to ship before a migration.
- **Extraction fix:** the unique constraint is the gate. `UNIQUE (process_class, ignited_by_event_id)`: NULLs are distinct in both MySQL 8 and Postgres, so manual starts are unaffected. It is 255+64 chars utf8mb4 = 1276 bytes, under InnoDB's 3072-byte DYNAMIC limit. `IProcessStore::insertIgnited()` returns `Inserted|AlreadyIgnited` (MySQL error 1062 / SQLSTATE 23000; Postgres 23505) and the runner returns quietly on `AlreadyIgnited`. The migration must dedupe existing duplicates first (keep the lowest id), or the index build fails. `dbDelta` does not surface that failure loudly, so the wp adapter needs a checked migration step.
- **Related:** `ReplayDeadLetterHandler.php:33` mints a new `event_id`, which defeats the ignition dedup key and contradicts the contract at `IProcessRepository.php:24-27` ("event_id is per-publication stable across retries and replays"). Replay should keep the original `event_id` (the DLQ row has it, `tables.php:77`) with a new outbox row id, or record `replay_of`.
- **Test:** integration: two PDO connections deliver the same envelope concurrently (barrier via `GET_LOCK` on a test lock, or two processes) → exactly one process row, the second returns without running steps. Unit: a fake store that throws duplicate-key on the second insert → no step runs.

### Bug 3: delayed events delayed twice

- **Location:** `OutboxRepository.php:31-32` sets `scheduled_at = now + delay`; `:97` fetches only rows with `scheduled_at <= now`; `ActionSchedulerOutboxPublisher.php:21-27` then schedules `time() + $entry->delay_seconds` again. The effective delay is at least 2 × delay plus the relay tick. On every **retry** the publisher adds `delay_seconds` again after `next_attempt_at` has already gated the row.
- **Masking:** `tests/Fakes/FakeOutboxRepository.php:53-54` writes `scheduled_at = now` with `delay_seconds = delay()`, so unit tests never see the gate and the add together.
- **Fix (hotfix and extraction):** the due time is absolute and lives on the row. The publisher schedules at `max(now, scheduled_at_utc)`; when that is ≤ now (the normal case, because the relay only fetches due rows) it calls `as_enqueue_async_action`. `delay_seconds` becomes informational. Parse `scheduled_at` explicitly as UTC (`new DateTimeImmutable($s, new DateTimeZone('UTC'))`) rather than relying on WP's `date_default_timezone_set('UTC')`. Document for `{prefix}_outbox_publish_external` hookers (`RoutingOutboxPublisher.php:58-63`) that they must not add `delay_seconds` either.
- **Compatibility risk:** consumers who tuned `delay()` against the observed doubled delay see delays halve. The inventory of `delay()` overrides across the five consumers (review finding 1) must precede release. The changelog entry is "behaviour fix".
- **Test:** unit: an entry with `delay_seconds=600` and `scheduled_at = now-1` → the publisher enqueues async (or schedules at ≤ now+1). Integration: event with `delay()=3`; run the relay at t+3; the AS action's `scheduled_date_gmt` is ≤ t+3+tick; it executes once.

---

## 6. Findings

| ID | Claim | Evidence (path:line) | Proposed owner | Compatibility risk | Test that proves it | Unresolved question |
|---|---|---|---|---|---|---|
| C1 | GET_LOCK NULL/error enters the section unlocked (bug 1) | `ddd-src/Application/Process/ProcessRunner.php:397-401`; stub `tests/wp-stubs.php:23` | legacy (hotfix) + core port `IProcessLock` + wp impl | low; stub change touches ~13 test files | spy wpdb null/'0' → no save, throws | Does any consumer override `ProcessRunner` or call `with_process_lock` reflectively? |
| C2 | Lock contention "will be retried" is false; AS does not retry, so the wake is lost | `ProcessRunner.php:392,400`; `vendor/woocommerce/action-scheduler/classes/abstracts/ActionScheduler_Abstract_QueueRunner.php:105-113` | core (re-queue contract) + wp (reschedule a resume job) | medium: adds AS actions | hold lock on conn 2 → deliver event → assert a later retry resumes | Retry key: (event_id, process_id)? |
| C3 | Lock name not namespaced by consumer/site; GET_LOCK is server-global | `ProcessRunner.php:396` | core (LockKey) + wp | rename breaks exclusion during mixed-version deploy; needs a transition | two consumers with the same process id → no cross blocking | Acquire old+new names during one release? |
| C4 | Ignition check-then-insert allows duplicate sagas (bug 2) | `ProcessRunner.php:166-175`; `ProcessRepository.php:90-100`; `tables.php:131` | legacy (lock hotfix) + core (`insertIgnited` contract) + wp/pdo-default/symfony (unique index) | schema migration must dedupe | concurrent double delivery → one row | Dedupe policy for existing duplicates: keep oldest, fail the others? |
| C5 | Delayed events delayed twice, and again on each retry (bug 3) | `OutboxRepository.php:31-32,97`; `ActionSchedulerOutboxPublisher.php:21-27` | legacy + core (absolute `due_at` contract) + wp | delays halve for tuned consumers | delay=3 → AS due ≤ t+3+tick | Which consumers override `delay()`? |
| C6 | `resume_on_event` reads outside the lock and never re-reads → lost AwaitAll tallies, resurrection after timeout | `ProcessRunner.php:292-318` | core (runner policy: re-read under lock, CAS save) | behaviour fix; no data change | R3/R4 two-connection interleaving | none |
| C7 | `continue_scheduled` guards outside the lock | `ProcessRunner.php:266-284` | core | none | double continuation → step runs once | none |
| C8 | `schedule_continuation` save-then-enqueue can strand `scheduled` rows forever | `ProcessRunner.php:661-670` | core (scheduling-intent contract) + wp (same-tx AS insert) | none if additive | kill between save and enqueue → recovery produces a wake | Recovery scanner or intent row in wp? (AS uses the same `$wpdb`, so one tx is possible) |
| C9 | `suspend_for_event` saves, then schedules the timeout separately: a lost alarm | `ProcessRunner.php:640-655` | core + wp | none | kill between → timeout still fires | none |
| C10 | Commands are dispatched before the suspended state is saved (R11); D3 requires the await persisted first | `ProcessRunner.php:473-478` | core | ordering change visible to steps that read their own process row (unlikely) | sync transport: fact delivered inside the dispatch → still resumes | Precheck semantics for a fact that arrived before registration (D3) |
| C11 | `start()` persists before locking and has no recovery for crashed `running` rows | `ProcessRunner.php:231-233`; `LongProcess.php:267-273` | core (lease on process row, stranded-scan repair) | new columns | kill mid-step → repair finds it and resumes/fails it | Auto-resume or operator-only? |
| C12 | Step effects are at-least-once: commands commit before the checkpoint save | `ProcessRunner.php:465-488,619-628` | core (documented contract; D1 ExternalEffect journal) | none | crash after command → re-run dispatches again; dedup by command id | Deterministic command ids from (process_id, step_index) (D13)? |
| C13 | `TransactionMiddleware` ignores query failures, runs without tx when wpdb is missing, cannot detect an outer tx | `TransactionMiddleware.php:34-54` | core (`ITransactionBoundary` checked) + wp | a failed COMMIT now surfaces as an error | inject a failing COMMIT → command reports failure | Join-with-savepoint or reject for caller-owned tx? |
| C14 | Outbox INSERT result unchecked → silent fact loss inside a committed tx | `OutboxRepository.php:60-62` | wp + pdo-default (ERRMODE_EXCEPTION) | none | force insert failure → command fails and rolls back | none |
| C15 | Relay lease hard-coded 300 s, ignoring `lock_timeout_seconds`; `release_stale_locks` is redundant with the claim predicate | `OutboxRepository.php:77,273-284`; `OutboxConfig.php:19` | core (lease policy) + wp | option starts to take effect | lease=2 s, stalled worker → re-claim at 2 s | none |
| C16 | No fencing on complete/fail/DLQ (R1) | `OutboxRepository.php:144-157,183-195,262-270` | core (claim token contract) + all impls | additive column `claim_token` | stale owner's `complete()` affects 0 rows | none |
| C17 | `mark_failed` read-modify-write of attempts | `OutboxRepository.php:162-195` | core contract; impl `attempts = attempts + 1` | none | concurrent fail → attempts +2 | none |
| C18 | `move_to_dlq` is two autocommits → duplicate DLQ rows or a DLQ row plus a completed row | `OutboxRepository.php:243-270` | wp/pdo-default/symfony (one tx) | none | kill between → no orphan | none |
| C19 | Relay "completed" = accepted by AS, not handled | `OutboxProcessor.php:72-73` | core (status vocabulary: `accepted` vs `handled`) | keep `completed` readable | doc + dashboard label | Rename status or add column? |
| C20 | WP: one AS action per fact → one failing listener suppresses ignition/resume and the other listeners; no retry | `integration-events.php:88-104`; `ProcessRunner.php:148-180,77-96` (priorities 50/99) | wp (per-subscriber delivery) + core (subscription contract) | changes AS action count and hook shape | listener A throws → B, ignition and resume still run | Fan-out per subscriber in relay, or catch-per-callback plus a failure ledger? |
| C21 | No DDD-visible handler DLQ in WP; AS failures are only in AS admin | `ddd-wordpress/Admin/Dashboard/Query/*` (no `actionscheduler_*` reads) | wp + core (D9 operator view port) | none | dashboard shows a failed delivery | none |
| C22 | Replay mints a new `event_id` (weak `wp_generate_uuid4`), breaks ignition dedup and listener idempotency, hard-codes attempts/sequence | `ReplayDeadLetterHandler.php:33-49`; contract `IProcessRepository.php:24-27`; `OutboxRepository.php:430-435` | core (repair semantics) + wp | replayed rows keep the original id; needs uniqueness change (`uniq_event_id`, `tables.php:66`) → store `replay_of`, or clear the old row first | replay an igniting fact → no second saga | Keep `event_id` unique per row, or allow replays with a `replay_seq`? |
| C23 | Replay/Retry are not transactional and not guarded by status or lease | `ReplayDeadLetterHandler.php:32-58`; `RetryDeliveryHandler.php:21-32` | core (repair commands as `ITransactionalCommand` + guards) | Retry of a `completed` row becomes an error unless forced | retry a leased row → refused | Should Retry on `completed` stay allowed (force flag)? |
| C24 | `dlq_unresolved` always 0: `resolved_at` column does not exist | `OutboxRepository.php:316-318`; `tables.php:73-90` | wp | none | stats after one DLQ → 1 | Add the column, or count all DLQ rows? |
| C25 | Pause store is a read-modify-write option → lost holds | `OutboxRepository.php:341-354` | core (`IRelayPauseStore`) + wp (rows or `add_option` per holder) | migrate option data | two concurrent holders both present | none |
| C26 | `cancel_duplicates` ignores the payload signature and cancels leased rows | `OutboxRepository.php:286-300` | core contract + impls | narrowing cancel changes behaviour for consumers relying on the type-wide cancel | leased row not cancelled; different payload kept | Is type-wide cancel what consumers want? |
| C27 | `ProcessRepository::save` ignores insert/update failure → process id 0 runs unpersisted | `ProcessRepository.php:58-72` | wp + core contract | none | failing insert → exception, no steps | none |
| C28 | Workflow runs inside a command: tx held across external effects (transactional) or per-item autocommit; no per-workflow lock | `WorkflowHandler.php:59-74,271-288`; `RescheduleAware.php:10` | core (workflow lease) + consumers | consumer `reschedule` implementations vary | two concurrent runs → each item once | Should workflows move onto the process lock/lease? |
| C29 | `persist_meta` delete-then-insert is non-atomic | `BehaviourWorkflowRepository.php:145-160` | wp | none | kill between → meta intact | none |
| C30 | Relay core class depends on WP (`has_action`, `error_log`, `wp_json_encode`) | `OutboxProcessor.php:69,160-165` | core (PSR-3 logger, optional `ISubscriberProbe`) + wp | none | core-only autoload test | none |
| C31 | Runner is WP-bound: `add_action`, `as_*`, `global $wpdb`, `WP_CLI` | `ProcessRunner.php:77,148,395-406,649,665,220` | core (subscription, scheduler, lock ports) + wp | high surface: public class kept as facade | core-only process test with in-memory doubles | none |
| C32 | Status enum `processing` is never used; claims keep `pending` | `tables.php:55`; `OutboxRepository.php:94-128` | core vocabulary | none | — | Use `claimed` explicitly in the new schema? |
| C33 | No relay, repair or stuck-process CLI in WP | `ddd-wordpress/cli/class-ddd-command.php:51,80` | wp + symfony console + core `runOnce` | none | `wp ddd relay --once` drains | Operator surface for pdo-default (a script, or `runOnce` report only)? |

---

## 7. What a portable port set must express

The three hosts differ in connection ownership and transport, but the scenarios must be identical. Each port below states the semantics, not a final signature.

### 7.1 `ITransactionBoundary` (core port; wp / pdo-default / symfony impls)
- `transactional(callable)`: begin, commit, rollback on the **same connection** the domain repositories and the outbox use. Every failure throws, including a failed COMMIT.
- Detects an open caller-owned transaction. Policy: join via SAVEPOINT or reject before the handler runs (spec section 1). Never issue a bare `START TRANSACTION` inside an open MySQL tx (implicit commit).
- `ITransactionalCommand` without a capable boundary throws before the handler (C13).
- pdo-default: `PdoTransactionBoundary(PDO $db)` asserts `PDO::ATTR_ERRMODE === ERRMODE_EXCEPTION` at construction and throws a configuration error otherwise, rather than mutating the host's connection. It uses `$db->inTransaction()` for detection.
- symfony: DBAL `Connection::transactional` on the ORM's connection, with `flush()` before commit.

### 7.2 `IOutboxStore` (core port)
- `append(record)` inside the ambient tx; it throws on failure (C14).
- `claim(limit, now) → Claim[]`, each with an opaque `claim_token` and `lease_until`, excluding paused selectors and rows not yet due (`due_at` absolute UTC, `next_attempt_at`). Implementation: `SELECT … FOR UPDATE SKIP LOCKED` works on MySQL 8 and Postgres ≥ 9.5; a conditional UPDATE claim is the fallback.
- `accept(claim, transport_ref)`, `retryLater(claim, error, next_at)` (atomic `attempts = attempts+1`), `deadLetter(claim, error)` (one tx). Each is **fenced**: `WHERE id=? AND claim_token=?`, and 0 affected rows means lost lease, so the result is discarded and logged (C16-C18).
- Status vocabulary separates `accepted` (transport has it) from handler outcome (C19); legacy `completed` stays readable as `accepted`.
- `IRelayPauseStore` with one row per holder, exact or wildcard selector, expiry, independent release (C25).

### 7.3 `IOutboxPublisher` / transport (core port)
- `publish(record, envelope, due_at) → Acceptance`. `due_at` is absolute; the publisher never adds a relative delay (bug 3).
- Returns an unambiguous acceptance or throws. A `0` action id from `as_enqueue_async_action` (not initialized, or short-circuited by a `pre_as_*` filter, `vendor/woocommerce/action-scheduler/functions.php:20-43`) must throw, not count as success.
- Capability flag `sharesConnectionWith(store)`: when the transport's storage is the same DB connection (AS on `$wpdb`, the pdo-default jobs table, the Messenger Doctrine transport on the same DBAL connection), the relay runs `publish + accept` **in one tx** and the duplicate window R2 disappears. Otherwise (AMQP, external webhook) it is at-least-once and the contract says so.

### 7.4 `IProcessLock` (core port; D4)
- `acquire(LockKey{consumer, tenant, process_id}, timeout) → LockHandle | throws LockNotAcquired`; `release(handle)`. Error and timeout are both "not acquired" (bug 1). Re-entrancy is declared: either reentrant per handle owner, or the runner is refactored to take the lock exactly once per wake (preferred: `handle_timeout` today nests at `ProcessRunner.php:345,364`).
- **Lifetime:** the wake spans several commits (each step's commands commit on their own, `ProcessRunner.php:619-628`). A transaction-scoped lock (`pg_advisory_xact_lock`, `SELECT … FOR UPDATE` on the process row) therefore does **not** cover the wake as currently structured. Options:
  - (a) Session lock: MySQL `GET_LOCK` checked for `'1'`; Postgres `pg_advisory_lock(key1,key2)` / `pg_try_advisory_lock` in a timeout loop on a **non-pooled** connection, for example `symfony/lock` `DoctrineDbalPostgreSqlStore`. It breaks under PgBouncer transaction pooling.
  - (b) **Row lease with fencing** on the process row: `UPDATE processes SET lease_token=?, lease_until=? WHERE id=? AND (lease_until IS NULL OR lease_until < now)`, and every save is `WHERE id=? AND lease_token=?`. It is portable across MySQL and Postgres, pool-safe, recovers from crashes by expiry, and catches stale owners. It needs lease renewal for long wakes.
  - Recommendation for contract freeze (answers review finding 9): the conformance contract is (b)'s semantics (exclusive, expiring, fenced). wp keeps a checked `GET_LOCK` for 0.6 compatibility, and pdo-default and symfony implement (b). (a) is an optional symfony adapter only where a dedicated connection is guaranteed.
- On contention the wake is re-queued through the delivery runner with backoff (C2), never dropped.

### 7.5 `IProcessStore` (core port)
- `insertIgnited(process, process_class, event_id) → Inserted | AlreadyIgnited`, backed by a unique constraint (bug 2).
- `find(id)`, `findWaitingFor(await_key)` for keyed awaits (D3). Rows are **re-read under the lock or lease** before any guard (C6, C7).
- `save(process, expected_version)` with a `version` column (optimistic check as a second line of defence), throwing on failure (C27).
- `findStranded(now)`: `running` or `scheduled` with an expired lease and no pending job, for repair (C8, C11).

### 7.6 `IDurableScheduler` / job intents (core port; D7, D14)
- `schedule(JobIntent{kind: continue|timeout|resume_retry|deliver, consumer, process_id, step_index, expected_status, due_at UTC, idempotency_key})` is written **in the same tx** as the process state change (C8, C9). A job is stale-safe: a handler re-checks `expected_status`/`step_index` under the lock and no-ops otherwise (this generalises `ProcessRunner.php:348-353` to every job kind).
- Implementations: wp: AS insert inside the same `$wpdb` tx (possible because AS `DBStore` writes through `$wpdb`), or an intent row relayed to AS. pdo-default: a `{prefix}_jobs` table (due_at, claim_token, attempts, last_error) on the host connection. symfony: an intent row in the domain tx, relayed to Messenger with `DelayStamp`/`RedeliveryStamp`, or the Doctrine transport on the same connection (atomic).
- Post-commit wakeup (D14) is an optimisation only: `runOnce()` polling must recover a lost wakeup.

### 7.7 Subscription and delivery (core port)
- Delivery is **per subscriber**: (event_id, subscriber_id), with each subscriber's retry budget and failure record independent (C20). The subscriber id is stable (listener class, `process_class#ignite`, `process_class#resume`).
- Consumer-side dedup ledger (inbox) keyed by (subscriber_id, event_id) is available as a default, because every path above is at-least-once (R2, C12).
- Ordering contract: ignition before resume for the same fact (today priorities 50 < 99, `ProcessRunner.php:113-115`) must be preserved or explicitly dropped.

### 7.8 Entry point for plain PHP (pdo-default; replaces spec M3a)
```php
// host already has $db (PDO, ERRMODE_EXCEPTION), shared with its own repositories
$ddd = DurableRuntime::compose(
  connection: new PdoConnection($db),   // no DSN, no credentials, no connect
  consumer: 'acme',                      // namespaces tables, locks, jobs
  handlers: $handlerMap, listeners: $listenerMap, processes: $processCatalog,
);
$ddd->commands()->handle(new DoThing(...));   // request path
// cron every minute, or register_shutdown_function after fastcgi_finish_request():
$report = $ddd->runOnce(maxItems: 200, maxSeconds: 50); // relay batch + due jobs, then return
```
- Schema: shipped as plain SQL (`schema/mysql8/*.sql`; Postgres DDL belongs to ddd-symfony), applied by the host. A `SchemaProbe` fails clearly on missing tables or columns. No migration runner.
- `runOnce` is bounded, re-entrant across concurrent cron invocations (claims and leases), and resets per-job context in `finally` (spec section 4).
- CodeIgniter or raw PHP get atomicity only when `$db` is the **same** PDO their repositories use; the recipe must show that.

### 7.9 Operator view (D9)
One read port listing: relay DLQ rows, delivery failures per subscriber (AS failed actions in wp, the jobs table in pdo-default, the Messenger failure transport in symfony), stranded processes (C11), and failed workflows. Repair commands (retry, replay keeping `event_id`, discard, resume or fail a stranded process) are transactional and guarded (C22, C23).

---

## 8. Fresh-process scenarios (acceptance, pdo-default on MySQL 8; the same script on symfony/Postgres)

Every step is a separate `php` invocation sharing only the database.

1. **P1 (request):** `commands()->handle(RequestThing)` → tx {domain row, outbox row E1} → commit → exit. The DB has E1 `pending`.
2. **P2 (cron `runOnce`):** claims E1 (token T1) → writes delivery jobs per subscriber and accepts E1 in one tx (shared connection) → runs due jobs: the ignition subscriber `insertIgnited(Proc, E1)` → Inserted → takes the lease → step 1 dispatches C1 (own tx; C1's fact E2 goes to the outbox) → saves `suspended(await key K)` + a timeout job at absolute `due_at` **in one tx** → releases the lease → exit.
3. **P3 (cron):** relays E2 → the resume job for K → re-read under lease → advance → step 2 → complete → exit. The timeout job becomes due later; P4 re-reads the process as `completed` and no-ops.

Crash variants, each ending in the same final state:
- kill P2 after the E1 claim, before accept → a later run reclaims after the lease expires; same `event_id`; ignition returns `AlreadyIgnited` if it had already run.
- kill P2 inside step 1 after C1 commits → the process lease expires → `findStranded` / job retry re-runs step 1; C1's side effect is deduped by deterministic command id (D13) or the D1 journal.
- run P2 twice concurrently → SKIP LOCKED and leases give one ignition and one step execution.
- deliver E2 twice → resume once; the second no-ops under re-read.
- timeout and E2 race → exactly one of {resume, timeout path} applies; no resurrection (R4).
- stop cron for an hour → nothing is lost; the next `runOnce` drains in `due_at` order, and each delayed item fires once (bug 3).

WP equivalent today: P1 is a WP request, P2 is an AS runner (WP-Cron loopback or `wp action-scheduler run`). The same scenarios fail at R2/R3/R4/R6/C8/C20 as documented above.

---

## 9. Mapping to TXP demands (module-map D1-D14, durable subset)

| Demand | What durable execution must provide | Findings |
|---|---|---|
| D1 ExternalEffect | perform outside the tx, keyed journal, record inside the tx; the step re-run window (C12) is where the journal pays off | C12, C28 |
| D3 keyed await | await persisted **before** dispatch, keyed lookup, precheck for early arrival, any-of cancellation, dynamic AwaitAll | C6, C10, R11 |
| D4 advisory-lock scope | section 7.4: lease-with-fencing contract; GET_LOCK kept in wp; session advisory only on dedicated connections | C1, C3 |
| D7 durable alarms | absolute UTC `due_at` job intents in the same tx; single-delay test | bug 3, C8, C9 |
| D9 unified operator view | section 7.9 | C21, C24, C33 |
| D10 workflow ignition from a fact | same unique-ignition gate as processes, keyed (workflow, minute) | C4, C28 |
| D13 process_id / step_index access | job idempotency keys and deterministic command ids | C12 |
| D14 post-commit wakeup | optimisation over polling; requires C10 ordering fixed first | C10, 7.6 |

---

## 10. Suggested sequencing inside the extraction branch

1. Fix bugs 1-3 on the extraction branch, and mirror them on the 0.6.x hotfix branch (unreleased): lock check plus stub, ignition lock (schema-free), absolute due time. Add unit tests that fail on 598858c.
2. Characterise R3/R4/R5 in a real-MySQL integration test before refactoring the runner to re-read under the lock. This is a behaviour fix with its own changelog line.
3. Freeze ports 7.1-7.7 with in-memory doubles and a shared conformance suite (the scenarios in section 8), then implement wp, then pdo-default (MySQL 8), then symfony (Postgres). Per review finding 2, symfony can start from the frozen contracts in parallel with wp.

---

## Open questions

1. **Lock contract (D4):** adopt row-lease-with-fencing as the portable contract and keep checked `GET_LOCK` only in wp? Or require session locks everywhere, which excludes pooled Postgres connections?
2. **Wake structure:** keep one wake spanning several commits (session lock or lease needed), or restructure to one transaction per step (process save + job intents + the step's commands' writes), which would allow transaction-scoped locks but conflicts with per-command `TransactionMiddleware` and the no-nesting guard?
3. **Per-subscriber delivery in wp:** fan out one AS action per subscriber at relay time (changes action counts and hook shapes seen by consumers), or keep one action per fact and catch per callback into a failure ledger?
4. **Replay identity:** keep the original `event_id` on replay (needs `uniq_event_id` relaxed or the original row replaced), or add `replay_of` and teach ignition and inbox dedup to follow it?
5. **Existing duplicate ignitions:** what does the unique-index migration do with duplicates already in consumer databases: keep the oldest and mark the others, or refuse to migrate?
6. **Delay semantics change:** which of cred, lms-monorepo, datastream, reporting and docker-factory override `delay()` and would see delays halve after bug 3?
7. **Lock rename transition:** acquire both the old `ddd_process_<id>` and the new namespaced name for one release, or accept a brief unprotected window during deploy?
8. **Stranded processes:** should `runOnce` / the wp relay auto-resume expired-lease `running`/`scheduled` rows, or only report them for operator repair?
9. **Retry on `completed`:** keep `RetryDeliveryCommand` able to redeliver completed rows (force flag), or restrict it to `pending`/`dlq`?
10. **`is_unique` scope:** is the type-wide cancel (payload ignored) intended by consumers, or should it match on a payload signature as the interface doc says (`IOutboxRepository.php:101-111`)?
11. **pdo-default and Postgres:** is the thin PDO adapter MySQL-only (Postgres only through DBAL in ddd-symfony), or should its SQL stay dialect-neutral enough (SKIP LOCKED, conditional-UPDATE leases, no `ON DUPLICATE KEY`) that a PDO-pgsql host works untested?
12. **Workflow concurrency:** should behaviour workflows move onto the same lease/lock and job-intent machinery as processes, given `reschedule()` is consumer-owned today (`WorkflowHandler.php:107`)?
