<?php
/**
 * Gate of `tests/harness/run.sh conformance-wp`: every scenario id due on
 * the wp host by wave N (ScenarioCatalogue, a copy of register sections 4
 * and 8) must appear in the JUnit log of the conformance-wp run as a PASSED
 * test on a Wp*Conformance class, and fail on none. A green PHPUnit run with
 * a due scenario only skipped, renamed or missing is a failure here.
 *
 * When an id is carried by more than one wp class, every copy must run: a
 * skipped copy fails the id, except a provisional skip whose host seam does
 * not exist yet (Support\DueGate::PROVISIONAL_SKIPS, WPC-4).
 *
 *   php tests/Integration/Conformance/bin/check-due.php <junit.xml> [wave=2]
 */

declare(strict_types=1);

use TangibleDDD\Conformance\ScenarioCatalogue;
use TangibleDDD\Tests\Integration\Conformance\Support\DueGate;

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

$gate = DueGate::fromJUnit($xml);
$due = ScenarioCatalogue::dueBy('wp', $wave);
sort($due);
$bad = 0;
foreach ($due as $id) {
  $outcome = $gate->verdict($id);
  printf("  %-8s %s\n", $outcome, $id);
  if ($outcome !== 'passed') {
    $bad++;
  }
}

printf("check-due: %d of %d scenario ids due on wp by wave %d passed\n", count($due) - $bad, count($due), $wave);
exit($bad === 0 ? 0 : 1);
