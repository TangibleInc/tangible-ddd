<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Conformance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Core\Tests\Pdo\Conformance\Support\PdoDueGate;

/**
 * The gate of `run.sh core-pdo`: an id passes only when it PASSED in both
 * prepare modes. Skipped, failed, errored and missing copies fail it.
 */
#[Group('pdo')]
final class PdoDueGateTest extends TestCase {

  public function test_an_id_passes_only_when_it_passed_in_both_prepare_modes(): void {
    $gate = PdoDueGate::fromJUnit(self::junit([
      ['Native', 'PdoNativeCommandScenariosTest', 'test_cmd_commit_atomic', ''],
      ['Emulated', 'PdoEmulatedCommandScenariosTest', 'test_cmd_commit_atomic', ''],
      ['Native', 'PdoNativeCommandScenariosTest', 'test_cmd_no_boundary', ''],
      ['Emulated', 'PdoEmulatedCommandScenariosTest', 'test_cmd_no_boundary', '<skipped/>'],
      ['Native', 'PdoNativeCommandScenariosTest', 'test_cmd_return_value', '<failure message="x"/>'],
      ['Emulated', 'PdoEmulatedCommandScenariosTest', 'test_cmd_return_value', ''],
      ['Native', 'PdoNativeLockScenariosTest', 'test_lock_contention', '<error message="x"/>'],
    ]));

    self::assertSame('passed', $gate->verdict('cmd.commit-atomic'));
    self::assertSame('skipped (emulated)', $gate->verdict('cmd.no-boundary'));
    self::assertSame('failed (native)', $gate->verdict('cmd.return-value'));
    self::assertSame('failed (native), missing (emulated)', $gate->verdict('lock.contention'));
    self::assertSame('missing (native), missing (emulated)', $gate->verdict('process.stale-wakeup'));
  }

  public function test_check_due_exits_0_only_when_every_due_id_passed(): void {
    $dir = sys_get_temp_dir() . '/ddd-due-' . bin2hex(random_bytes(4));
    mkdir($dir);
    $partial = "$dir/partial.xml";
    file_put_contents($partial, self::junit([
      ['Native', 'PdoNativeCommandScenariosTest', 'test_cmd_commit_atomic', ''],
    ])->asXML());

    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__FILE__) . '/bin/check-due.php') . ' ' . escapeshellarg($partial) . ' 2>&1', $out, $code);
    @unlink($partial);
    @rmdir($dir);

    self::assertSame(1, $code, implode("\n", $out));
    self::assertStringContainsString('missing (emulated)', implode("\n", $out));
    self::assertStringContainsString('of 37 scenario ids due on pdo by wave 3 passed', implode("\n", $out));
  }

  /** @param list<array{string, string, string, string}> $cases mode, class, method, child xml */
  private static function junit(array $cases): \SimpleXMLElement {
    $xml = '<testsuites><testsuite name="pdo-conformance">';
    foreach ($cases as [$mode, $class, $method, $child]) {
      $fqcn = "TangibleDDD\\Core\\Tests\\Pdo\\Conformance\\$mode\\$class";
      $xml .= "<testcase name=\"$method\" class=\"$fqcn\" classname=\"" . str_replace('\\', '.', $fqcn) . "\">$child</testcase>";
    }
    return new \SimpleXMLElement($xml . '</testsuite></testsuites>');
  }
}
