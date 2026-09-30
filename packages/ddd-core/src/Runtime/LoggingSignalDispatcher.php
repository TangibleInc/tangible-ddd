<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime;

use TangibleDDD\Application\Infrastructure\IInfrastructureEvent;
use TangibleDDD\Infra\IConsumerIdentity;
use TangibleDDD\Runtime\Support\Log;

/** Core default IInfrastructureSignalDispatcher: one log line per signal, never silent. */
final class LoggingSignalDispatcher implements IInfrastructureSignalDispatcher {

  /** @param (\Closure(string):void)|null $log */
  public function __construct(private readonly ?\Closure $log = null) {}

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
