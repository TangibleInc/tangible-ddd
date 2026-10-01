<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Support;

use Psr\Log\AbstractLogger;

/**
 * PSR-3 logger that keeps every record in memory (CR-SP-1: the runtime
 * classes take a LoggerInterface; the wave-1 closure form is transitional).
 * A host fixture registers it in HostDefaults too, so the core classes that
 * log through the host logger (the act bracket's sink-failure line, signal
 * fallbacks) stay off stderr during a test run.
 */
final class RecordingLogger extends AbstractLogger {

  /** @var list<array{level: string, message: string}> */
  public array $records = [];

  /** @param \Closure(string): void|null $onMessage also called with each message */
  public function __construct(private readonly ?\Closure $onMessage = null) {}

  public function log($level, string|\Stringable $message, array $context = []): void {
    $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
    if ($this->onMessage !== null) {
      ($this->onMessage)((string) $message);
    }
  }
}
