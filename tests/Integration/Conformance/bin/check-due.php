<?php
/**
 * Gate of `tests/harness/run.sh conformance-wp`: every scenario id due on
 * the wp host by wave N (ScenarioCatalogue, a copy of register sections 4
 * and 8) must appear in the JUnit log of the conformance-wp run as a PASSED
 * test (no failure, error or skip). A green PHPUnit run with a due scenario
 * skipped, renamed or missing is a failure here.
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

/** @var array<string, 'passed'|'failed'|'skipped'> method name => worst outcome on a wp class */
$outcomes = [];
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
  $previous = $outcomes[$method] ?? 'passed';
  $outcomes[$method] = $previous !== 'passed' ? $previous : $outcome;
}

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
