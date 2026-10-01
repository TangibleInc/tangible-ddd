<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures;

use Psr\Log\AbstractLogger;

/** PSR-3 logger that keeps every record, for assertions. */
final class RecordingLogger extends AbstractLogger {

  /** @var list<array{level: string, message: string}> */
  public array $records = [];

  /** Signature valid against psr/log 1, 2 and 3. @param string|\Stringable $message */
  public function log($level, $message, array $context = []): void {
    $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
  }

  /** @return list<string> */
  public function messages(): array {
    return array_map(static fn (array $r) => $r['message'], $this->records);
  }
}
