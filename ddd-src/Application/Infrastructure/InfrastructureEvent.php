<?php

namespace TangibleDDD\Application\Infrastructure;

use TangibleDDD\Infra\IDDDConfig;

/**
 * Base for infrastructure events. Carries the subject + trace context and
 * knows how to dispatch itself out-of-band.
 *
 * dispatch() fires two WordPress actions — the per-consumer one a consumer
 * hooks for its own reactions, and a global one a cross-consumer monitor hooks
 * (carrying the prefix so it knows the source). It is guarded so it is a no-op
 * outside WordPress (unit tests / CLI without a hook system), the same pattern
 * the rest of the framework uses around do_action.
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
   * Fire this event as WordPress actions: {prefix}_{action} (per-consumer) and
   * tangible_ddd_{action} (global, with the prefix as a second arg).
   */
  public function dispatch(IDDDConfig $config): void {
    if (!function_exists('do_action')) {
      return;
    }

    do_action($config->hook(static::action()), $this);
    do_action('tangible_ddd_' . static::action(), $this, $config->prefix());
  }
}
