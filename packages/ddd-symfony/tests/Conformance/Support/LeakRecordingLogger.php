<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Conformance\Support;

use Psr\Log\AbstractLogger;
use TangibleDDD\Runtime\RuntimeLeakDetected;

/**
 * The logger DddRuntimeReset reports leaks to (CRITICAL, with the
 * RuntimeLeakDetected as `exception`). The fixture takes the last one after
 * each worker message.
 */
final class LeakRecordingLogger extends AbstractLogger {

  private ?RuntimeLeakDetected $leak = null;

  public function log($level, \Stringable|string $message, array $context = []): void {
    if (($context['exception'] ?? null) instanceof RuntimeLeakDetected) {
      $this->leak = $context['exception'];
    }
  }

  public function takeLeak(): ?RuntimeLeakDetected {
    $leak = $this->leak;
    $this->leak = null;
    return $leak;
  }
}
