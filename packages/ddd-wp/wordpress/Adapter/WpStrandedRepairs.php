<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use Psr\Log\LoggerInterface;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Process\Repair\FailStrandedProcess;
use TangibleDDD\Application\Process\Repair\FailStrandedProcessHandler;
use TangibleDDD\Application\Process\Repair\ResumeStrandedProcess;
use TangibleDDD\Application\Process\Repair\ResumeStrandedProcessHandler;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\Persistence\ProcessRepository;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\Lock\ReentrantProcessLock;
use TangibleDDD\Runtime\NestedPolicy;
use TangibleDDD\Runtime\SystemClock;

/**
 * The stranded-process repairs on wp (WP8-10; register 3.10, 5.3 step 5):
 * `wp ddd ops --resume-stranded=<id>` / `--fail-stranded=<id>` dispatch
 * core's ResumeStrandedProcess / FailStrandedProcess to their core handlers
 * on the consumer's v8 ports:
 *
 *   IProcessStore     WpdbProcessStore (findStranded: running rows only while
 *                     both lock names are free)
 *   IWakeupScheduler  WpdbWakeupScheduler (a resume's ResumeRetry intent is
 *                     projected to `{prefix}_ddd_wakeup`, a Continue to the
 *                     legacy `{prefix}_process_continue`)
 *   IProcessLock      ReentrantProcessLock over GetLockProcessLock (both
 *                     names; the guard takes it with a zero wait)
 *   boundary          WpdbTransactionBoundary (Reject): the handler runs its
 *                     writes in one transaction under the lock
 *
 * A refusal (the lock is held, the row is not stranded, the version moved)
 * is core's ProcessNotStranded. The repairs only exist on schema v8.
 */
final class WpStrandedRepairs {

  public function __construct(
    private readonly IDDDConfig $config,
    private readonly ?IClock $clock = null,
  ) {}

  public function resume(int $processId, ?int $expectedVersion = null): void {
    $this->dispatch(new ResumeStrandedProcess($this->config->prefix(), $processId, $expectedVersion));
  }

  public function fail(int $processId, string $reason, bool $compensate = false, ?int $expectedVersion = null): void {
    $this->dispatch(new FailStrandedProcess($this->config->prefix(), $processId, $reason, $compensate, $expectedVersion));
  }

  public function dispatch(ICommand $command): void {
    if (!WpSchema::isV8($this->config)) {
      throw new \LogicException("Consumer '{$this->config->prefix()}' has no stranded repairs (schema v8 not installed).");
    }
    $clock = $this->clock ?? HostDefaults::get(IClock::class) ?? new SystemClock();
    $ports = [
      new WpdbProcessStore(new ProcessRepository($this->config), $this->config, $clock),
      new WpdbWakeupScheduler($this->config, $clock),
      new ReentrantProcessLock(new GetLockProcessLock(), HostDefaults::get(LoggerInterface::class)),
      $clock,
      new WpdbTransactionBoundary(NestedPolicy::Reject),
    ];
    $handler = match (true) {
      $command instanceof ResumeStrandedProcess => new ResumeStrandedProcessHandler(...$ports),
      $command instanceof FailStrandedProcess => new FailStrandedProcessHandler(...$ports),
      default => throw new \InvalidArgumentException('Not a stranded repair: ' . get_class($command)),
    };
    $handler->handle($command);
  }
}
