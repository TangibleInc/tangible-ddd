<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Native;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Core\Tests\Pdo\PdoTestCase;

/**
 * The acceptance fixture of DurableRuntime::compose() (register section 8
 * wave 3): examples/plain-php-durable run as separate `php` processes,
 *
 *   produce.php --reset && DDD_CLOCK_OFFSET=120 drain.php && DDD_CLOCK_OFFSET=120 drain.php
 *
 * on the host's PDO and on the README's mysqli adapter. The scripts assert
 * every step themselves; this test checks their exit codes and verdict
 * lines. Runs once (it opens its own connections), in its own database
 * `<suite database>_example`.
 */
final class PlainPhpDurableExampleTest extends TestCase {

  /** @return array<string, array{0: string}> */
  public static function drivers(): array {
    return ['pdo' => ['pdo'], 'mysqli' => ['mysqli']];
  }

  #[DataProvider('drivers')]
  public function test_produce_then_two_offset_drains_complete_the_process_and_the_stale_timeout_is_a_no_op(string $driver): void {
    $produce = $this->script('produce.php', $driver, null, '--reset');
    self::assertStringContainsString('ok   the first step ran and suspended on the await', $produce);

    $first = $this->script('drain.php', $driver, '120');
    self::assertStringContainsString('ok   the trial finished as activated', $first);
    self::assertStringContainsString('ok   the fact won: the timeout was cancelled with the resuming save', $first);

    $second = $this->script('drain.php', $driver, '120');
    self::assertStringContainsString('ok   the stale timeout copy was claimed and completed', $second);
    self::assertStringContainsString('ok   as a no-op: the process row and the outcome are untouched', $second);
  }

  public function test_without_the_fact_the_due_timeout_finishes_the_trial(): void {
    $this->script('produce.php', 'pdo', null, '--reset', '--no-activation');
    self::assertStringContainsString('still waiting for its timeout', $this->script('drain.php', 'pdo', null));
    self::assertStringContainsString('ok   the timeout fired and the process proceeded', $this->script('drain.php', 'pdo', 'PT2M'));
  }

  private function script(string $name, string $driver, ?string $offset, string ...$args): string {
    $env = getenv() + [
      'DDD_EXAMPLE_DB_HOST' => getenv('DDD_PDO_HOST') ?: '127.0.0.1',
      'DDD_EXAMPLE_DB_PORT' => getenv('DDD_PDO_PORT') ?: '33306',
      'DDD_EXAMPLE_DB_USER' => getenv('DDD_PDO_USER') ?: 'root',
      'DDD_EXAMPLE_DB_PASSWORD' => getenv('DDD_PDO_PASSWORD') ?: 'ddd',
    ];
    $env['DDD_EXAMPLE_DB_NAME'] = PdoTestCase::databaseName() . '_example';
    $env['DDD_EXAMPLE_DRIVER'] = $driver;
    unset($env['DDD_CLOCK_OFFSET']);
    if ($offset !== null) {
      $env['DDD_CLOCK_OFFSET'] = $offset;
    }

    $path = dirname(__DIR__, 5) . '/examples/plain-php-durable/' . $name;
    $process = proc_open([PHP_BINARY, $path, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    self::assertIsResource($process);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    self::assertSame(0, $code, "$name ($driver, offset " . ($offset ?? 'none') . ") failed:\n$out\n$err");
    self::assertStringEndsWith("ok\n", $out);
    return $out;
  }
}
