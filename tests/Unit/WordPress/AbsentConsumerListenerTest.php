<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\WordPress;

use PHPUnit\Framework\TestCase;
use Tangible\DeactivatedPlugin\Domain\Events\EventFromAnAbsentPlugin;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;

use function TangibleDDD\WordPress\integration_action;
use function TangibleDDD\WordPress\integration_listener;

require_once __DIR__ . '/../../Fakes/Absent/EventFromAnAbsentPlugin.php';

if (!function_exists('TangibleDDD\\WordPress\\integration_listener')) {
  require_once __DIR__ . '/../../../ddd-wordpress/integration-events.php';
}

/**
 * Deactivating a plugin must not take the site down.
 *
 * A cross-plugin listener names an event class owned by another plugin. When
 * that plugin is deactivated its namespace root is no longer registered, and
 * resolving the hook name throws NoConsumerOwnsClass. Listeners wire at
 * `init` priority 3 — after every active plugin has registered — so an
 * unresolvable root there means the owner is genuinely absent, not late.
 * The event can never fire, so the listener is simply skipped.
 *
 * Regression: tangible-ddd-mega-trace's FleetPolicies registered a listener
 * on a Tangible\Datastream event; deactivating Datastream fatalled every
 * request from wp-settings.php, admin included.
 */
final class AbsentConsumerListenerTest extends TestCase {

  protected function setUp(): void {
    ConsumerRegistry::reset();
    $GLOBALS['_test_actions'] = [];
  }

  protected function tearDown(): void {
    ConsumerRegistry::reset();
  }

  public function test_integration_listener_skips_an_event_no_consumer_owns(): void {
    integration_listener(
      EventFromAnAbsentPlugin::class,
      static fn (EventFromAnAbsentPlugin $event) => null,
    );

    $this->assertSame(
      [],
      $GLOBALS['_test_actions'],
      'no hook is registered for an event whose owning plugin is deactivated',
    );
  }

  public function test_integration_action_skips_an_event_no_consumer_owns(): void {
    integration_action(EventFromAnAbsentPlugin::class, static fn () => null);

    $this->assertSame([], $GLOBALS['_test_actions']);
  }

  public function test_a_resolvable_event_still_registers_its_hook(): void {
    integration_listener(FakeResolvedEvent::class, static fn () => null);

    $this->assertArrayHasKey(
      FakeResolvedEvent::integration_action(),
      $GLOBALS['_test_actions'],
      'the guard must not suppress listeners whose owner is present',
    );
  }
}
