<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Console;

use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TangibleDDD\Symfony\Runtime\Relay;
use TangibleDDD\Symfony\Runtime\Wakeup\IRelayWaiter;
use TangibleDDD\Symfony\Runtime\Wakeup\IWakeupRelayStep;

/**
 * `ddd:relay`: moves due outbox rows to the `ddd_facts` Messenger transport.
 *
 *   ddd:relay --once              one relay step, then exit
 *   ddd:relay --limit=100         rows per step (default tangible_ddd.relay.batch_size)
 *   ddd:relay --time-limit=3600   stop after N seconds (supervisor restarts it)
 *   ddd:relay --sleep=1           idle poll interval when a step claimed nothing
 *
 *   ddd:relay --consumer=billing  only that consumer (wave 5; default: every consumer, in turn)
 *
 * Run it on a DIRECT (non-pooled) Postgres connection. SIGTERM / SIGINT stop
 * the loop after the current step. Each step is Relay::run_once(), i.e. the
 * core relay step (OutboxProcessor port form, CONF-3), followed by the
 * wakeup relay step when one is wired (due wakeup intents → `ddd_wakeups`,
 * plus the throttled stranded scan, register 5.3); this command owns only
 * the loop. When neither step found work it waits: on the LISTEN waiter
 * when one is wired (D14: a commit that pokes the relay wakes it at once,
 * --sleep is the poll fallback), else a plain sleep.
 *
 * Storage errors: a DBAL exception from a step (connection lost, failover,
 * lock timeout) is logged and the loop backs off 1, 2, 4 ... 30 s and tries
 * again (DBAL reconnects lazily on the next statement). After
 * $maxConsecutiveFailures failed steps in a row it exits non-zero and the
 * supervisor is the recovery path. With --once a storage error exits
 * non-zero straight away. Anything that is not a DBAL exception is a bug and
 * propagates.
 */
#[AsCommand(name: 'ddd:relay', description: 'Relay due outbox facts to the Messenger ddd_facts transport')]
final class RelayCommand extends Command implements SignalableCommandInterface {

  private const MAX_BACKOFF_SECONDS = 30;

  private bool $stop = false;

  private readonly LoggerInterface $logger;

  /** @var \Closure(int): void */
  private readonly \Closure $sleeper;

  /**
   * @param array<string, array{0: Relay, 1: ?IWakeupRelayStep}> $lanes several consumers (wave 5): name → its relay
   *   and wakeup step, primary first; empty = the one consumer of $relay / $wakeups
   */
  public function __construct(
    private readonly Relay $relay,
    private readonly int $defaultLimit,
    private readonly int $defaultSleep,
    ?LoggerInterface $logger = null,
    ?callable $sleeper = null,
    private readonly int $maxConsecutiveFailures = 10,
    private readonly ?IWakeupRelayStep $wakeups = null,
    private readonly ?IRelayWaiter $waiter = null,
    private readonly array $lanes = [],
  ) {
    $this->logger = $logger ?? new NullLogger();
    $this->sleeper = $sleeper === null ? static function (int $s): void { sleep($s); } : \Closure::fromCallable($sleeper);
    parent::__construct();
  }

  protected function configure(): void {
    $this
      ->addOption('once', null, InputOption::VALUE_NONE, 'Run one relay step and exit')
      ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Rows per relay step', (string) $this->defaultLimit)
      ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many seconds')
      ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Seconds to wait when a step found nothing', (string) $this->defaultSleep)
      ->addOption('consumer', null, InputOption::VALUE_REQUIRED, 'Relay only this consumer (its name in tangible_ddd.consumers); default: every consumer');
  }

  /** @return array<string, array{0: Relay, 1: ?IWakeupRelayStep}>|null the lanes to run; null for an unknown consumer */
  private function lanes(?string $consumer): ?array {
    $lanes = $this->lanes === [] ? ['' => [$this->relay, $this->wakeups]] : $this->lanes;
    if ($consumer === null) {
      return $lanes;
    }
    if ($this->lanes === []) {
      return $lanes; // one consumer: --consumer can only name it
    }
    return isset($lanes[$consumer]) ? [$consumer => $lanes[$consumer]] : null;
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
    $consumer = $input->getOption('consumer');
    $lanes = $this->lanes($consumer === null ? null : (string) $consumer);
    if ($lanes === null) {
      $output->writeln(sprintf('<error>ddd:relay: unknown consumer "%s"; one of %s</error>', $consumer, implode(', ', array_keys($this->lanes))));
      return Command::FAILURE;
    }

    $totals = ['claimed' => 0, 'accepted' => 0, 'retried' => 0, 'dlq' => 0, 'lost' => 0, 'wakeups' => 0, 'requeued' => 0];
    $failures = 0;
    $exit = Command::SUCCESS;
    do {
      try {
        $reports = [];
        foreach ($lanes as [$relay, $wakeupStep]) {
          $reports[] = [$relay->run_once($limit), $wakeupStep?->run_once($limit)];
        }
        $failures = 0;
      } catch (DbalException $e) {
        $failures++;
        $this->logger->error(sprintf('[ddd relay] relay step failed (%d in a row): %s', $failures, $e->getMessage()), ['exception' => $e]);
        $output->writeln(sprintf('<error>ddd:relay: relay step failed: %s</error>', $e->getMessage()));
        if ($once) {
          $exit = Command::FAILURE;
          break;
        }
        if ($failures >= $this->maxConsecutiveFailures) {
          $output->writeln(sprintf('<error>ddd:relay: giving up after %d consecutive failed steps</error>', $failures));
          $exit = Command::FAILURE;
          break;
        }
        if ($this->stop || ($deadline !== null && microtime(true) >= $deadline)) {
          break;
        }
        ($this->sleeper)(min(self::MAX_BACKOFF_SECONDS, 2 ** ($failures - 1)));
        continue;
      }

      $idle = true;
      foreach ($reports as [$report, $wakeups]) {
        $totals['claimed'] += count($report->claimed);
        $totals['accepted'] += count($report->accepted);
        $totals['retried'] += count($report->retried);
        $totals['dlq'] += count($report->dead_lettered);
        $totals['lost'] += count($report->lost);
        $totals['wakeups'] += count($wakeups?->projected ?? []);
        $totals['requeued'] += count($wakeups?->requeued ?? []);
        if ($output->isVerbose() && ($report->claimed !== [] || $wakeups?->did_work())) {
          $output->writeln(sprintf('claimed %d, accepted %d, retried %d, dead-lettered %d, lease lost %d, wakeups projected %d',
            count($report->claimed), count($report->accepted), count($report->retried), count($report->dead_lettered), count($report->lost),
            count($wakeups?->projected ?? [])));
        }
        $idle = $idle && $report->claimed === [] && !($wakeups?->did_work() ?? false);
      }
      if ($once || $this->stop || ($deadline !== null && microtime(true) >= $deadline)) {
        break;
      }
      if ($idle && $sleep > 0) {
        if ($this->waiter !== null) {
          $this->waiter->wait((float) $sleep);
        } else {
          ($this->sleeper)($sleep);
        }
      }
    } while (!$this->stop && ($deadline === null || microtime(true) < $deadline));

    $output->writeln(sprintf('ddd:relay: claimed %d, accepted %d, retried %d, dead-lettered %d, lease lost %d, wakeups projected %d, stranded re-queued %d',
      $totals['claimed'], $totals['accepted'], $totals['retried'], $totals['dlq'], $totals['lost'], $totals['wakeups'], $totals['requeued']));

    return $exit;
  }
}
