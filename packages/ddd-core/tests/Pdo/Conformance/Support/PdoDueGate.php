<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Conformance\Support;

use TangibleDDD\Conformance\ScenarioId;

/**
 * Per-id verdicts from the JUnit log of the pdo conformance run: an id
 * passes only when its scenario method PASSED on a Native host class
 * (ATTR_EMULATE_PREPARES false) and on an Emulated one (true). A skipped,
 * failed, errored or missing copy fails the id, so a green PHPUnit run with
 * a due scenario only skipped is still red here.
 */
final class PdoDueGate {

  public const MODES = ['native' => '\\Native\\', 'emulated' => '\\Emulated\\'];

  /** @param array<string, array<string, string>> $outcomes method name => mode => passed|skipped|failed */
  private function __construct(private readonly array $outcomes) {}

  public static function fromJUnit(\SimpleXMLElement $xml): self {
    $outcomes = [];
    foreach ($xml->xpath('//testcase') ?: [] as $case) {
      $class = (string) $case['class'];
      $mode = null;
      foreach (self::MODES as $name => $segment) {
        if (str_contains($class, $segment)) {
          $mode = $name;
        }
      }
      if ($mode === null) {
        continue;
      }
      $outcome = match (true) {
        isset($case->failure), isset($case->error) => 'failed',
        isset($case->skipped) => 'skipped',
        default => 'passed',
      };
      $method = (string) $case['name'];
      // any non-passing copy wins
      if (($outcomes[$method][$mode] ?? 'passed') === 'passed') {
        $outcomes[$method][$mode] = $outcome;
      }
    }
    return new self($outcomes);
  }

  /** 'passed', or what was wrong per mode, e.g. 'skipped (emulated)'. */
  public function verdict(string $id): string {
    $method = ScenarioId::method_name($id);
    $problems = [];
    foreach (array_keys(self::MODES) as $mode) {
      $outcome = $this->outcomes[$method][$mode] ?? 'missing';
      if ($outcome !== 'passed') {
        $problems[] = "$outcome ($mode)";
      }
    }
    return $problems === [] ? 'passed' : implode(', ', $problems);
  }
}
