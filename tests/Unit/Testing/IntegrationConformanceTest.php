<?php

namespace TangibleDDD\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Testing\IntegrationConformance;

/**
 * The two doctrine checks consumers run over their own src/:
 *
 *  - event_violations(): every concrete class using IntegrationBehaviour must
 *    have a publishable ctor (the ctor IS the wire schema) — scalars, backed
 *    enums, DateTimeImmutable-compatible dates, arrays. Catches at test time
 *    what strict scalarise()/revive() would throw at first publish.
 *  - listener_violations(): IntegrationListener subclasses translate facts to
 *    intentions and do nothing else — object ctor dependencies are the fat-
 *    listener anti-pattern (the work belongs in the command handler).
 *
 * Scanners are PHPUnit-independent (violation arrays in, no assertions) so
 * `wp ddd doctor` can reuse them verbatim.
 */
class IntegrationConformanceTest extends TestCase {

  private const FIXTURES = __DIR__ . '/../../Fakes/Conformance';

  // ── events ──────────────────────────────────────────────────────────

  public function test_conformant_event_passes(): void {
    $violations = IntegrationConformance::event_violations(self::FIXTURES);

    $this->assertNotContains(
      'TangibleDDD\\Tests\\Fakes\\Conformance\\ConformantEvent',
      array_column($violations, 'class'),
    );
  }

  public function test_entity_param_is_flagged(): void {
    $this->assertViolation('EntityLadenEvent', 'entity', param: 'entity');
  }

  public function test_mutable_datetime_is_flagged(): void {
    $this->assertViolation('MutableDateEvent', 'DateTimeImmutable', param: 'when');
  }

  public function test_union_type_is_flagged(): void {
    $this->assertViolation('UnionTypedEvent', 'union', param: 'ref');
  }

  public function test_untyped_param_is_flagged(): void {
    $this->assertViolation('UntypedEvent', 'schema', param: 'anything');
  }

  public function test_abstract_bases_and_bystanders_are_exempt(): void {
    $flagged = array_column(IntegrationConformance::event_violations(self::FIXTURES), 'class');

    $this->assertNotContains('TangibleDDD\\Tests\\Fakes\\Conformance\\AbstractEventBase', $flagged);
    $this->assertNotContains('TangibleDDD\\Tests\\Fakes\\Conformance\\PlainValueHolder', $flagged);
  }

  // ── listeners ───────────────────────────────────────────────────────

  public function test_thin_and_scalar_configured_listeners_pass(): void {
    $flagged = array_column(IntegrationConformance::listener_violations(self::FIXTURES), 'class');

    $this->assertNotContains('TangibleDDD\\Tests\\Fakes\\Conformance\\ThinListener', $flagged);
    $this->assertNotContains('TangibleDDD\\Tests\\Fakes\\Conformance\\ConfiguredListener', $flagged);
  }

  public function test_fat_listener_is_flagged_with_remediation(): void {
    $violations = IntegrationConformance::listener_violations(self::FIXTURES);
    $fat = array_values(array_filter(
      $violations,
      fn (array $v) => str_ends_with($v['class'], 'FatListener'),
    ));

    $this->assertCount(1, $fat);
    $this->assertSame('repo', $fat[0]['param']);
    $this->assertStringContainsString('command handler', $fat[0]['problem']);
  }

  // ── ergonomics ──────────────────────────────────────────────────────

  public function test_describe_renders_one_line_per_violation(): void {
    $violations = IntegrationConformance::event_violations(self::FIXTURES);
    $text = IntegrationConformance::describe($violations);

    $this->assertStringContainsString('EntityLadenEvent', $text);
    $this->assertStringContainsString('$entity', $text);
  }

  public function test_missing_directory_yields_no_violations(): void {
    $this->assertSame([], IntegrationConformance::event_violations(self::FIXTURES . '/nope'));
    $this->assertSame([], IntegrationConformance::listener_violations(self::FIXTURES . '/nope'));
  }

  private function assertViolation(string $short_class, string $problem_fragment, string $param): void {
    $violations = IntegrationConformance::event_violations(self::FIXTURES);
    $hits = array_values(array_filter(
      $violations,
      fn (array $v) => str_ends_with($v['class'], $short_class) && $v['param'] === $param,
    ));

    $this->assertCount(1, $hits, "expected exactly one violation for {$short_class}::\${$param}");
    $this->assertStringContainsStringIgnoringCase($problem_fragment, $hits[0]['problem']);
  }
}
