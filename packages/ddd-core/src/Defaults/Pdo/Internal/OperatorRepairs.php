<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo\Internal;

use TangibleDDD\Application\Process\Repair\FailStrandedProcess;
use TangibleDDD\Application\Process\Repair\FailStrandedProcessHandler;
use TangibleDDD\Application\Process\Repair\ResumeStrandedProcess;
use TangibleDDD\Application\Process\Repair\ResumeStrandedProcessHandler;
use TangibleDDD\Defaults\Pdo\IHostConnection;
use TangibleDDD\Defaults\Pdo\MySqlNamedLock;
use TangibleDDD\Defaults\Pdo\PdoJobStore;
use TangibleDDD\Defaults\Pdo\PdoOutboxAdministration;
use TangibleDDD\Defaults\Pdo\PdoProcessStore;
use TangibleDDD\Defaults\Pdo\PdoRepairRefused;
use TangibleDDD\Defaults\Pdo\PdoTransactionBoundary;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\Lock\ReentrantProcessLock;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Runtime\Outbox\OutboxRowNotFound;
use TangibleDDD\Runtime\PrefixedTableNames;

/**
 * The repairs behind PdoOperatorView::repair() (register 3.10, C23; wave 4).
 * See PdoOperatorView for the action table and the guards.
 *
 * @internal
 */
final class OperatorRepairs {

  /** @var array<string, list<string>> layer => the actions it has */
  private const ACTIONS = [
    'relay' => ['retry', 'replay', 'discard'],
    'delivery' => ['redeliver'],
    'wakeup' => ['retry_wake'],
    'process' => ['resume_stranded', 'fail_stranded'],
  ];

  private readonly string $jobs;
  private readonly string $dlq;
  private readonly string $ledger;

  public function __construct(
    private readonly IHostConnection $db,
    private readonly string $consumer,
    private readonly string $tablePrefix,
    private readonly IClock $clock,
    private readonly PdoOutboxAdministration $administration,
    private readonly PdoProcessStore $processes,
  ) {
    $tables = new PrefixedTableNames($tablePrefix);
    $this->jobs = $tables->table('ddd_jobs');
    $this->dlq = $tables->table('ddd_dlq');
    $this->ledger = $tables->table('ddd_delivery_ledger');
  }

  /** @param array<string, mixed> $options */
  public function run(Layer $layer, string $key, string $action, array $options): void {
    if (!in_array($action, self::ACTIONS[$layer->value] ?? [], true)) {
      throw new \InvalidArgumentException(sprintf(
        'Layer %s has no repair "%s" (it has: %s)', $layer->value, $action, implode(', ', self::ACTIONS[$layer->value] ?? []) ?: 'none'
      ));
    }

    match ($action) {
      'retry' => $this->administration->retry($key, (bool) ($options['force'] ?? false)),
      'replay' => $this->administration->replay($this->newestDeadLetter($key)),
      'discard' => $this->administration->discard($this->newestDeadLetter($key)),
      'redeliver' => $this->redeliver($key),
      'retry_wake' => $this->dueNow($key, "kind <> 'deliver'", "Wakeup $key"),
      'resume_stranded' => $this->resumeHandler()->handle(new ResumeStrandedProcess(
        $this->consumer, $this->processId($key), self::optionalInt($options, 'expected_version'),
      )),
      'fail_stranded' => $this->failHandler()->handle(new FailStrandedProcess(
        $this->consumer, $this->processId($key), self::reason($options), (bool) ($options['compensate'] ?? false),
        self::optionalInt($options, 'expected_version'),
      )),
    };
  }

  private function newestDeadLetter(string $eventId): int {
    $id = $this->db->fetch_one("SELECT id FROM `{$this->dlq}` WHERE event_id = ? ORDER BY id DESC LIMIT 1", [$eventId])['id'] ?? null;
    if ($id === null) {
      throw new OutboxRowNotFound("Event $eventId has no dead letter");
    }
    return (int) $id;
  }

  /** $key is `subscriber@event_id` (a ledger item) or `deliver:{event_id}` (a deliver job). */
  private function redeliver(string $key): void {
    if (str_starts_with($key, 'deliver:')) {
      $this->dueNow($key, "kind = 'deliver'", "Deliver job $key");
      return;
    }
    $at = strrpos($key, '@'); // subscriber ids may themselves contain '@'; event ids do not
    if ($at === false || $at === 0 || $at === strlen($key) - 1) {
      throw new \InvalidArgumentException("A delivery key is subscriber@event_id or deliver:{event_id}, got $key");
    }
    $subscriber = substr($key, 0, $at);
    $eventId = substr($key, $at + 1);
    $row = $this->db->fetch_one(
      "SELECT delivered_at, exhausted_at FROM `{$this->ledger}` WHERE subscriber_id = ? AND event_id = ?",
      [$subscriber, $eventId]
    );
    if ($row !== null && $row['exhausted_at'] !== null) {
      throw new PdoRepairRefused("$subscriber exhausted its budget for $eventId; its compensation ran, nothing to redeliver");
    }
    if ($row !== null && $row['delivered_at'] !== null) {
      throw new PdoRepairRefused("$subscriber already delivered $eventId");
    }
    $this->dueNow('deliver:' . $eventId, "kind = 'deliver'", "The deliver job of $eventId");
  }

  /** Make a queued, unleased job due now; attempts stay (the history the operator saw). */
  private function dueNow(string $idempotencyKey, string $kindSql, string $what): void {
    $now = Utc::to_db($this->clock->now());
    $n = $this->db->execute(
      "UPDATE `{$this->jobs}` SET next_attempt_at = ?, due_at = LEAST(due_at, ?)
       WHERE idempotency_key = ? AND $kindSql AND (claim_token IS NULL OR lease_until <= ?)",
      [$now, $now, $idempotencyKey, $now]
    );
    if ($n === 0 && $this->db->fetch_one("SELECT 1 AS x FROM `{$this->jobs}` WHERE idempotency_key = ? AND $kindSql AND next_attempt_at = ?", [$idempotencyKey, $now]) === null) {
      $exists = $this->db->fetch_one("SELECT 1 AS x FROM `{$this->jobs}` WHERE idempotency_key = ? AND $kindSql", [$idempotencyKey]) !== null;
      throw new PdoRepairRefused($exists ? "$what is leased by a worker; repair refused" : "$what is not queued (completed or gone)");
    }
  }

  private function processId(string $key): int {
    if (!ctype_digit($key) || (int) $key <= 0) {
      throw new \InvalidArgumentException("A process key is the process id, got $key");
    }
    return (int) $key;
  }

  /** @param array<string, mixed> $options */
  private static function reason(array $options): string {
    $reason = $options['reason'] ?? null;
    if (!is_string($reason) || trim($reason) === '') {
      throw new \InvalidArgumentException('fail_stranded needs a non-empty reason option');
    }
    return $reason;
  }

  /** @param array<string, mixed> $options */
  private static function optionalInt(array $options, string $name): ?int {
    return isset($options[$name]) ? (int) $options[$name] : null;
  }

  private function resumeHandler(): ResumeStrandedProcessHandler {
    return new ResumeStrandedProcessHandler(...$this->repairPorts());
  }

  private function failHandler(): FailStrandedProcessHandler {
    return new FailStrandedProcessHandler(...$this->repairPorts());
  }

  /** @return list<object> store, scheduler, lock, clock, boundary on this connection */
  private function repairPorts(): array {
    return [
      $this->processes,
      new PdoJobStore($this->db, $this->consumer, $this->tablePrefix, $this->clock),
      new ReentrantProcessLock(new MySqlNamedLock($this->db)),
      $this->clock,
      new PdoTransactionBoundary($this->db),
    ];
  }
}
