<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TangibleDDD\Symfony\Runtime\Relay;

/**
 * `ddd:relay`: moves due outbox rows to the `ddd_facts` Messenger transport.
 *
 *   ddd:relay --once              one relay step, then exit
 *   ddd:relay --limit=100         rows per step (default tangible_ddd.relay.batch_size)
 *   ddd:relay --time-limit=3600   stop after N seconds (supervisor restarts it)
 *   ddd:relay --sleep=1           idle poll interval when a step claimed nothing
 *
 * Run it on a DIRECT (non-pooled) Postgres connection. SIGTERM / SIGINT stop
 * the loop after the current step. Round 3 swaps the step for the core relay
 * (CONF-3) and adds the LISTEN wakeup (D14); the options stay.
 */
#[AsCommand(name: 'ddd:relay', description: 'Relay due outbox facts to the Messenger ddd_facts transport')]
final class RelayCommand extends Command implements SignalableCommandInterface {

  private bool $stop = false;

  public function __construct(private readonly Relay $relay, private readonly int $defaultLimit, private readonly int $defaultSleep) {
    parent::__construct();
  }

  protected function configure(): void {
    $this
      ->addOption('once', null, InputOption::VALUE_NONE, 'Run one relay step and exit')
      ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Rows per relay step', (string) $this->defaultLimit)
      ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many seconds')
      ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Seconds to wait when a step found nothing', (string) $this->defaultSleep);
  }

  public function getSubscribedSignals(): array {
    return \defined('SIGTERM') ? [\SIGTERM, \SIGINT] : [];
  }

  public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false {
    $this->stop = true;
    return false;
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $limit = max(1, (int) $input->getOption('limit'));
    $sleep = max(0, (int) $input->getOption('sleep'));
    $timeLimit = $input->getOption('time-limit');
    $deadline = $timeLimit === null ? null : microtime(true) + (float) $timeLimit;
    $once = (bool) $input->getOption('once');

    $totals = ['claimed' => 0, 'accepted' => 0, 'retried' => 0, 'dlq' => 0, 'lost' => 0];
    do {
      $report = $this->relay->runOnce($limit);
      $totals['claimed'] += count($report->claimed);
      $totals['accepted'] += count($report->accepted);
      $totals['retried'] += count($report->retried);
      $totals['dlq'] += count($report->deadLettered);
      $totals['lost'] += count($report->lost);
      if ($output->isVerbose() && $report->claimed !== []) {
        $output->writeln(sprintf('claimed %d, accepted %d, retried %d, dead-lettered %d, lease lost %d',
          count($report->claimed), count($report->accepted), count($report->retried), count($report->deadLettered), count($report->lost)));
      }
      if ($once || $this->stop || ($deadline !== null && microtime(true) >= $deadline)) {
        break;
      }
      if ($report->claimed === [] && $sleep > 0) {
        sleep($sleep);
      }
    } while (!$this->stop && ($deadline === null || microtime(true) < $deadline));

    $output->writeln(sprintf('ddd:relay: claimed %d, accepted %d, retried %d, dead-lettered %d, lease lost %d',
      $totals['claimed'], $totals['accepted'], $totals['retried'], $totals['dlq'], $totals['lost']));

    return Command::SUCCESS;
  }
}
