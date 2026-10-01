<?php

/**
 * The cron line of a raw-PHP host: one bounded DurableRuntime::drain() pass
 * (relay step, deliveries, due wakeups, stranded scan), in a fresh `php`
 * process that shares nothing with produce.php but the database.
 *
 *   DDD_CLOCK_OFFSET=120 php examples/plain-php-durable/drain.php
 *
 * The offset moves this process's clock past the trial's 60 s timeout, so
 * the timeout intent is due in this pass as well:
 *
 * - First drain after produce.php: the relay hands TrialActivated to its
 *   deliver job, the delivery resumes the process (the fact arrived before
 *   the deadline, and deliveries run before wakeups), the process finishes
 *   as `activated`, and the satisfied await cancels its timeout intent in
 *   the same transaction. With produce.php --no-activation there is no
 *   fact: the due timeout fires instead and the trial finishes `timed_out`.
 * - Any later drain, once the process is completed: it plants a surviving
 *   copy of the old timeout intent (what a restored backup or a duplicated
 *   queue would leave behind), and the pass runs it as a stale-safe no-op:
 *   re-read under the lock, wrong state, nothing changes.
 *
 * Asserts every step and exits 0, or prints FAIL lines and exits 1. A drain
 * whose timeout is not due yet (no offset, --no-activation) reports that the
 * trial is still waiting and exits 0.
 */

declare(strict_types=1);

namespace Example\PlainPhpDurable;

require __DIR__ . '/bootstrap.php';

use TangibleDDD\Runtime\Scheduling\WakeupIntent;

$db = connect();
(new \TangibleDDD\Defaults\Pdo\SchemaCheck($db, TrialConsumer::PREFIX . '_'))->assert();
$runtime = runtime($db);
$checks = new Checks();
$now = (new \TangibleDDD\Testing\EnvOffsetClock())->now();

$before = latestTrial($db);
if ($before === null || $before['process_id'] === null) {
  fwrite(STDERR, "No trial yet: run produce.php first.\n");
  exit(1);
}
$processId = (int) $before['process_id'];
$alreadyCompleted = $before['process_status'] === 'completed';
$timeoutDue = $before['timeout_due_at'] === null ? null : new \DateTimeImmutable($before['timeout_due_at'], new \DateTimeZone('UTC'));
echo sprintf("trial %d, process %d: %s; clock %s UTC%s\n", $before['id'], $processId, $before['process_status'], $now->format('H:i:s'),
  $timeoutDue !== null && $timeoutDue <= $now ? ', its timeout is due' : '');

// ── the stale copy (only once the process is done) ─────────────────────────
$copy = null;
if ($alreadyCompleted && $before['timeout_key'] !== null) {
  [, , $step] = explode(':', (string) $before['timeout_key']);
  $copy = WakeupIntent::timeout(TrialConsumer::PREFIX, $processId, (int) $step, $timeoutDue);
  $runtime->boundary()->run(static fn () => $runtime->jobs()->schedule($copy));
  echo "planted a surviving copy of {$copy->key} (due {$before['timeout_due_at']})\n";
}

// ── one bounded pass ───────────────────────────────────────────────────────
$report = $runtime->drain(maxItems: 50, maxSeconds: 10);
echo sprintf("drain: relayed %d, delivered %d, wakes completed [%s], stopped by %s\n",
  count($report->relay?->accepted ?? []), $report->delivered, implode(', ', $report->wakes_completed), $report->stopped_by);

$after = latestTrial($db);
$checks->check($report->errors === [] && $report->leaks === [], 'the pass ran cleanly (no stage error, nothing leaked)');

if ($after['process_status'] !== 'completed') {
  $waiting = $after['process_status'] === 'suspended' && ($timeoutDue === null || $timeoutDue > $now) && $before['status'] !== 'active';
  $checks->check($waiting, 'the trial is still waiting for its timeout at ' . ($before['timeout_due_at'] ?? '?') . ' UTC (set DDD_CLOCK_OFFSET past it)');
  $checks->finish();
}

$expected = $after['status'] === 'active' ? 'activated' : 'timed_out';
$checks->check(true, 'the process is completed');
$checks->check($after['outcome'] === $expected, "the trial finished as $expected");
$checks->check((int) $after['finished_count'] === 1, 'FinishTrial ran exactly once');
$checks->check(
  $db->fetch_one('SELECT 1 AS live FROM trialdemo_ddd_jobs WHERE process_id = ?', [$processId]) === null,
  'no intent of the process is left'
);
$checks->check((int) $db->fetch_one("SELECT COUNT(*) AS n FROM trialdemo_ddd_outbox WHERE status = 'pending'")['n'] === 0, 'no fact is waiting');
$checks->check($runtime->operator_view()->list() === [], 'the operator view is empty');

if ($copy !== null) {
  $checks->check(in_array($copy->key, $report->wakes_completed, true), 'the stale timeout copy was claimed and completed');
  $checks->check(
    [$after['process_version'], $after['process_updated_at'], $after['outcome']] === [$before['process_version'], $before['process_updated_at'], $before['outcome']],
    'as a no-op: the process row and the outcome are untouched'
  );
} elseif (!$alreadyCompleted) {
  $checks->check(
    $expected === 'activated' ? !in_array($before['timeout_key'], $report->wakes_completed, true) : in_array($before['timeout_key'], $report->wakes_completed, true),
    $expected === 'activated' ? 'the fact won: the timeout was cancelled with the resuming save' : 'the timeout fired and the process proceeded'
  );
}

$checks->finish();
