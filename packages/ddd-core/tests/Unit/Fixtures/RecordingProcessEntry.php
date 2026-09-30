<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures;

use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Process\IProcessEntry;

/** Stub process runner: records ignitions and resumes in order. */
final class RecordingProcessEntry implements IProcessEntry {

  /** @var list<string> */
  public array $calls = [];

  /** @var list<string> shared journal a test can interleave with commands */
  public static array $journal = [];

  public function ignite(string $processClass, IIntegrationEvent $event, string $eventId): void {
    $this->calls[] = "ignite:$processClass:$eventId";
    self::$journal[] = 'ignition';
  }

  public function resume(IIntegrationEvent $event): void {
    $this->calls[] = 'resume:' . get_class($event);
    self::$journal[] = 'resume';
  }
}
