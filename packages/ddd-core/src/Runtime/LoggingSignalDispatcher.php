<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime;

use TangibleDDD\Application\Infrastructure\IInfrastructureEvent;
use TangibleDDD\Infra\IConsumerIdentity;
use Psr\Log\LoggerInterface;
use TangibleDDD\Runtime\Support\Log;

/** Core default IInfrastructureSignalDispatcher: one log line per signal, never silent. */
final class LoggingSignalDispatcher implements IInfrastructureSignalDispatcher {

  /**
   * @param LoggerInterface|(\Closure(string):void)|null $log PSR-3 logger; the
   *   closure form is the deprecated wave-1 shape (CR-SP-1). Null: host logger,
   *   else error_log().
   */
  public function __construct(private readonly LoggerInterface|\Closure|null $log = null) {}

  public function emit(IInfrastructureEvent $e, IConsumerIdentity $c): void {
    Log::write($this->log, sprintf(
      '[ddd signal] %s_%s (%s) correlation=%s causation=%s:%s',
      $c->prefix(),
      $e::action(),
      get_class($e),
      $e->correlation_id() ?? '-',
      $e->causation_type() ?? '-',
      $e->causation_id() ?? '-'
    ));
  }
}
