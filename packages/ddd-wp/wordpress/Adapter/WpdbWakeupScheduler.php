<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\Scheduling\ClaimedWakeup;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Runtime\Scheduling\WakeupOutsideTransaction;
use TangibleDDD\Runtime\SystemClock;

/**
 * The wp IWakeupScheduler on schema v8 (register 3.6, 5.3; ruling on
 * rollback with pending durable rows).
 *
 * The `{prefix}_ddd_wakeups` row is the recovery ledger and the fencing
 * source. Every intent is ALSO projected to Action Scheduler at schedule
 * time, future-dated to due_at, on the legacy hook with the legacy
 * ASSOCIATIVE args (R4), in the consumer's `processes` group, so a
 * rolled-back 0.6 winner still fires every pending timeout and
 * continuation, future-dated ones included (B16):
 *
 * - Timeout     → `{prefix}_await_timeout`    ['process_id' => int, 'step_index' => int]
 * - Continue    → `{prefix}_process_continue` ['process_id' => int]
 *                 (enqueued async when due now or earlier, as 0.6 did)
 * - ResumeRetry → `{prefix}_ddd_wakeup`       ['key' => idempotency key]
 *                 (no 0.6 callback: lost on rollback, like redeliveries)
 * - Deliver     → LogicException: wp handler retries go through the
 *                 `{prefix}_ddd_redeliver` hook (WpLedgeredDelivery), not intents.
 *
 * Row lifecycle: pending → firing (begin(), the AS callback is running the
 * wake) → done (finish() ok) | back to pending with attempts + 1 and
 * last_error (finish() with an error: the AS action failed, a later relay
 * tick re-projects it) | cancelled. Scheduling a key that is `pending` is a
 * no-op; a key that is firing, done or cancelled is RE-ARMED (a wake that
 * reschedules its own key from inside itself is not lost).
 *
 * Transactions: schedule() and cancel() throw WakeupOutsideTransaction
 * unless a DDD wpdb transaction is open (WpdbTransactionDepth): the intent,
 * its AS projection (same connection) and the state change commit together.
 * claimDue() / complete() / retryLater() are the port's lease-fenced drain
 * path (claim_token + locked_until); begin() / finish() / reproject() are
 * the wp-specific hooks used by the Action Scheduler callbacks and the
 * relay tick. A 0.6 copy never reads the table.
 */
final class WpdbWakeupScheduler implements IWakeupScheduler {

  /** A firing row older than this is a wake that died (fatal, killed worker): re-armed by reproject(). */
  public const FIRING_STALE_SECONDS = 900;

  public function __construct(
    private readonly IDDDConfig $config,
    private readonly ?IClock $clock = null,
  ) {}

  public function schedule(WakeupIntent $i): void {
    $this->requireTransaction('schedule');
    [$hook, $args] = $this->projection($i);
    $db = self::db();
    $now = $this->stamp();

    $row = $db->get_row($db->prepare("SELECT id, status FROM `{$this->table()}` WHERE idempotency_key = %s FOR UPDATE", $i->idempotencyKey));
    if ($row && $row->status === 'pending') {
      return; // duplicate key: no-op
    }

    $actionId = $this->project($i, $hook, $args);

    if ($row) {
      $ok = $db->query($db->prepare(
        "UPDATE `{$this->table()}` SET status = 'pending', due_at = %s, attempts = 0, last_error = NULL, claim_token = NULL,
           locked_until = NULL, hook = %s, args = %s, as_action_id = %d, kind = %s, process_id = %d, step_index = %d,
           expected_status = %s, updated_at = %s
         WHERE id = %d",
        self::utc($i->dueAt), $hook, (string) wp_json_encode($args), $actionId, $i->kind->value, (int) $i->processId,
        (int) $i->stepIndex, (string) $i->expectedStatus, $now, (int) $row->id
      ));
    } else {
      $ok = $db->insert($this->table(), [
        'idempotency_key' => $i->idempotencyKey,
        'kind' => $i->kind->value,
        'process_id' => $i->processId,
        'step_index' => $i->stepIndex,
        'expected_status' => $i->expectedStatus,
        'due_at' => self::utc($i->dueAt),
        'status' => 'pending',
        'attempts' => 0,
        'hook' => $hook,
        'args' => (string) wp_json_encode($args),
        'as_action_id' => $actionId,
        'created_at' => $now,
        'updated_at' => $now,
        'blog_id' => is_multisite() ? get_current_blog_id() : 1,
      ]);
    }
    if ($ok === false) {
      throw new \RuntimeException("Wakeup intent {$i->idempotencyKey} was not stored: " . (string) $db->last_error);
    }
  }

  public function cancel(string $idempotencyKey): void {
    $this->requireTransaction('cancel');
    $db = self::db();
    $row = $db->get_row($db->prepare("SELECT id, status, hook, args FROM `{$this->table()}` WHERE idempotency_key = %s", $idempotencyKey));
    if (!$row || !in_array($row->status, ['pending', 'firing'], true)) {
      return;
    }
    $db->query($db->prepare(
      "UPDATE `{$this->table()}` SET status = 'cancelled', claim_token = NULL, locked_until = NULL, updated_at = %s WHERE id = %d",
      $this->stamp(),
      (int) $row->id
    ));
    if ($row->status === 'pending' && function_exists('as_unschedule_action') && $row->hook) {
      $args = json_decode((string) $row->args, true);
      as_unschedule_action((string) $row->hook, is_array($args) ? $args : [], $this->group());
    }
  }

  public function claimDue(\DateTimeImmutable $now, int $limit, int $leaseSeconds): array {
    if (WpdbTransactionDepth::current() > 0) {
      throw new \TangibleDDD\Runtime\NestedTransactionRejected('IWakeupScheduler::claimDue() runs its own transaction and must be called outside one.');
    }
    $at = self::utc($now);
    $until = self::utc($now->modify('+' . max(0, $leaseSeconds) . ' seconds'));

    return (new WpdbTransactionBoundary())->run(function () use ($at, $until, $limit): array {
      $db = self::db();
      $rows = $db->get_results($db->prepare(
        "SELECT * FROM `{$this->table()}`
         WHERE status = 'pending' AND due_at <= %s AND (locked_until IS NULL OR locked_until <= %s)
         ORDER BY due_at ASC, id ASC LIMIT %d FOR UPDATE SKIP LOCKED",
        $at, $at, max(0, $limit)
      ));
      $claimed = [];
      foreach (is_array($rows) ? $rows : [] as $row) {
        $token = bin2hex(random_bytes(16));
        $db->query($db->prepare(
          "UPDATE `{$this->table()}` SET claim_token = %s, locked_until = %s WHERE id = %d",
          $token, $until, (int) $row->id
        ));
        $claimed[] = new ClaimedWakeup($this->intent($row), $token, new \DateTimeImmutable($until, new \DateTimeZone('UTC')), (int) $row->attempts);
      }
      return $claimed;
    });
  }

  public function complete(ClaimedWakeup $w): bool {
    $db = self::db();
    $n = $db->query($db->prepare(
      "UPDATE `{$this->table()}` SET status = 'done', claim_token = NULL, locked_until = NULL, updated_at = %s
       WHERE idempotency_key = %s AND claim_token = %s",
      $this->stamp(), $w->intent->idempotencyKey, $w->claimToken
    ));
    return (int) $n === 1;
  }

  public function retryLater(ClaimedWakeup $w, string $error, \DateTimeImmutable $nextAt): bool {
    $db = self::db();
    $n = $db->query($db->prepare(
      "UPDATE `{$this->table()}` SET attempts = attempts + 1, last_error = %s, due_at = %s, claim_token = NULL, locked_until = NULL, updated_at = %s
       WHERE idempotency_key = %s AND claim_token = %s",
      $error, self::utc($nextAt), $this->stamp(), $w->intent->idempotencyKey, $w->claimToken
    ));
    return (int) $n === 1;
  }

  // ── wp: the Action Scheduler callbacks and the relay tick ────────────────

  /**
   * The AS action of a wake started: its pending intents become `firing`.
   * Continue (args carry no step index): every pending continuation of the
   * process.
   */
  public function begin(WakeKind $kind, int $processId, ?int $stepIndex): void {
    $db = self::db();
    $step = $stepIndex === null ? '' : ' AND step_index = ' . (int) $stepIndex;
    // No due_at filter: Action Scheduler (or an operator's "run now") decided
    // the action is due, and every wake is stale-safe.
    $db->query($db->prepare(
      "UPDATE `{$this->table()}` SET status = 'firing', updated_at = %s
       WHERE kind = %s AND process_id = %d AND status = 'pending'$step",
      $this->stamp(), $kind->value, $processId
    ));
  }

  /**
   * The wake returned ($error null: done) or threw ($error: back to
   * pending, attempts + 1, so the relay tick re-projects it). Only rows
   * still `firing` change: a key the wake re-armed stays pending.
   */
  public function finish(WakeKind $kind, int $processId, ?int $stepIndex, ?string $error): void {
    $db = self::db();
    $step = $stepIndex === null ? '' : ' AND step_index = ' . (int) $stepIndex;
    if ($error === null) {
      $db->query($db->prepare(
        "UPDATE `{$this->table()}` SET status = 'done', updated_at = %s WHERE kind = %s AND process_id = %d AND status = 'firing'$step",
        $this->stamp(), $kind->value, $processId
      ));
      return;
    }
    $db->query($db->prepare(
      "UPDATE `{$this->table()}` SET status = 'pending', attempts = attempts + 1, last_error = %s, updated_at = %s
       WHERE kind = %s AND process_id = %d AND status = 'firing'$step",
      $error, $this->stamp(), $kind->value, $processId
    ));
  }

  /**
   * Relay tick (5.3 step 3, wp form): re-project every due `pending` intent
   * whose Action Scheduler action is gone (failed, deleted, lost), and
   * re-arm `firing` rows whose wake died long ago. Never re-projects while
   * a pending or running action exists for the same hook and args.
   *
   * @return int intents re-projected
   */
  public function reproject(\DateTimeImmutable $now, int $limit = 100): int {
    $db = self::db();
    $at = self::utc($now);
    $db->query($db->prepare(
      "UPDATE `{$this->table()}` SET status = 'pending', attempts = attempts + 1, last_error = COALESCE(last_error, 'wake died while firing')
       WHERE status = 'firing' AND updated_at <= %s",
      self::utc($now->modify('-' . self::FIRING_STALE_SECONDS . ' seconds'))
    ));

    $rows = $db->get_results($db->prepare(
      "SELECT * FROM `{$this->table()}` WHERE status = 'pending' AND due_at <= %s AND hook IS NOT NULL ORDER BY due_at ASC, id ASC LIMIT %d",
      $at, max(0, $limit)
    ));
    $n = 0;
    foreach (is_array($rows) ? $rows : [] as $row) {
      $args = json_decode((string) $row->args, true);
      $args = is_array($args) ? $args : [];
      if (as_has_scheduled_action((string) $row->hook, $args, $this->group())) {
        continue;
      }
      $intent = $this->intent($row);
      $actionId = $this->project($intent, (string) $row->hook, $args);
      $db->query($db->prepare("UPDATE `{$this->table()}` SET as_action_id = %d, updated_at = %s WHERE id = %d", $actionId, $this->stamp(), (int) $row->id));
      $n++;
    }
    return $n;
  }

  /** begin() for one intent by key (the ResumeRetry hook); null when it is not pending. */
  public function beginKey(string $idempotencyKey): ?WakeupIntent {
    $db = self::db();
    $n = $db->query($db->prepare(
      "UPDATE `{$this->table()}` SET status = 'firing', updated_at = %s WHERE idempotency_key = %s AND status = 'pending'",
      $this->stamp(), $idempotencyKey
    ));
    if ((int) $n !== 1) {
      return null;
    }
    $row = $db->get_row($db->prepare("SELECT * FROM `{$this->table()}` WHERE idempotency_key = %s", $idempotencyKey));
    return $row ? $this->intent($row) : null;
  }

  /** finish() for one intent by key. */
  public function finishKey(string $idempotencyKey, ?string $error): void {
    $db = self::db();
    if ($error === null) {
      $db->query($db->prepare(
        "UPDATE `{$this->table()}` SET status = 'done', updated_at = %s WHERE idempotency_key = %s AND status = 'firing'",
        $this->stamp(), $idempotencyKey
      ));
      return;
    }
    $db->query($db->prepare(
      "UPDATE `{$this->table()}` SET status = 'pending', attempts = attempts + 1, last_error = %s, updated_at = %s
       WHERE idempotency_key = %s AND status = 'firing'",
      $error, $this->stamp(), $idempotencyKey
    ));
  }

  /** The live (pending or firing) intents of a process. */
  public function hasLiveIntent(int $processId): bool {
    $db = self::db();
    return (bool) $db->get_var($db->prepare(
      "SELECT 1 FROM `{$this->table()}` WHERE process_id = %d AND status IN ('pending', 'firing') LIMIT 1",
      $processId
    ));
  }

  public function group(): string {
    return $this->config->as_group('processes');
  }

  /** @return array{0: string, 1: array<string, int|string>} */
  public function projection(WakeupIntent $i): array {
    if ($i->kind === WakeKind::Deliver) {
      throw new \LogicException("Wake kind deliver is not an intent on WordPress: handler retries use the {$this->config->hook('ddd_redeliver')} hook");
    }
    if ($i->processId === null) {
      throw new \InvalidArgumentException("Wakeup {$i->idempotencyKey} has no process id");
    }

    return match ($i->kind) {
      WakeKind::Timeout => [$this->config->hook('await_timeout'), ['process_id' => $i->processId, 'step_index' => (int) $i->stepIndex]],
      WakeKind::Continue => [$this->config->hook('process_continue'), ['process_id' => $i->processId]],
      WakeKind::ResumeRetry => [$this->config->hook('ddd_wakeup'), ['key' => $i->idempotencyKey]],
    };
  }

  /** @param array<string, int|string> $args */
  private function project(WakeupIntent $i, string $hook, array $args): int {
    if (!function_exists('as_schedule_single_action')) {
      throw new \RuntimeException("Action Scheduler is not loaded; wakeup {$i->idempotencyKey} cannot be projected");
    }
    $due = $i->dueAt->getTimestamp();
    $id = $i->kind === WakeKind::Continue && $due <= $this->clock()->now()->getTimestamp()
      ? as_enqueue_async_action($hook, $args, $this->group())
      : as_schedule_single_action($due, $hook, $args, $this->group());

    if ((int) $id === 0) {
      throw new \RuntimeException("Action Scheduler did not create the $hook action for {$i->idempotencyKey}");
    }
    return (int) $id;
  }

  public function intent(object $row): WakeupIntent {
    return new WakeupIntent(
      WakeKind::from((string) $row->kind),
      $this->config->prefix(),
      $row->process_id === null ? null : (int) $row->process_id,
      $row->step_index === null ? null : (int) $row->step_index,
      $row->expected_status === null ? null : (string) $row->expected_status,
      new \DateTimeImmutable((string) $row->due_at, new \DateTimeZone('UTC')),
      (string) $row->idempotency_key,
    );
  }

  private function requireTransaction(string $what): void {
    if (WpdbTransactionDepth::current() === 0) {
      throw new WakeupOutsideTransaction("IWakeupScheduler::$what() needs an open transaction on the WordPress connection: the intent and the state change commit together (C8, C9).");
    }
  }

  private function clock(): IClock {
    return $this->clock ?? HostDefaults::get(IClock::class) ?? new SystemClock();
  }

  private function stamp(): string {
    return self::utc($this->clock()->now());
  }

  private static function utc(\DateTimeImmutable $t): string {
    return $t->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
  }

  private function table(): string {
    return $this->config->table('ddd_wakeups');
  }

  private static function db(): \wpdb {
    return $GLOBALS['wpdb'];
  }
}
