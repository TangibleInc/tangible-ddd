<?php

namespace TangibleDDD\Domain\Events;

/**
 * #[Touches] referenced a class that is not an Aggregate — a wiring bug,
 * named with both parties (house exception style). Thrown by the attribute
 * ctor, so it fires wherever the attribute is instantiated: loudly in the
 * conformance scan (the hard gate), tolerated by the harvest (which never
 * throws — post-commit decoration).
 */
final class TouchesNonAggregate extends \LogicException {

  public function __construct(string $not_an_aggregate) {
    parent::__construct(sprintf(
      '#[Touches] references %s, which is not an Aggregate — touches record domain state; reference the aggregate root class. (Attributes are lazy: the declaring event is named by whoever instantiated this — the conformance scan prefixes it.)',
      $not_an_aggregate
    ));
  }
}
