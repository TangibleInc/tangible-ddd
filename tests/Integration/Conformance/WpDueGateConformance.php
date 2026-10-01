<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Tests\Integration\Conformance\Support\DueGate;

/**
 * The verdict rules of bin/check-due.php, pinned on hand-written JUnit
 * logs. Not a catalogue id; check-due ignores these methods.
 *
 * An id carried by more than one wp class passes only when a copy passed,
 * none failed and none was skipped. The one tolerated skip is a copy whose
 * host seam does not exist in the codebase yet (DueGate::PROVISIONAL_SKIPS);
 * once the seam interface exists, a skipped copy fails the gate, so a
 * wp-local test can no longer mask the shared scenario.
 */
#[Group('wp')]
#[Group('gate')]
final class WpDueGateConformance extends TestCase {

  private const SHARED = 'TangibleDDD\\Tests\\Integration\\Conformance\\WpCommandConformance';
  private const LOCAL = 'TangibleDDD\\Tests\\Integration\\Conformance\\WpAuditConformance';
  private const NOT_WP = 'TangibleDDD\\Tests\\Unit\\SomethingTest';

  #[TestDox('a copy that passed, alone, passes the id')]
  public function test_single_passed_copy_passes(): void {
    $gate = $this->gate([[self::SHARED, 'test_cmd_commit_atomic', 'passed']]);
    self::assertSame('passed', $gate->verdict('cmd.commit-atomic'));
  }

  #[TestDox('an id no wp test carries is missing')]
  public function test_absent_id_is_missing(): void {
    $gate = $this->gate([[self::NOT_WP, 'test_cmd_commit_atomic', 'passed']]);
    self::assertSame('missing', $gate->verdict('cmd.commit-atomic'));
  }

  #[TestDox('any failed or errored copy fails the id, even when another copy passed')]
  public function test_failed_copy_fails(): void {
    $gate = $this->gate([
      [self::SHARED, 'test_audit_sink_fails', 'passed'],
      [self::LOCAL, 'test_audit_sink_fails', 'error'],
    ]);
    self::assertSame('failed', $gate->verdict('audit.sink-fails'));
  }

  #[TestDox('a skipped copy beside a passed copy fails the id when the id has no provisional seam')]
  public function test_skipped_copy_masks_nothing(): void {
    $gate = $this->gate([
      [self::SHARED, 'test_cmd_commit_atomic', 'skipped'],
      [self::LOCAL, 'test_cmd_commit_atomic', 'passed'],
    ]);
    self::assertSame('skipped', $gate->verdict('cmd.commit-atomic'));
  }

  #[TestDox('a skipped copy is tolerated beside a passed copy while its seam interface does not exist')]
  public function test_skipped_copy_tolerated_while_seam_absent(): void {
    $gate = $this->gate([
      [self::SHARED, 'test_audit_sink_fails', 'skipped'],
      [self::LOCAL, 'test_audit_sink_fails', 'passed'],
    ], seamExists: static fn (string $fqcn): bool => false);
    self::assertSame('passed', $gate->verdict('audit.sink-fails'));
  }

  #[TestDox('once the seam interface exists (WPC-4 due), a skipped shared copy fails the id')]
  public function test_skipped_copy_fails_once_seam_exists(): void {
    $gate = $this->gate([
      [self::SHARED, 'test_audit_sink_fails', 'skipped'],
      [self::LOCAL, 'test_audit_sink_fails', 'passed'],
    ], seamExists: static fn (string $fqcn): bool => $fqcn === DueGate::PROVISIONAL_SKIPS['audit.sink-fails']);
    self::assertSame('skipped', $gate->verdict('audit.sink-fails'));
  }

  #[TestDox('only skipped copies never pass, provisional or not')]
  public function test_only_skipped_never_passes(): void {
    $gate = $this->gate([[self::SHARED, 'test_audit_sink_fails', 'skipped']], seamExists: static fn (string $fqcn): bool => false);
    self::assertSame('skipped', $gate->verdict('audit.sink-fails'));
  }

  #[TestDox('the provisional seam list names only audit.sink-fails (WPC-4)')]
  public function test_provisional_list_is_wpc4_only(): void {
    self::assertSame(['audit.sink-fails' => 'TangibleDDD\\Conformance\\AuditSinkFaults'], DueGate::PROVISIONAL_SKIPS);
  }

  /** @param list<array{0: string, 1: string, 2: 'passed'|'failed'|'error'|'skipped'}> $cases */
  private function gate(array $cases, ?\Closure $seamExists = null): DueGate {
    $xml = '<?xml version="1.0" encoding="UTF-8"?><testsuites><testsuite name="conformance-wp">';
    foreach ($cases as [$class, $method, $outcome]) {
      $body = match ($outcome) {
        'passed' => '',
        'failed' => '<failure type="x">boom</failure>',
        'error' => '<error type="x">boom</error>',
        'skipped' => '<skipped/>',
      };
      $xml .= sprintf('<testcase name="%s" class="%s" file="x.php">%s</testcase>', $method, $class, $body);
    }
    $xml .= '</testsuite></testsuites>';

    return DueGate::fromJUnit(new \SimpleXMLElement($xml), $seamExists);
  }
}
