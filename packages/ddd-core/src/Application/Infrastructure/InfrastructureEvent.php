<?php

namespace TangibleDDD\Application\Infrastructure;

use TangibleDDD\Infra\IConsumerIdentity;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IInfrastructureSignalDispatcher;
use TangibleDDD\Runtime\LoggingSignalDispatcher;
use TangibleDDD\Runtime\Support\Log;

/**
 * Base for infrastructure events. Carries the subject + trace context and
 * knows how to dispatch itself out-of-band.
 *
 * dispatch() routes to the host's IInfrastructureSignalDispatcher
 * (register 1.4, 3.9), resolved from HostDefaults per call. ddd-wp provides
 * one that fires the two legacy WordPress actions: the per-consumer
 * `{prefix}_{action}` a consumer hooks for its own reactions, and the global
 * `tangible_ddd_{action}` a cross-consumer monitor hooks (with the prefix as
 * a second argument). With no host dispatcher the signal is logged through
 * PSR-3 (LoggingSignalDispatcher), so it is never silent; before wave 2 it
 * vanished outside WordPress.
 *
 * Error behaviour: never throws. A dispatcher failure is caught and logged,
 * because the machinery emitting the signal (relay, runner, workflow) may be
 * the thing that already failed.
 */
abstract class InfrastructureEvent implements IInfrastructureEvent {

  public function __construct(
    protected readonly mixed $subject,
    protected readonly ?string $correlation_id = null,
    protected readonly ?string $causation_id = null,
    protected readonly ?string $causation_type = null,
  ) {}

  public function subject(): mixed {
    return $this->subject;
  }

  public function correlation_id(): ?string {
    return $this->correlation_id;
  }

  public function causation_id(): ?string {
    return $this->causation_id;
  }

  public function causation_type(): ?string {
    return $this->causation_type;
  }

  /**
   * Emit this signal for $config's consumer. Takes the portable identity
   * (widened from IDDDConfig; every 0.6 caller passes an IDDDConfig).
   */
  public function dispatch(IConsumerIdentity $config): void {
    $dispatcher = HostDefaults::get(IInfrastructureSignalDispatcher::class) ?? new LoggingSignalDispatcher();

    try {
      $dispatcher->emit($this, $config);
    } catch (\Throwable $e) {
      Log::write(null, sprintf(
        '[ddd signal] dispatcher %s failed on %s_%s: %s',
        get_class($dispatcher),
        $config->prefix(),
        static::action(),
        $e->getMessage()
      ), 'error');
    }
  }
}
