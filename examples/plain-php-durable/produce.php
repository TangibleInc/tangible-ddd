<?php

/**
 * The web request of a raw-PHP host:
 *
 *   php examples/plain-php-durable/produce.php [--reset] [--no-activation]
 *
 * 1. Applies schema/mysql8 (host tooling; idempotent). --reset drops the
 *    example database first.
 * 2. Sends StartTrial through the composed command bus: the trial row, the
 *    TrialProcess row (`scheduled`) and its Continue intent commit in ONE
 *    transaction on the host's connection.
 * 3. Runs one bounded drain pass, as a raw-PHP host does from a shutdown
 *    function after the response: the first step runs now, on the real
 *    clock. It persists the await and a timeout intent due in
 *    TrialProcess::TIMEOUT_SECONDS, then dispatches ActivateTrial, whose
 *    TrialActivated fact waits in the outbox for the next relay step.
 *    With --no-activation the step sends nothing, so only the timeout can
 *    end the wait.
 *
 * Exits 0 when the trial is waiting with its timeout scheduled.
 */

declare(strict_types=1);

namespace Example\PlainPhpDurable;

require __DIR__ . '/bootstrap.php';

$reset = in_array('--reset', $argv, true);
$activate = !in_array('--no-activation', $argv, true);

$db = connect($reset);
applySchema($db);
$runtime = runtime($db);
$checks = new Checks();

$trialId = 1 + (int) ($db->fetchOne('SELECT MAX(id) AS id FROM trialdemo_trials')['id'] ?? 0);
$processId = $runtime->bus()->handle(new StartTrial($trialId, $activate));
echo "trial $trialId: started process $processId" . ($activate ? '' : ' (no activation will be sent)') . "\n";

$trial = latestTrial($db);
$checks->check($trial['process_status'] === 'scheduled', 'the command committed the trial and its scheduled process together');
$checks->check(
  $db->fetchOne("SELECT 1 AS ok FROM trialdemo_ddd_jobs WHERE kind = 'continue' AND process_id = ?", [$processId]) !== null,
  'with a Continue intent: no step ran inside the request\'s transaction'
);

// The post-response drain pass (a shutdown function in a real host).
$report = $runtime->drain(maxItems: 50, maxSeconds: 10);
$checks->check($report->errors === [] && $report->leaks === [], 'the drain pass ran cleanly');

$trial = latestTrial($db);
$timeout = $db->fetchOne("SELECT idempotency_key, due_at FROM trialdemo_ddd_jobs WHERE kind = 'timeout' AND process_id = ?", [$processId]);
$checks->check($trial['process_status'] === 'suspended', 'the first step ran and suspended on the await');
$checks->check($timeout !== null, 'its timeout intent is scheduled (' . ($timeout['due_at'] ?? 'none') . ' UTC)');
$pendingFacts = (int) $db->fetchOne("SELECT COUNT(*) AS n FROM trialdemo_ddd_outbox WHERE status = 'pending'")['n'];
$checks->check($pendingFacts === ($activate ? 1 : 0), $activate ? 'TrialActivated waits in the outbox' : 'no fact was announced');

if ($timeout !== null) {
  // Host bookkeeping for drain.php's stale-timeout check.
  $db->execute('UPDATE trialdemo_trials SET timeout_key = ?, timeout_due_at = ? WHERE id = ?', [$timeout['idempotency_key'], $timeout['due_at'], $trialId]);
}

echo 'next: DDD_CLOCK_OFFSET=' . (TrialProcess::TIMEOUT_SECONDS * 2) . " php examples/plain-php-durable/drain.php\n";
$checks->finish();
