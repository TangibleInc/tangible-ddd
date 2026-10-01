<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Compat\Rollback\Support;

/**
 * A legacy winner (L-0.6.x) as a php child process on the same database
 * (bin/legacy.php). One instance per installed copy.
 */
final class Legacy {

  public function __construct(public readonly string $version, public readonly string $dir) {}

  /**
   * The installed copies, from DDD_ROLLBACK_LEGACY ("0.6.6=/legacy/a 0.6.2=/legacy/b").
   *
   * @return array<string, self> version => copy
   */
  public static function all(): array {
    $out = [];
    foreach (preg_split('/\s+/', trim((string) getenv('DDD_ROLLBACK_LEGACY'))) ?: [] as $pair) {
      if (!str_contains($pair, '=')) {
        continue;
      }
      [$version, $dir] = explode('=', $pair, 2);
      $out[$version] = new self($version, $dir);
    }
    return $out;
  }

  /**
   * Run one operation; returns its JSON object. A child that fails (exit
   * code, an `error` key) throws with its output.
   *
   * @param array<string, mixed> $args
   * @return array<string, mixed>
   */
  public function run(string $op, array $args = []): array {
    $cmd = [PHP_BINARY, '-d', 'memory_limit=512M', dirname(__DIR__) . '/bin/legacy.php', $op, base64_encode((string) json_encode($args))];
    $env = getenv();
    $env['DDD_ROLLBACK_LEGACY_DIR'] = $this->dir;
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($proc)) {
      throw new \RuntimeException("legacy $this->version: could not start the child");
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    $data = null;
    foreach (array_reverse(preg_split('/\R/', trim($out)) ?: []) as $line) {
      $decoded = json_decode($line, true);
      if (is_array($decoded)) {
        $data = $decoded;
        break;
      }
    }
    if ($code !== 0 || !is_array($data) || isset($data['error'])) {
      throw new \RuntimeException(sprintf("legacy %s `%s` failed (exit %d): %s\n--- stdout\n%s\n--- stderr\n%s", $this->version, $op, $code, (string) ($data['error'] ?? 'no result'), $out, $err));
    }
    $data['_stderr'] = $err;
    return $data;
  }
}
