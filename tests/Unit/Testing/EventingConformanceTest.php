<?php

namespace TangibleDDD\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Testing\IntegrationConformance;

/**
 * The eventing fences as shift-left scanners (Item 1 + Item 5 of the
 * hardening batch). Both are TEXT scans, not reflection: the verbs they
 * police ($aggregate->pull_events(), $this->event()) are call-site sins,
 * invisible to a ctor/interface scan, and the fixture dirs must never be
 * autoloaded to be judged.
 *
 *  - pull_events_violations(): pull_events() is the framework's harvest
 *    verb; consumer code clearing a diary must say discard_events().
 *  - handler_raised_events(): every $this->event( in a command handler (or
 *    any class naming RaisesEvents) is an act-level raise — legal, but
 *    only as a conscious, reviewed decision. The allowlist IS the review.
 */
class EventingConformanceTest extends TestCase {

  private const FIXTURES = __DIR__ . '/../../Fakes/EventingConformance';

  // ── pull_events_violations ──────────────────────────────────────────

  public function test_consumer_pull_events_call_is_flagged_with_file_and_line(): void {
    $violations = IntegrationConformance::pull_events_violations(self::FIXTURES . '/dirty');

    $this->assertCount(1, $violations);
    $this->assertStringContainsString('RehydratingRepository.php', $violations[0]['file']);
    $this->assertSame(14, $violations[0]['line']);
    $this->assertStringContainsString('discard_events', $violations[0]['problem'], 'points at the blessed verb');
  }

  public function test_discarding_repository_is_clean_and_declarations_do_not_trip(): void {
    $this->assertSame([], IntegrationConformance::pull_events_violations(self::FIXTURES . '/clean'));
  }

  public function test_mega_trace_consumer_fixture_is_clean(): void {
    // The in-repo reference consumer adopts the fence from day one.
    $this->assertSame(
      [],
      IntegrationConformance::pull_events_violations(__DIR__ . '/../../../tools/mega-trace/src'),
      IntegrationConformance::describe(IntegrationConformance::pull_events_violations(__DIR__ . '/../../../tools/mega-trace/src'))
    );
  }

  public function test_missing_dir_yields_no_violations(): void {
    $this->assertSame([], IntegrationConformance::pull_events_violations(self::FIXTURES . '/nope'));
  }

  // ── handler_raised_events ───────────────────────────────────────────

  public function test_handler_raise_and_trait_user_are_reported_but_bystanders_are_not(): void {
    $occurrences = IntegrationConformance::handler_raised_events(self::FIXTURES . '/handlers');

    $files = array_map(static fn (array $o) => basename($o['file']), $occurrences);
    sort($files);
    $this->assertSame(
      ['RescheduleRaisingHandler.php', 'TraitRaisingService.php'],
      $files,
      'CommandHandlers/* and RaisesEvents users are in scope; other $this->event( calls are not'
    );
  }

  public function test_allowlisted_class_is_covered(): void {
    $occurrences = IntegrationConformance::handler_raised_events(self::FIXTURES . '/handlers', [
      'TangibleDDD\\Tests\\Fakes\\EventingConformance\\Handlers\\Application\\CommandHandlers\\RescheduleRaisingHandler',
    ]);

    $files = array_map(static fn (array $o) => basename($o['file']), $occurrences);
    $this->assertSame(['TraitRaisingService.php'], $files);
  }

  public function test_allowlist_also_matches_by_path_fragment(): void {
    $occurrences = IntegrationConformance::handler_raised_events(self::FIXTURES . '/handlers', [
      'RescheduleRaisingHandler.php',
      'TraitRaisingService.php',
    ]);

    $this->assertSame([], $occurrences);
  }

  public function test_describe_renders_file_line_rows(): void {
    $line = IntegrationConformance::describe(
      IntegrationConformance::pull_events_violations(self::FIXTURES . '/dirty')
    );

    $this->assertStringContainsString('RehydratingRepository.php', $line);
    $this->assertStringContainsString(':14', $line);
  }
}
