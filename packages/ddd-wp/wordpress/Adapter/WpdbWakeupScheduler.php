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
use TangibleDDD\Runtime\Support\Log;
use TangibleDDD\Runtime\SystemClock;

/**
 * The wp IWakeupScheduler on schema v8 (register 3.6, 5.1, 5.3; ruling on
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
 * wake) → done (finish() ok) | cancelled | back to pending (finish() with an
 * error) | exhausted.
 *
 * Retry budget (register 5.1, wake layer): a failed wake counts one attempt
 * and goes back to `pending` with due_at = now + min(300, 2 × 2^n) s, n the
 * failures before this one; the relay tick re-projects it once that is due
 * (the AS action of the failed run is gone). The WAKE_BUDGET-th failure
 * makes the row `exhausted`: it leaves reproject(), surfaces in the
 * operator view (layer wakeup, repair `rearm`) and is re-armed only by
 * rearm(). A failure that can never succeed (the process is quarantined)
 * closes the row as `cancelled` at once (WpWakeBracket).
 *
 * Scheduling a key that is `pending` or `exhausted` is a no-op (an exhausted
 * wake is not restarted behind the operator's back); a key that is firing,
 * done or cancelled is RE-ARMED, so a wake that reschedules its own key
 * from inside itself is not lost. Re-arming a `firing` key keeps its
 * attempts (a wake that re-arms itself and then throws still spends its
 * budget); a wake that returns clears the count of the keys it re-armed.
 *
 * Transactions: schedule() and cancel() throw WakeupOutsideTransaction
 * unless a DDD wpdb transaction is open (WpdbTransactionDepth): the intent,
 * its AS projection (same connection) and the state change commit together.
 * claimDue() / complete() / retryLater() are the port's lease-fenced drain
 * path (claim_token + locked_until); begin() / finish() / reproject() are
 * the wp-specific hooks used by the Action Scheduler callbacks and the
 * relay tick. A 0.6 copy never reads the table. Every failed storage write
 * throws \RuntimeException.
 */
final class WpdbWakeupScheduler implements IWakeupScheduler {

  /** A firing row older than this is a wake that died (fatal, killed worker): re-armed by reproject(). */
  public const FIRING_STALE_SECONDS = 900;

  /** Register 5.1, wake execution: attempts before the intent is exhausted. */
  public const WAKE_BUDGET = 10;

  /** Register 5.1: backoff 2 s × 2^n, capped. */
  public const BACKOFF_BASE_SECONDS = 2;

  public const BACKOFF_CAP_SECONDS = 300;

  public function __construct(
    private readonly IDDDConfig $config,
    private readonly ?IClock $clock = null,
  ) {}

  /** Seconds before the retry that follows the ($failuresBefore + 1)-th failure. */
  public static function backoffSeconds(int $failuresBefore): int {
    return min(self::BACKOFF_CAP_SECONDS, self::BACKOFF_BASE_SECONDS << max(0, min(16, $failuresBefore)));
  }

  public function schedule(WakeupIntent $i): void {
    $this->requireTransaction('schedule');
    [$hook, $args] = $this->projection($i);
    $db = self::db();
    $now = $this->stamp();

    $row = $db->get_row($db->prepare("SELECT id, status FROM `{$this->table()}` WHERE idempotency_key = %s FOR UPDATE", $i->idempotencyKey));
    if ($row && in_array($row->status, ['pending', 'exhausted'], true)) {
      return; // duplicate key, or an exhausted wake only an operator re-arms
    }

    $actionId = $this->project($i, $hook, $args);

    if ($row) {
      $keep = $row->status === 'firing';
      $ok = $db->query($db->prepare(
        "UPDATE `{$this->table()}` SET status = 'pending', due_at = %s,
           attempts = IF(%d = 1, attempts, 0), last_error = IF(%d = 1, last_error, NULL), claim_token = NULL,
           locked_until = NULL, hook = %s, args = %s, as_action_id = %d, kind = %s, process_id = %d, step_index = %d,
           expected_status = %s, updated_at = %s
         WHERE id = %d",
        self::utc($i->dueAt), (int) $keep, (int) $keep, $hook, (string) wp_json_encode($args), $actionId, $i->kind->value, (int) $i->processId,
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
      throw new \RuntimeException("Wakeup intent {$i->idempotencyKey} was not stored in {$this->table()}: " . (string) $db->last_error);
    }
  }

  public function cancel(string $idempotencyKey): void {
    $this->requireTransaction('cancel');
    $db = self::db();
    $row = $db->get_row($db->prepare("SELECT id, status, hook, args FROM `{$this->table()}` WHERE idempotency_key = %s", $idempotencyKey));
    if (!$row || !in_array($row->status, ['pending', 'firing', 'exhausted'], true)) {
      return;
    }
    $this->write($db->prepare(
      "UPDATE `{$this->table()}` SET status = 'cancelled', claim_token = NULL, locked_until = NULL, updated_at = %s WHERE id = %d",
      $this->stamp(),
      (int) $row->id
    ), "cancel($idempotencyKey)");
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
      if ($db->last_error !== '') { // wpdb resets it per query; get_results() returns [] on an error
        throw new \RuntimeException("claimDue on {$this->table()} failed: {$db->last_error}");
      }
      $claimed = [];
      foreach (is_array($rows) ? $rows : [] as $row) {
        $token = bin2hex(random_bytes(16));
        // A lease that was not written is not a claim: throw, the
        // transaction rolls back and nothing is handed out.
        $this->write($db->prepare(
          "UPDATE `{$this->table()}` SET claim_token = %s, locked_until = %s WHERE id = %d",
          $token, $until, (int) $row->id
        ), 'claimDue lease');
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
    $this->write($db->prepare(
      "UPDATE `{$this->table()}` SET status = 'firing', updated_at = %s
       WHERE kind = %s AND process_id = %d AND status = 'pending'$step",
      $this->stamp(), $kind->value, $processId
    ), 'begin');
  }

  /**
   * The wake returned ($error null: done, and the keys it re-armed start a
   * fresh budget) or threw ($error: one attempt spent, back to pending
   * after the backoff, or exhausted at the budget; $terminal: the failure
   * can never succeed, the row is cancelled). Only rows still `firing`
   * change their status: a key the wake re-armed stays pending.
   */
  public function finish(WakeKind $kind, int $processId, ?int $stepIndex, ?string $error, bool $terminal = false): void {
    $db = self::db();
    $step = $stepIndex === null ? '' : ' AND step_index = ' . (int) $stepIndex;
    $match = $db->prepare("kind = %s AND process_id = %d", $kind->value, $processId) . $step;
    if ($error === null) {
      $this->write(
        "UPDATE `{$this->table()}` SET " . $db->prepare("status = 'done', updated_at = %s", $this->stamp()) . " WHERE $match AND status = 'firing'",
        'finish'
      );
      $this->write(
        "UPDATE `{$this->table()}` SET attempts = 0, last_error = NULL WHERE $match AND status = 'pending' AND attempts > 0",
        'finish (re-armed keys)'
      );
      return;
    }
    $this->fail("$match AND status = 'firing'", $error, $terminal, 'finish');
  }

  /**
   * Relay tick (5.3 step 3, wp form): re-project every due `pending` intent
   * whose Action Scheduler action is gone (failed, deleted, lost), and
   * count a `firing` row whose wake died long ago as a failed attempt.
   * Never re-projects while a pending or running action exists for the
   * same hook and args. Exhausted rows are left alone.
   *
   * $ignoreBackoff (the pre-rollback drain): also re-project pending
   * intents whose retry backoff is not due yet, future-dated to their
   * due_at, so a wake that just failed (its action is gone) is still on its
   * legacy hook when a 0.6 winner takes over.
   *
   * @return int intents re-projected
   */
  public function reproject(\DateTimeImmutable $now, int $limit = 100, bool $ignoreBackoff = false): int {
    $db = self::db();
    $at = self::utc($now);
    $this->fail(
      $db->prepare("status = 'firing' AND updated_at <= %s", self::utc($now->modify('-' . self::FIRING_STALE_SECONDS . ' seconds'))),
      'wake died while firing',
      false,
      'reproject (stale firing)',
      $now
    );

    $rows = $db->get_results($db->prepare(
      "SELECT * FROM `{$this->table()}` WHERE status = 'pending' AND (%d = 1 OR due_at <= %s) AND hook IS NOT NULL ORDER BY due_at ASC, id ASC LIMIT %d",
      (int) $ignoreBackoff, $at, max(0, $limit)
    ));
    if ($db->last_error !== '') {
      throw new \RuntimeException("reproject on {$this->table()} failed: {$db->last_error}");
    }
    $n = 0;
    foreach (is_array($rows) ? $rows : [] as $row) {
      $args = json_decode((string) $row->args, true);
      $args = is_array($args) ? $args : [];
      if (as_has_scheduled_action((string) $row->hook, $args, $this->group())) {
        continue;
      }
      $intent = $this->intent($row);
      $actionId = $this->project($intent, (string) $row->hook, $args);
      $this->write(
        $db->prepare("UPDATE `{$this->table()}` SET as_action_id = %d, updated_at = %s WHERE id = %d", $actionId, $this->stamp(), (int) $row->id),
        'reproject'
      );
      $n++;
    }
    return $n;
  }

  /**
   * Pending intents with no pending or running Action Scheduler action on
   * their hook (what a 0.6 winner would never fire); the pre-rollback drain
   * counts them as remaining.
   */
  public function unprojected(int $limit = 1000): int {
    $db = self::db();
    $rows = $db->get_results($db->prepare(
      "SELECT hook, args FROM `{$this->table()}` WHERE status = 'pending' AND hook IS NOT NULL ORDER BY id ASC LIMIT %d",
      max(0, $limit)
    ));
    if ($db->last_error !== '') {
      throw new \RuntimeException("unprojected on {$this->table()} failed: {$db->last_error}");
    }
    $n = 0;
    foreach (is_array($rows) ? $rows : [] as $row) {
      $args = json_decode((string) $row->args, true);
      if (!as_has_scheduled_action((string) $row->hook, is_array($args) ? $args : [], $this->group())) {
        $n++;
      }
    }
    return $n;
  }

  /**
   * Operator repair (`wp ddd ops --rearm=<key>`): an `exhausted` intent
   * becomes pending with a fresh budget, due now, and is projected again.
   *
   * @return bool false when the key is not exhausted
   */
  public function rearm(string $idempotencyKey): bool {
    return (new WpdbTransactionBoundary())->run(function () use ($idempotencyKey): bool {
      $db = self::db();
      $row = $db->get_row($db->prepare(
        "SELECT * FROM `{$this->table()}` WHERE idempotency_key = %s AND status = 'exhausted' FOR UPDATE",
        $idempotencyKey
      ));
      if (!$row) {
        return false;
      }
      $now = $this->clock()->now();
      $row->due_at = self::utc($now);
      $args = json_decode((string) $row->args, true);
      $actionId = $this->project($this->intent($row), (string) $row->hook, is_array($args) ? $args : []);
      $this->write($db->prepare(
        "UPDATE `{$this->table()}` SET status = 'pending', attempts = 0, due_at = %s, as_action_id = %d, claim_token = NULL, locked_until = NULL, updated_at = %s WHERE id = %d",
        self::utc($now), $actionId, self::utc($now), (int) $row->id
      ), "rearm($idempotencyKey)");
      return true;
    });
  }

  /** begin() for one intent by key (the ResumeRetry hook); null when it is not pending. */
  public function beginKey(string $idempotencyKey): ?WakeupIntent {
    $db = self::db();
    $n = $this->write($db->prepare(
      "UPDATE `{$this->table()}` SET status = 'firing', updated_at = %s WHERE idempotency_key = %s AND status = 'pending'",
      $this->stamp(), $idempotencyKey
    ), 'beginKey');
    if ($n !== 1) {
      return null;
    }
    $row = $db->get_row($db->prepare("SELECT * FROM `{$this->table()}` WHERE idempotency_key = %s", $idempotencyKey));
    return $row ? $this->intent($row) : null;
  }

  /** finish() for one intent by key. */
  public function finishKey(string $idempotencyKey, ?string $error, bool $terminal = false): void {
    $db = self::db();
    $match = $db->prepare('idempotency_key = %s', $idempotencyKey);
    if ($error === null) {
      $this->write(
        "UPDATE `{$this->table()}` SET " . $db->prepare("status = 'done', updated_at = %s", $this->stamp()) . " WHERE $match AND status = 'firing'",
        'finishKey'
      );
      $this->write(
        "UPDATE `{$this->table()}` SET attempts = 0, last_error = NULL WHERE $match AND status = 'pending' AND attempts > 0",
        'finishKey (re-armed key)'
      );
      return;
    }
    $this->fail("$match AND status = 'firing'", $error, $terminal, 'finishKey');
  }

  /** The live (pending or firing) intents of a process. */
  public function hasLiveIntent(int $processId): bool {
    $db = self::db();
    return (bool) $db->get_var($db->prepare(
      "SELECT 1 FROM `{$this->table()}` WHERE process_id = %d AND status IN ('pending', 'firing') LIMIT 1",
      $processId
    ));
  }

  /** Whether a wake of the process exhausted its budget (the operator re-arms it). */
  public function hasExhaustedIntent(int $processId): bool {
    $db = self::db();
    return (bool) $db->get_var($db->prepare(
      "SELECT 1 FROM `{$this->table()}` WHERE process_id = %d AND status = 'exhausted' LIMIT 1",
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

  /**
   * One failed attempt for the rows matching $where (raw SQL condition).
   * MySQL evaluates single-table SET assignments left to right with the
   * updated values, so status and due_at read the attempts BEFORE the
   * increment.
   */
  private function fail(string $where, string $error, bool $terminal, string $what, ?\DateTimeImmutable $now = null): void {
    $db = self::db();
    $now ??= $this->clock()->now();
    $stamp = self::utc($now);
    $budget = self::WAKE_BUDGET;
    $base = self::BACKOFF_BASE_SECONDS;
    $cap = self::BACKOFF_CAP_SECONDS;

    $exhausting = $terminal ? [] : $db->get_col("SELECT idempotency_key FROM `{$this->table()}` WHERE $where AND attempts + 1 >= $budget");

    // $where is already prepared: it is appended, never prepared twice.
    $set = $db->prepare(
      "status = IF(%d = 1, 'cancelled', IF(attempts + 1 >= $budget, 'exhausted', 'pending')),
       due_at = IF(%d = 1 OR attempts + 1 >= $budget, due_at, DATE_ADD(%s, INTERVAL LEAST($cap, $base << LEAST(attempts, 16)) SECOND)),
       attempts = attempts + 1,
       last_error = %s, claim_token = NULL, locked_until = NULL, updated_at = %s",
      (int) $terminal, (int) $terminal, $stamp, $error, $stamp
    );
    $this->write("UPDATE `{$this->table()}` SET $set WHERE $where", $what);

    foreach (is_array($exhausting) ? $exhausting : [] as $key) {
      Log::write(null, sprintf(
        '[%s-process] wakeup %s exhausted its budget (%d attempts): %s. Re-arm it with `wp ddd ops --rearm=%s --consumer=%s`.',
        $this->config->prefix(), $key, $budget, $error, $key, $this->config->prefix()
      ), 'error');
    }
  }

  /** @return int rows affected; throws when the query failed */
  private function write(string $sql, string $what): int {
    $db = self::db();
    $n = $db->query($sql);
    if ($n === false) {
      throw new \RuntimeException("Wakeup $what on {$this->table()} failed: " . (string) $db->last_error);
    }
    return (int) $n;
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
