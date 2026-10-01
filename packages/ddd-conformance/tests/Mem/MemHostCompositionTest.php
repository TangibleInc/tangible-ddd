<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Tests\Mem;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\ConformanceTestCase;
use TangibleDDD\Conformance\Fixtures\WidgetRegistered;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Mem\MemHostFixture;

/**
 * Not a scenario: pins that the mem host runs the scenarios on the real
 * ddd-core pipeline classes (CONF-1..3), not on conformance-owned
 * stand-ins, and that the bootstrap maps no source outside ddd-core.
 *
 * Each check looks for a behaviour only the core class has.
 */
#[Group('mem')]
final class MemHostCompositionTest extends ConformanceTestCase {

  private MemHostFixture $mem;

  protected function create_fixture(): HostFixture {
    return $this->mem = new MemHostFixture();
  }

  public function test_conformance_owns_no_pipeline_piece_but_the_handler_map(): void {
    $middlewares = $buses = $relays = [];
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', \FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
      $source = (string) file_get_contents($file->getPathname());
      $name = $file->getBasename('.php');
      if (preg_match('/implements[^{]*\bMiddleware\b/', $source)) {
        $middlewares[] = $name;
      }
      if (preg_match('/implements[^{]*\bIIntegrationEventBus\b/', $source)) {
        $buses[] = $name;
      }
      if (str_contains($source, '->submit(')) {
        $relays[] = $name;
      }
    }

    self::assertSame(['HandlerMapMiddleware'], $middlewares, 'the act bracket is the core CorrelationMiddleware');
    self::assertSame([], $buses, 'the integration bus is the core OutboxIntegrationEventBus');
    self::assertSame([], $relays, 'the relay step is the core OutboxProcessor');
  }

  public function test_the_bootstrap_maps_no_ddd_wp_source(): void {
    self::assertFalse(class_exists('TangibleDDD\\Infra\\DDDConfig'), 'packages/ddd-wp/src is not autoloadable from conformance');
  }

  public function test_the_act_bracket_is_the_core_correlation_middleware(): void {
    $this->publish(new WidgetRegistered('w-1'));

    $opened = $this->mem->audit_sink()->opened;
    self::assertCount(1, $opened);
    // The core bracket appends the consumer's version as `plugin` (0.6 {php, wp, plugin}).
    self::assertSame(MemHostFixture::CONSUMER_VERSION, $opened[0]->environment['plugin'] ?? null);
    self::assertSame(32, strlen($opened[0]->command_id));
  }

  public function test_facts_go_through_the_core_outbox_bus(): void {
    $id = $this->publish(new WidgetRegistered('w-1'));

    // The core bus hands every published fact to the IFactObserver.
    $observed = $this->mem->fact_observer()->observed;
    self::assertCount(1, $observed);
    self::assertSame($id, $observed[0]['record']->event_id);
  }

  public function test_the_relay_step_is_the_core_outbox_processor(): void {
    $id = $this->publish(new WidgetRegistered('w-1'));

    self::assertSame([$id], $this->host->relay_once()->accepted);

    // OutboxProcessor logs `[{prefix}-outbox] COMPLETED: {json}` per accepted row.
    $completed = array_filter($this->mem->logs, static fn (string $l) => str_starts_with($l, '[' . MemHostFixture::CONSUMER_PREFIX . '-outbox] COMPLETED') && str_contains($l, $id));
    self::assertCount(1, $completed);
  }
}
