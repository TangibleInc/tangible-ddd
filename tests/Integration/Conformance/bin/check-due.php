<?php
/**
 * Gate of `tests/harness/run.sh conformance-wp`: every scenario id due on
 * the wp host by wave N (ScenarioCatalogue, a copy of register sections 4
 * and 8) must appear in the JUnit log of the conformance-wp run as a PASSED
 * test on a Wp*Conformance class, and fail on none. A green PHPUnit run with
 * a due scenario only skipped, renamed or missing is a failure here.
 *
 *   php tests/Integration/Conformance/bin/check-due.php <junit.xml> [wave=2]
 */

declare(strict_types=1);

use TangibleDDD\Conformance\ScenarioCatalogue;
use TangibleDDD\Conformance\ScenarioId;

$root = dirname(__DIR__, 4);
/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require $root . '/vendor/autoload.php';
$loader->addPsr4('TangibleDDD\\Conformance\\', $root . '/packages/ddd-conformance/src/');

[$junit, $wave] = [$argv[1] ?? '', (int) ($argv[2] ?? 2)];
if (!is_file($junit)) {
  fwrite(STDERR, "check-due: no JUnit log at '$junit'\n");
  exit(1);
}

$xml = simplexml_load_file($junit);
if ($xml === false) {
  fwrite(STDERR, "check-due: unreadable JUnit log '$junit'\n");
  exit(1);
}

// One id may be carried by more than one wp class (e.g. a shared scenario
// a host cannot run yet is skipped there and run by a wp-specific class).
// An id passes when at least one wp test carrying it passed and none failed.
/** @var array<string, array{passed: int, failed: int, skipped: int}> method name => counts on wp classes */
$seen = [];
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
  $seen[$method] ??= ['passed' => 0, 'failed' => 0, 'skipped' => 0];
  $seen[$method][$outcome]++;
}
$outcomes = array_map(static fn (array $n) => match (true) {
  $n['failed'] > 0 => 'failed',
  $n['passed'] > 0 => 'passed',
  default => 'skipped',
}, $seen);

$due = ScenarioCatalogue::dueBy('wp', $wave);
sort($due);
$bad = 0;
foreach ($due as $id) {
  $method = ScenarioId::methodName($id);
  $outcome = $outcomes[$method] ?? 'missing';
  printf("  %-8s %s\n", $outcome, $id);
  if ($outcome !== 'passed') {
    $bad++;
  }
}

printf("check-due: %d of %d scenario ids due on wp by wave %d passed\n", count($due) - $bad, count($due), $wave);
exit($bad === 0 ? 0 : 1);
