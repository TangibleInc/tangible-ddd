<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures;

use Psr\Log\AbstractLogger;

/** PSR-3 logger that keeps every record, for assertions. */
final class RecordingLogger extends AbstractLogger {

  /** @var list<array{level: string, message: string}> */
  public array $records = [];

  public function log($level, \Stringable|string $message, array $context = []): void {
    $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
  }

  /** @return list<string> */
  public function messages(): array {
    return array_map(static fn (array $r) => $r['message'], $this->records);
  }
}
