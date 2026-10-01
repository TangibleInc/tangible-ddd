<?php
/**
 * Gate of `tests/harness/run.sh core-pdo`: every scenario id due on the pdo
 * host by wave N (ScenarioCatalogue, a copy of register sections 4 and 8)
 * must have PASSED in the JUnit log in both prepare modes (Native and
 * Emulated host classes). Skipped, failed or missing copies fail the gate.
 *
 *   php packages/ddd-core/tests/Pdo/Conformance/bin/check-due.php <junit.xml> [wave=4]
 */

declare(strict_types=1);

use TangibleDDD\Conformance\ScenarioCatalogue;
use TangibleDDD\Core\Tests\Pdo\Conformance\Support\PdoDueGate;

$root = dirname(__DIR__, 6);
/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require $root . '/vendor/autoload.php';
$loader->addPsr4('TangibleDDD\\Conformance\\', $root . '/packages/ddd-conformance/src/');
$loader->addPsr4('TangibleDDD\\Core\\Tests\\Pdo\\Conformance\\', dirname(__DIR__) . '/');

[$junit, $wave] = [$argv[1] ?? '', (int) ($argv[2] ?? 4)];
if (!is_file($junit)) {
  fwrite(STDERR, "check-due: no JUnit log at '$junit'\n");
  exit(1);
}
$xml = simplexml_load_file($junit);
if ($xml === false) {
  fwrite(STDERR, "check-due: unreadable JUnit log '$junit'\n");
  exit(1);
}

$gate = PdoDueGate::fromJUnit($xml);
$due = ScenarioCatalogue::dueBy('pdo', $wave);
sort($due);
$bad = 0;
foreach ($due as $id) {
  $verdict = $gate->verdict($id);
  printf("  %-8s %s%s\n", $verdict === 'passed' ? 'passed' : 'FAILED', $id, $verdict === 'passed' ? '' : "  [$verdict]");
  if ($verdict !== 'passed') {
    $bad++;
  }
}

printf("check-due: %d of %d scenario ids due on pdo by wave %d passed in both prepare modes\n", count($due) - $bad, count($due), $wave);
exit($bad === 0 ? 0 : 1);
