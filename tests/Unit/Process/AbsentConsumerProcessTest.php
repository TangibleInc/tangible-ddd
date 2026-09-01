<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use Tangible\DeactivatedPlugin\Domain\Events\EventFromAnAbsentPlugin;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Infra\Consumers\IntegrationHookName;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\Tests\Fakes\FakeProcessRepository;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;
use TangibleDDD\Tests\Fakes\FakeStartsOnProcess;

require_once __DIR__ . '/../../Fakes/Absent/EventFromAnAbsentPlugin.php';

/**
 * Process wiring is the second registration path (after integration
 * listeners) that resolves a hook name at `init` priority 3, and it must
 * tolerate an absent owner the same way.
 *
 * Regression: with tangible-lms deactivated, mega-trace's
 * CertificationJourneyProcess still declared #[StartsOn] a Tangible\LMS
 * event, and register_start() fatalled every request from wp-settings.php.
 */
final class AbsentConsumerProcessTest extends TestCase {

  private ProcessRunner $runner;

  protected function setUp(): void {
    Correlation::reset();
    IntegrationHookName::reset();
    $GLOBALS['wpdb'] = new \wpdb();
    $GLOBALS['_test_actions'] = [];
    $this->runner = new ProcessRunner(new FakeDDDConfig(), new FakeProcessRepository());
  }

  protected function tearDown(): void {
    Correlation::reset();
    IntegrationHookName::reset();
  }

  public function test_register_start_skips_an_event_no_consumer_owns(): void {
    $this->runner->register_start(FakeStartsOnProcess::class, EventFromAnAbsentPlugin::class);

    $this->assertSame(
      [],
      $GLOBALS['_test_actions'],
      'process ignition binds no hook when the event owner is deactivated',
    );
  }

  public function test_register_event_skips_an_event_no_consumer_owns(): void {
    $this->runner->register_event(EventFromAnAbsentPlugin::class);

    $this->assertSame([], $GLOBALS['_test_actions']);
  }

  public function test_a_resolvable_event_still_registers_its_hooks(): void {
    $this->runner->register_start(FakeStartsOnProcess::class, FakeResolvedEvent::class);
    $this->runner->register_event(FakeResolvedEvent::class);

    $this->assertArrayHasKey(
      FakeResolvedEvent::integration_action(),
      $GLOBALS['_test_actions'],
      'the guard must not suppress processes whose event owner is present',
    );
    $this->assertCount(
      2,
      $GLOBALS['_test_actions'][FakeResolvedEvent::integration_action()],
      'both ignition and resume stay bound',
    );
  }
}
