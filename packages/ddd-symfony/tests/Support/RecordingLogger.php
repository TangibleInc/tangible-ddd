<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Support;

use Psr\Log\AbstractLogger;

/** PSR-3 logger that keeps every line, for asserting on warnings and errors. */
final class RecordingLogger extends AbstractLogger {

  /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
  public array $records = [];

  public function log($level, \Stringable|string $message, array $context = []): void {
    $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
  }

  public function text(): string {
    return implode("\n", array_map(static fn (array $r) => "[{$r['level']}] {$r['message']}", $this->records));
  }

  /** @return list<string> messages logged at $level */
  public function at(string $level): array {
    return array_values(array_map(
      static fn (array $r) => $r['message'],
      array_filter($this->records, static fn (array $r) => $r['level'] === $level)
    ));
  }
}
