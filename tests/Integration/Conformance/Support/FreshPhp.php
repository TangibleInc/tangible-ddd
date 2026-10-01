<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance\Support;

/**
 * Parent side of a fresh php process (FreshProcesses on wp): runs
 * `php bin/fresh.php <op> <args>` in the same container, with the parent's
 * environment (the WP_TESTS_DB_* the harness passed) plus the host clock
 * (DDD_CONFORMANCE_NOW, the parent's frozen time as a unix timestamp).
 *
 * The child boots WordPress through the same conformance bootstrap and
 * composes the same WpConformanceRuntime; it shares nothing with the
 * parent but the database. It reports on stdout, one JSON object per line
 * (merged here, later keys win), and flushes before a kill, so a SIGKILLed
 * child still reports what it knew at the kill point. stderr goes to a
 * temporary file (never a pipe the parent could block on).
 *
 * After a child that died, the parent waits until MySQL has dropped the
 * child's connection (and with it every GET_LOCK it held), as it would be
 * long gone by the time anything else looked.
 */
final class FreshPhp {

  /** @return array{died: bool, exit: int, out: array<string, mixed>, stderr: string} */
  public static function run(string $op, array $args, \DateTimeImmutable $now): array {
    $script = dirname(__DIR__) . '/bin/fresh.php';
    $errFile = tempnam(sys_get_temp_dir(), 'ddd-fresh-');
    $env = getenv();
    $env['DDD_CONFORMANCE_NOW'] = (string) $now->getTimestamp();

    $proc = proc_open(
      [PHP_BINARY, '-d', 'memory_limit=1G', $script, $op, base64_encode((string) json_encode($args))],
      [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']],
      $pipes,
      dirname(__DIR__, 4),
      $env,
    );
    if (!is_resource($proc)) {
      throw new \RuntimeException("conformance-wp: could not start the fresh php process for $op");
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $status = proc_get_status($proc);
    while ($status['running']) {
      usleep(10_000);
      $status = proc_get_status($proc);
    }
    proc_close($proc);
    $stderr = (string) @file_get_contents($errFile);
    @unlink($errFile);

    $out = [];
    foreach (preg_split('/\R/', $stdout) ?: [] as $line) {
      $decoded = json_decode(trim($line), true);
      if (is_array($decoded)) {
        $out = array_replace($out, $decoded);
      }
    }

    $died = $status['signaled'] && $status['termsig'] === 9;
    if ($died && isset($out['conn'])) {
      self::awaitDisconnect((int) $out['conn']);
    }
    if (!$died && $status['exitcode'] !== 0) {
      throw new \RuntimeException(sprintf(
        "conformance-wp: fresh php process '%s' exited %d.\nstdout: %s\nstderr: %s",
        $op, $status['exitcode'], $stdout, $stderr
      ));
    }

    return ['died' => $died, 'exit' => (int) $status['exitcode'], 'out' => $out, 'stderr' => $stderr];
  }

  private static function awaitDisconnect(int $connectionId): void {
    global $wpdb;
    for ($i = 0; $i < 200; $i++) {
      $alive = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = %d', $connectionId));
      if ($alive === 0) {
        return;
      }
      usleep(10_000);
    }
    throw new \RuntimeException("conformance-wp: MySQL still holds connection $connectionId of a killed fresh process");
  }
}
