<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance\Support;

use TangibleDDD\Conformance\ScenarioId;

/**
 * Verdicts of the conformance-wp gate (bin/check-due.php), read from the
 * run's JUnit log.
 *
 * A scenario id is carried by test methods named ScenarioId::method_name($id)
 * on Wp*Conformance classes. One id may be carried by more than one class
 * (a shared scenario class and a wp-local class). The id passes when at
 * least one copy passed, no copy failed or errored, and no copy was skipped.
 *
 * The single exception is PROVISIONAL_SKIPS: a skipped copy of such an id is
 * tolerated, beside a passed copy, only while the named host seam interface
 * does not exist. Once it exists (the branch that defines it is merged), the
 * shared copy must run on wp, so a wp-local test of the same name can no
 * longer mask it (WPC-4).
 */
final class DueGate {

  /** @var array<string, class-string> id => seam interface whose absence tolerates a skipped copy */
  public const PROVISIONAL_SKIPS = [
    'audit.sink-fails' => 'TangibleDDD\\Conformance\\AuditSinkFaults',
  ];

  /**
   * @param array<string, array{passed: int, failed: int, skipped: int}> $counts method name => outcomes on wp classes
   * @param \Closure(string): bool $seamExists
   */
  private function __construct(private readonly array $counts, private readonly \Closure $seamExists) {}

  /** @param null|\Closure(string): bool $seamExists defaults to interface_exists() with autoload */
  public static function fromJUnit(\SimpleXMLElement $xml, ?\Closure $seamExists = null): self {
    $counts = [];
    foreach ($xml->xpath('//testcase') ?: [] as $case) {
      $class = (string) $case['class'];
      if (!str_contains($class, '\\Integration\\Conformance\\Wp')) {
        continue;
      }
      $method = (string) $case['name'];
      $outcome = match (true) {
        isset($case->failure), isset($case->error) => 'failed',
        isset($case->skipped) => 'skipped',
        default => 'passed',
      };
      $counts[$method] ??= ['passed' => 0, 'failed' => 0, 'skipped' => 0];
      $counts[$method][$outcome]++;
    }

    return new self($counts, $seamExists ?? static fn (string $fqcn): bool => interface_exists($fqcn));
  }

  /** @return 'passed'|'failed'|'skipped'|'missing' */
  public function verdict(string $id): string {
    $n = $this->counts[ScenarioId::method_name($id)] ?? null;
    if ($n === null) {
      return 'missing';
    }
    if ($n['failed'] > 0) {
      return 'failed';
    }
    if ($n['passed'] === 0) {
      return 'skipped';
    }
    if ($n['skipped'] > 0 && !$this->skipTolerated($id)) {
      return 'skipped';
    }

    return 'passed';
  }

  private function skipTolerated(string $id): bool {
    $seam = self::PROVISIONAL_SKIPS[$id] ?? null;

    return $seam !== null && !($this->seamExists)($seam);
  }
}
