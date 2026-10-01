<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Conformance\Support;

use TangibleDDD\Conformance\FreshRun;

/**
 * Runs one fresh `php` process (bin/fresh.php) against the fixture's
 * database: nothing is shared with the test process except the database and
 * the clock offset (DDD_CLOCK_OFFSET). The operation and its arguments go
 * over a serialized spec file; the child answers with one marker line of
 * JSON on stdout. A child killed with SIGKILL at its stated point reports
 * what it knew right before the kill; the parent records `died`.
 */
final class FreshProcessRunner {

  public const MARKER = '@@ddd-fresh@@';

  public function __construct(
    private readonly string $database,
    private readonly string $prefix,
    private readonly string $version,
    private readonly bool $emulatePrepares,
    private readonly int $clockOffset,
  ) {}

  public static function script(): string {
    return dirname(__DIR__) . '/bin/fresh.php';
  }

  /**
   * @param array<string, mixed> $args
   * @return array{died: bool, eventId?: string, processId?: ?int, relayed?: list<string>, delivered?: int, errors?: list<string>}
   */
  public function run(string $op, array $args): array {
    $spec = tempnam(sys_get_temp_dir(), 'ddd-fresh-');
    if ($spec === false) {
      throw new \RuntimeException('could not create the fresh-process spec file');
    }
    file_put_contents($spec, serialize([
      'op' => $op,
      'args' => $args,
      'database' => $this->database,
      'prefix' => $this->prefix,
      'version' => $this->version,
      'emulate' => $this->emulatePrepares,
    ]));

    try {
      $env = getenv() + [];
      $env['DDD_CLOCK_OFFSET'] = (string) $this->clockOffset;
      $process = proc_open(
        [PHP_BINARY, '-d', 'memory_limit=512M', self::script(), $spec],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        $env,
      );
      if (!is_resource($process)) {
        throw new \RuntimeException('could not start the fresh php process');
      }
      $stdout = (string) stream_get_contents($pipes[1]);
      $stderr = (string) stream_get_contents($pipes[2]);
      fclose($pipes[1]);
      fclose($pipes[2]);

      // proc_close() loses the signal; poll the status until the child is reaped.
      do {
        $status = proc_get_status($process);
        if ($status['running']) {
          usleep(10_000);
        }
      } while ($status['running']);
      proc_close($process);
    } finally {
      @unlink($spec);
    }

    $killed = $status['signaled'] && $status['termsig'] === 9;
    $answer = null;
    foreach (explode("\n", $stdout) as $line) {
      if (str_starts_with($line, self::MARKER)) {
        $answer = json_decode(substr($line, strlen(self::MARKER)), true, 512, JSON_THROW_ON_ERROR);
      }
    }
    if ($answer === null || (!$killed && $status['exitcode'] !== 0)) {
      throw new \RuntimeException(sprintf(
        "fresh php process '%s' failed (exit %s%s)\nstdout:\n%s\nstderr:\n%s",
        $op,
        (string) $status['exitcode'],
        $status['signaled'] ? ', signal ' . $status['termsig'] : '',
        $stdout,
        $stderr,
      ));
    }
    $answer['died'] = $killed;
    return $answer;
  }

  /** @param array<string, mixed> $answer */
  public function freshRun(array $answer): FreshRun {
    return new FreshRun(
      died: (bool) $answer['died'],
      process_id: isset($answer['processId']) ? (int) $answer['processId'] : null,
      relayed: array_values($answer['relayed'] ?? []),
      delivered: (int) ($answer['delivered'] ?? 0),
      errors: array_values($answer['errors'] ?? []),
    );
  }
}
