<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Support\Fixtures;

use TangibleDDD\Domain\Events\DomainEvent;

/** A plain in-process domain event with a stamped prefix. */
final class PingDomainEvent extends DomainEvent implements PingMarker {

  public function __construct(public readonly string $id = 'x') {}

  public function payload(): array {
    return ['id' => $this->id];
  }

  protected static function prefix(): string {
    return 'sft';
  }
}
