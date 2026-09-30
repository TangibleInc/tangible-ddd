<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Correlation;

/**
 * Identity of the integration fact currently being delivered (register 3.9, D13).
 *
 * Returned by `Correlation::current_fact()` when the ambient cause is
 * Kind::Fact. That accessor needs an edit to the existing Correlation class,
 * which wave 1 may not make; it lands with the wave-2 move of Correlation.
 * Until then FactRef is the frozen value shape adapters and consumers type against.
 *
 * Pure value: never throws, holds no connection, safe to keep beyond a message.
 */
final class FactRef {

  public function __construct(
    public readonly string $eventId,
    public readonly string $eventClass,
    public readonly string $correlationId,
  ) {}
}
