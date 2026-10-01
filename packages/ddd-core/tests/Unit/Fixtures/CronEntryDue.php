<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures;

use TangibleDDD\Domain\Events\IntegrationEvent;

/** A cron tick (D10): `due_at` is the tick time, ISO 8601 UTC. */
final class CronEntryDue extends IntegrationEvent {

  public function __construct(
    public readonly string $entry = 'nightly',
    public readonly string $due_at = '2026-10-01T12:00:00+00:00',
  ) {}

  protected static function prefix(): string {
    return 'acme';
  }
}
