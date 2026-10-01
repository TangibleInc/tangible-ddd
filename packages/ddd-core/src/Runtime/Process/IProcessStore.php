<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Process;

use TangibleDDD\Application\Process\LongProcess;

/**
 * Version-fenced process persistence with ignition dedup (register 3.8, X7,
 * 5.2, 5.3). New interface; consumers' IProcessRepository implementations
 * are bridged by LegacyProcessStore in wave 3 (R3).
 *
 * - insertIgnited(): the #[StartsOn] ignition path ONLY. Writes
 *   ignition_key = uuid5(event_id, process_class) (IgnitionKey::for) under
 *   UNIQUE (process_class, ignition_key) and returns AlreadyIgnited on a
 *   duplicate key (MySQL 1062 / Postgres 23505 only, never SQLSTATE class
 *   23000). The loser is not persisted and runs no step (bug 2). On wp it
 *   also checks long_processes.ignited_by_event_id for the class inside the
 *   ignition lock (rollback/roll-forward safety).
 * - insert(): manual start. ignition_key stays NULL; ignited_by_event_id is
 *   whatever the process carries. Never deduped, even inside a fact drain.
 * - Both inserts set the process id (set_id) and start at version 1.
 * - find(): call under the process lock. Returns null for an unknown id.
 *   An undecodable row is quarantined (status `failed`, quarantine_reason
 *   set) and QuarantinedProcess is thrown.
 * - save(): `WHERE id = ? AND version = ?`; returns the new version; 0 rows
 *   throws ConcurrentProcessModification. An unknown id throws
 *   ProcessStoreFailed.
 * - touch(): the fenced version bump the runner performs before each step
 *   dispatch (3.7); same errors as save(). (UNRATIFIED addition to the 3.8
 *   sketch; CR-5 in Runtime/API-CHANGE-REQUESTS.md.)
 * - versionOf(): the current version for the runner's fence, null for an
 *   unknown id. (UNRATIFIED addition; CR-5.)
 * - findWaitingFor(): ids only (E F14), of `suspended` processes waiting for
 *   the class; $awaitKey narrows keyed awaits (D3, wave 4). A store may
 *   index per route (LongProcess::await_routes(): one (event_class,
 *   await_key) row each, key '' = unkeyed) and match $awaitKey exactly, with
 *   null = any key; or index only the `waiting_for` column and ignore
 *   $awaitKey. Both are correct: the runner asks for (class, key) and
 *   (class, '') and filters every candidate through the await's accepts().
 *   Matching the class's parents and interfaces is optional: mem and pdo
 *   do, the wp adapters and LegacyProcessStore (a consumer's 0.6
 *   find_waiting_for) match `waiting_for = class` exactly. For AwaitAny the
 *   `waiting_for` value is the branches' common class or interface, so the
 *   runner also asks for each IIntegrationEvent ancestor of the fact unless
 *   the store implements IMatchesFactAncestry (one lookup then).
 * - findStranded(): `running`/`scheduled` rows with no live intent past the
 *   threshold (default 15 min).
 *
 * Error behaviour: every write failure throws ProcessStoreFailed; nothing
 * returns 0 or false for a failure (C27).
 * Connection rules: the host connection. The runner wraps "save + intents +
 * await rows" in ITransactionBoundary::run on this connection (5.3), and
 * insertIgnited runs in the same transaction as the initial save.
 */
interface IProcessStore {

  public function insertIgnited(LongProcess $p, string $processClass, string $eventId): IgnitionResult;

  public function insert(LongProcess $p): int;

  /** @throws QuarantinedProcess */
  public function find(int $id): ?LongProcess;

  /** @throws ConcurrentProcessModification|ProcessStoreFailed */
  public function save(LongProcess $p, int $expectedVersion): int;

  /** @throws ConcurrentProcessModification|ProcessStoreFailed */
  public function touch(int $id, int $expectedVersion): int;

  public function versionOf(int $id): ?int;

  /** @return list<int> */
  public function findWaitingFor(string $eventClass, ?string $awaitKey = null): array;

  /** @return list<StrandedProcess> */
  public function findStranded(\DateTimeImmutable $now): array;
}
