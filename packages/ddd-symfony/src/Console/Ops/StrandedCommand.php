<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Console\Ops;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Lock\LockKey;
use TangibleDDD\Runtime\Lock\LockNotAcquired;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Symfony\Ops\CoreStrandedRepairs;
use TangibleDDD\Symfony\Persistence\DbalWakeupScheduler;

/**
 * `ddd:ops:stranded`: layers `process` and `wakeup` of the operator view
 * (register 3.10, 5.1, 5.3 step 5).
 *
 * Without options it lists stranded processes (running/scheduled with no
 * live intent past the threshold) and exhausted wakeup intents. Repairs:
 *
 *   --resume=<process id>   mint a Continue intent at the process's current
 *                           step (ResumeStrandedProcess). For a `running`
 *                           row this re-runs the current step: its effects
 *                           must be idempotent (deterministic command ids,
 *                           D1 journal), which is why the relay never does
 *                           it automatically.
 *   --fail=<process id>     mark the process `failed` with --reason
 *                           (FailStrandedProcess), under its process lock
 *                           and a version-fenced save; refused while a wake
 *                           holds the lock.
 *   --rearm=<intent key>    put an exhausted wakeup intent back in line with
 *                           a fresh budget.
 *
 * Each option may repeat. The command fails if any repair failed.
 *
 * --resume / --fail dispatch core's ResumeStrandedProcess /
 * FailStrandedProcess through the command bus once core ships them
 * (CoreStrandedRepairs, WP8-10); until then they repair inline as above.
 */
#[AsCommand(name: 'ddd:ops:stranded', description: 'List stranded processes and exhausted wakeups; resume, fail or re-arm them')]
final class StrandedCommand extends Command {

  public function __construct(
    private readonly IProcessStore $processes,
    private readonly DbalWakeupScheduler $wakeups,
    private readonly ITransactionBoundary $boundary,
    private readonly IProcessLock $lock,
    private readonly IClock $clock,
    private readonly string $consumer,
    private readonly float $lockTimeoutSeconds = 1.0,
    private readonly ?CoreStrandedRepairs $repairs = null,
  ) {
    parent::__construct();
  }

  protected function configure(): void {
    $this
      ->addOption('resume', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Mint a Continue intent for this process id')
      ->addOption('fail', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Mark this process id failed')
      ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Failure reason for --fail', 'failed by operator (ddd:ops:stranded)')
      ->addOption('rearm', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Re-arm this exhausted intent key')
      ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Rows to list', '100');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $resume = (array) $input->getOption('resume');
    $fail = (array) $input->getOption('fail');
    $rearm = (array) $input->getOption('rearm');

    if ($resume === [] && $fail === [] && $rearm === []) {
      $this->list($output, max(1, (int) $input->getOption('limit')));
      return Command::SUCCESS;
    }

    $ok = true;
    foreach ($rearm as $key) {
      $ok = $this->attempt($output, "re-arm $key", fn () => $this->wakeups->rearm((string) $key, $this->clock->now())
        ?: throw new \RuntimeException('no such intent')) && $ok;
    }
    foreach ($resume as $id) {
      $ok = $this->attempt($output, "resume process #$id", fn () => $this->resume((int) $id)) && $ok;
    }
    foreach ($fail as $id) {
      $ok = $this->attempt($output, "fail process #$id", fn () => $this->fail((int) $id, (string) $input->getOption('reason'))) && $ok;
    }
    return $ok ? Command::SUCCESS : Command::FAILURE;
  }

  private function list(OutputInterface $output, int $limit): void {
    $stranded = array_slice($this->processes->find_stranded($this->clock->now()), 0, $limit);
    if ($stranded === []) {
      $output->writeln('No stranded processes.');
    } else {
      $output->writeln('Stranded processes (no live wakeup intent):');
      $table = new Table($output);
      $table->setHeaders(['process id', 'class', 'status', 'step', 'updated at (UTC)']);
      foreach ($stranded as $s) {
        $table->addRow([$s->process_id, $s->process_class, $s->status, $s->step_index, $s->updated_at->format('Y-m-d H:i:s')]);
      }
      $table->render();
    }

    $exhausted = $this->wakeups->exhausted($limit);
    if ($exhausted === []) {
      $output->writeln('No exhausted wakeup intents.');
    } else {
      $output->writeln('Exhausted wakeup intents:');
      $table = new Table($output);
      $table->setHeaders(['intent key', 'kind', 'process id', 'attempts', 'exhausted at (UTC)', 'last error']);
      foreach ($exhausted as $e) {
        $table->addRow([
          $e['intent']->key, $e['intent']->kind->value, $e['intent']->process_id ?? '-', $e['attempts'],
          $e['exhausted_at']->format('Y-m-d H:i:s'), mb_strimwidth((string) $e['last_error'], 0, 120, '...'),
        ]);
      }
      $table->render();
    }
    $output->writeln('Repairs: --resume=<process id>, --fail=<process id> [--reason=...], --rearm=<intent key>');
  }

  private function resume(int $id): void {
    if ($this->repairs?->available()) {
      $this->repairs->resume($id);
      return;
    }
    $process = $this->processes->find($id) ?? throw new \RuntimeException('no such process');
    if (in_array($process->status(), ['completed', 'failed'], true)) {
      throw new \RuntimeException("process is {$process->status()}; nothing to resume");
    }
    $step = $process->current_step_index();
    $intent = new WakeupIntent(WakeKind::Continue, $this->consumer, $id, $step, $process->status(), $this->clock->now(), "continue:$id:$step");
    $this->boundary->run(function () use ($intent): void {
      $this->wakeups->cancel($intent->key); // an exhausted intent with the same key is replaced
      $this->wakeups->schedule($intent);
    });
  }

  private function fail(int $id, string $reason): void {
    if ($this->repairs?->available()) {
      $this->repairs->fail($id, $reason);
      return;
    }
    try {
      $handle = $this->lock->acquire(new LockKey($this->consumer, '', $id), $this->lockTimeoutSeconds);
    } catch (LockNotAcquired $e) {
      throw new \RuntimeException('its process lock is held (a wake is running?): ' . $e->getMessage(), 0, $e);
    }
    try {
      $process = $this->processes->find($id) ?? throw new \RuntimeException('no such process');
      $version = $this->processes->version_of($id) ?? throw new \RuntimeException('no such process');
      if (in_array($process->status(), ['completed', 'failed'], true)) {
        throw new \RuntimeException("process is already {$process->status()}");
      }
      $process->fail($reason);
      $this->boundary->run(fn () => $this->processes->save($process, $version));
    } finally {
      $this->lock->release($handle);
    }
  }

  private function attempt(OutputInterface $output, string $what, callable $repair): bool {
    try {
      $repair();
      $output->writeln("Done: $what.");
      return true;
    } catch (\Throwable $e) {
      $output->writeln(sprintf('<error>Could not %s: %s</error>', $what, $e->getMessage()));
      return false;
    }
  }
}
