<?php

namespace TangibleDDD\Tests\Unit\Outbox;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Infra\Consumers\ConsumerHandle;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\Services\DueWorkSettler;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;

class DueWorkSettlerTest extends TestCase {

  public function test_nothing_due_settles_in_one_pass(): void {
    $settler = new DueWorkSettler(
      ['test' => $this->handle(new FakeDDDConfig())],
      static fn(ConsumerHandle $handle): int => 0,
      static fn(array $prefixes): array => [],
      static function(int $id): void {},
    );

    $result = $settler->settle();

    $this->assertSame(1, $result->passes);
    $this->assertSame(0, $result->outbox_rows);
    $this->assertSame(0, $result->actions_run);
    $this->assertTrue($result->settled);
  }

  public function test_passes_repeat_while_work_keeps_appearing(): void {
    // Relaying a row enqueues its delivery action; running that action
    // records a reaction whose row the next pass relays.
    $relayed = [2, 1, 0, 0];
    $due = [[], [10, 11], [12], []];
    $ran = [];

    $settler = new DueWorkSettler(
      ['test' => $this->handle(new FakeDDDConfig())],
      static function(ConsumerHandle $handle) use (&$relayed): int {
        return array_shift($relayed) ?? 0;
      },
      static function(array $prefixes) use (&$due): array {
        return array_shift($due) ?? [];
      },
      static function(int $id) use (&$ran): void {
        $ran[] = $id;
      },
    );

    $result = $settler->settle();

    $this->assertSame([10, 11, 12], $ran);
    $this->assertSame(4, $result->passes);
    $this->assertSame(3, $result->outbox_rows);
    $this->assertSame(3, $result->actions_run);
    $this->assertTrue($result->settled);
  }

  public function test_stops_at_the_pass_limit_without_claiming_to_be_settled(): void {
    $settler = new DueWorkSettler(
      ['test' => $this->handle(new FakeDDDConfig())],
      static fn(ConsumerHandle $handle): int => 1,
      static fn(array $prefixes): array => [],
      static function(int $id): void {},
    );

    $result = $settler->settle(3);

    $this->assertSame(3, $result->passes);
    $this->assertSame(3, $result->outbox_rows);
    $this->assertFalse($result->settled);
  }

  public function test_every_consumer_is_relayed_and_their_groups_are_searched(): void {
    $relayed_prefixes = [];
    $searched = null;

    $settler = new DueWorkSettler(
      [
        'test' => $this->handle(new FakeDDDConfig()),
        'acme_orders' => $this->handle($this->config_with_prefix('acme_orders')),
      ],
      static function(ConsumerHandle $handle) use (&$relayed_prefixes): int {
        $relayed_prefixes[] = $handle->prefix();
        return 0;
      },
      static function(array $prefixes) use (&$searched): array {
        $searched = $prefixes;
        return [];
      },
      static function(int $id): void {},
    );

    $settler->settle();

    $this->assertSame(['test', 'acme_orders'], $relayed_prefixes);
    // acme_orders' as_group() hyphenates its prefix, but the outbox
    // repositories queue deliveries on the raw prefix ('acme_orders-outbox'),
    // so both spellings are searched; 'test' spells both the same.
    $this->assertSame(['test-', 'acme-orders-', 'acme_orders-'], $searched);
  }

  public function test_result_serializes_for_the_rest_route(): void {
    $settler = new DueWorkSettler(
      [],
      static fn(ConsumerHandle $handle): int => 0,
      static fn(array $prefixes): array => [],
      static function(int $id): void {},
    );

    $this->assertSame(
      ['passes' => 1, 'outbox_rows' => 0, 'actions_run' => 0, 'settled' => true],
      $settler->settle()->to_array(),
    );
  }

  private function handle(IDDDConfig $config): ConsumerHandle {
    return new ConsumerHandle($config, static fn(): object => new \stdClass());
  }

  private function config_with_prefix(string $prefix): IDDDConfig {
    return new class($prefix) implements IDDDConfig {
      public function __construct(private readonly string $prefix) {}
      public function prefix(): string { return $this->prefix; }
      public function table(string $name): string { return 'wp_' . $this->prefix . '_' . $name; }
      public function hook(string $name): string { return $this->prefix . '_' . $name; }
      public function as_group(string $name): string { return str_replace('_', '-', $this->prefix) . '-' . $name; }
      public function option(string $name): string { return $this->prefix . '_' . $name; }
      public function domain_action(string $event_name): string { return $this->prefix . '_domain_' . $event_name; }
      public function integration_action(string $event_name): string { return $this->prefix . '_integration_' . $event_name; }
      public function version(): string { return 'test'; }
    };
  }
}
