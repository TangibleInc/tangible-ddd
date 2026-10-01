<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use TangibleDDD\Application\Infrastructure\IInfrastructureEvent;
use TangibleDDD\Infra\IConsumerIdentity;
use TangibleDDD\Runtime\IInfrastructureSignalDispatcher;

/**
 * The sf IInfrastructureSignalDispatcher (register 3.9; wave-2 carry-over),
 * provided to HostDefaults at bundle boot: every signal is one PSR-3 log
 * line (warning, with the signal in the context) and, when an event
 * dispatcher exists, a DddSignal event. Signals are never silent, and reach
 * error_log only in an app with no logger service. A throwing listener is
 * caught and logged: signals never
 * break the code that emits them (OutboxProcessor, the audit bracket).
 */
final class SymfonySignalDispatcher implements IInfrastructureSignalDispatcher {

  /** @param ?LoggerInterface $logger null only in an app without a logger: then error_log, never silent */
  public function __construct(
    private readonly ?LoggerInterface $logger,
    private readonly ?EventDispatcherInterface $events = null,
  ) {}

  public function emit(IInfrastructureEvent $e, IConsumerIdentity $c): void {
    $this->log('warning', sprintf(
      '[ddd signal] %s_%s (%s) correlation=%s causation=%s:%s',
      $c->prefix(), $e::action(), get_class($e),
      $e->correlation_id() ?? '-', $e->causation_type() ?? '-', $e->causation_id() ?? '-'
    ), ['signal' => $e, 'consumer' => $c->prefix()]);

    if ($this->events === null) {
      return;
    }
    try {
      $this->events->dispatch(new DddSignal($e, $c));
    } catch (\Throwable $t) {
      $this->log('error', sprintf('[ddd signal] a DddSignal listener failed on %s (ignored): %s', $e::action(), $t->getMessage()), ['exception' => $t]);
    }
  }

  /** @param array<string, mixed> $context */
  private function log(string $level, string $message, array $context): void {
    if ($this->logger !== null) {
      $this->logger->log($level, $message, $context);
      return;
    }
    error_log($message);
  }
}
