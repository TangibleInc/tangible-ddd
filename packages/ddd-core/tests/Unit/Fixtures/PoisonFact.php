<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures;

use TangibleDDD\Domain\Events\IntegrationEvent;

/** A fact whose stored payload never decodes (a renamed field, a removed enum case). */
final class PoisonFact extends IntegrationEvent {

  public function __construct(public readonly int $id = 1) {}

  public static function from_payload(array $payload): static {
    throw new \UnexpectedValueException('payload no longer decodes');
  }

  protected static function prefix(): string {
    return 'acme';
  }
}
