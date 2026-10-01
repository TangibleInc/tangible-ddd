<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\V8;

use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Infra\Persistence\ProcessRepository;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\Delivery\Subscriber;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Tests\Integration\V8\Fakes\V8Fact;
use TangibleDDD\Tests\Integration\V8\Fakes\V8IgnitedProcess;
use TangibleDDD\WordPress\Adapter\WpDeliveryLedger;
use TangibleDDD\WordPress\Adapter\WpLedgeredDelivery;

use function TangibleDDD\WordPress\integration_action;
use function TangibleDDD\WordPress\register_delivery_hooks;

/**
 * Per-callback invoker wrapping on wp (register 3.5, 5.1; WPC-2): every
 * DDD-registered callback on a fact's 0.6 hook is a ledgered subscriber,
 * isolated from the others, retried through `{prefix}_ddd_redeliver` and
 * budgeted; id-less payloads bypass the ledger (wave1-notes).
 */
final class WpDeliveryV8Test extends V8TestCase {

  private const EVENT_ID = 'f0000000-0000-4000-8000-0000000000aa';

  private string $hook;

  /** @var array<string, int> */
  private array $runs = [];

  protected function setUp(): void {
    parent::setUp();
    $this->installV8();
    WpLedgeredDelivery::resetForTests();
    $this->hook = V8Fact::integration_action();
    $this->runs = [];
    V8IgnitedProcess::$runs = 0;
  }

  protected function tearDown(): void {
    WpLedgeredDelivery::resetForTests();
    parent::tearDown();
  }

  private function wrapped(int $n = 1, string $eventId = self::EVENT_ID): array {
    return IntegrationEnvelope::wrap((new V8Fact($n))->integration_payload(), '44444444-4444-4444-8444-444444444444', 1, $eventId);
  }

  private function ledger(): WpDeliveryLedger {
    return new WpDeliveryLedger($this->config->prefix());
  }

  /** A DDD listener counted under $name that throws while $failures > 0. */
  private function listen(string $name, int &$failures, int $priority = 10): void {
    integration_action(V8Fact::class, function (array $payload) use ($name, &$failures): void {
      $this->runs[$name] = ($this->runs[$name] ?? 0) + 1;
      if ($failures > 0) {
        $failures--;
        throw new \RuntimeException("$name is down");
      }
    }, $priority);
  }

  public function test_the_ledger_counts_attempts_and_never_downgrades_a_delivery(): void {
    $l = $this->ledger();
    self::assertSame([false, 0, null, false], [$l->delivered('s', 'e'), $l->attempts('s', 'e'), $l->lastError('s', 'e'), $l->exhausted('s', 'e')]);

    $l->markFailed('s', 'e', 'boom', 1);
    $l->markFailed('s', 'e', 'boom again', 2);
    self::assertSame([2, 'boom again', false], [$l->attempts('s', 'e'), $l->lastError('s', 'e'), $l->delivered('s', 'e')]);

    $l->markDelivered('s', 'e');
    $l->markFailed('s', 'e', 'late', 3);
    self::assertTrue($l->delivered('s', 'e'));
    self::assertSame(2, $l->attempts('s', 'e'));

    $long = str_repeat('x', 400);
    $l->markExhausted($long, 'e');
    self::assertTrue($l->exhausted($long, 'e'));
    self::assertNotNull($this->wpdb->get_var("SELECT exhausted_at FROM `{$this->table('ddd_delivery_ledger')}` WHERE subscriber_id = '$long'"));
  }

  public function test_a_throwing_listener_is_isolated_and_only_it_is_redelivered(): void {
    $failA = 1;
    $failB = 0;
    $this->listen('a', $failA, 10);
    $this->listen('b', $failB, 20);
    $raw = 0;
    add_action($this->hook, static function () use (&$raw): void { $raw++; }, 30);
    register_delivery_hooks($this->config);

    do_action($this->hook, $this->wrapped());

    self::assertSame(['a' => 1, 'b' => 1], $this->runs, 'b still ran after a threw');
    self::assertSame(1, $raw, 'raw callbacks after it still ran too');
    [$a, $b] = WpLedgeredDelivery::subscribers($this->hook);
    self::assertSame([1, false, true], [$this->ledger()->attempts($a, self::EVENT_ID), $this->ledger()->delivered($a, self::EVENT_ID), $this->ledger()->delivered($b, self::EVENT_ID)]);

    $redeliveries = $this->pendingActions('ddd8it_ddd_redeliver');
    self::assertCount(1, $redeliveries);
    self::assertSame(['hook' => $this->hook, 'event_class' => V8Fact::class, 'payload' => $this->wrapped()], $redeliveries[0]->args);
    self::assertEqualsWithDelta(time() + 30, $redeliveries[0]->due, 5, 'handler backoff 30 s × 2^0');

    \ActionScheduler::runner()->process_action($redeliveries[0]->id, 'ddd-v8-test');

    self::assertSame(['a' => 2, 'b' => 1], $this->runs, 'the retry runs only the failed subscriber');
    self::assertSame(1, $raw, 'and never a raw callback');
    self::assertTrue($this->ledger()->delivered($a, self::EVENT_ID));
    self::assertSame([], $this->pendingActions('ddd8it_ddd_redeliver'));
  }

  public function test_a_double_delivery_runs_each_subscriber_once(): void {
    $none = 0;
    $this->listen('a', $none);
    do_action($this->hook, $this->wrapped());
    do_action($this->hook, $this->wrapped());
    self::assertSame(['a' => 1], $this->runs);
  }

  public function test_the_budget_exhausts_and_fires_the_compensation_once(): void {
    $compensated = [];
    HostDefaults::get(ISubscriptionRegistry::class)->add(new Subscriber(
      'ddd8it/listener:always-down',
      Subscriber::LISTENER,
      V8Fact::class,
      static function (): void { throw new \RuntimeException('down for good'); },
      static function (V8Fact $e, \Throwable $last) use (&$compensated): void { $compensated[] = [$e->n, $last->getMessage()]; },
    ));

    for ($i = 0; $i < 7; $i++) {
      WpLedgeredDelivery::redeliver($this->hook, V8Fact::class, $this->wrapped(4));
    }

    $l = $this->ledger();
    self::assertSame([5, true], [$l->attempts('ddd8it/listener:always-down', self::EVENT_ID), $l->exhausted('ddd8it/listener:always-down', self::EVENT_ID)]);
    self::assertSame([[4, 'down for good']], $compensated);
  }

  public function test_an_id_less_payload_bypasses_the_ledger_with_0_6_semantics(): void {
    $fail = 1;
    $this->listen('a', $fail);

    try {
      do_action($this->hook, (new V8Fact(1))->integration_payload());
      self::fail('an id-less delivery keeps the 0.6 propagation');
    } catch (\RuntimeException $e) {
      self::assertSame('a is down', $e->getMessage());
    }
    self::assertSame('0', (string) $this->wpdb->get_var("SELECT COUNT(*) FROM `{$this->table('ddd_delivery_ledger')}`"));
    self::assertSame([], $this->pendingActions('ddd8it_ddd_redeliver'));
  }

  public function test_before_the_v8_migration_a_throw_still_aborts_do_action(): void {
    update_option($this->config->option('ddd_schema_version'), 7, false);
    $fail = 1;
    $none = 0;
    $this->listen('a', $fail, 10);
    $this->listen('b', $none, 20);

    try {
      do_action($this->hook, $this->wrapped());
      self::fail('0.6 semantics before v8');
    } catch (\RuntimeException) {
    }
    self::assertSame(['a' => 1], $this->runs);
  }

  public function test_a_listener_subscriber_id_is_its_class(): void {
    $listener = new class {
      public function bind(): void {
        \TangibleDDD\WordPress\integration_listener(V8Fact::class, fn () => null);
      }
    };
    $listener->bind();
    self::assertSame(['listener:' . get_class($listener)], WpLedgeredDelivery::subscribers($this->hook));
  }

  public function test_a_redelivered_igniting_fact_ignites_once(): void {
    $runner = new ProcessRunner($this->config, new ProcessRepository($this->config));
    $runner->register_start(V8IgnitedProcess::class, V8Fact::class);

    do_action($this->hook, $this->wrapped(9));
    do_action($this->hook, $this->wrapped(9));
    WpLedgeredDelivery::redeliver($this->hook, V8Fact::class, $this->wrapped(9));

    self::assertSame(1, V8IgnitedProcess::$runs);
    self::assertSame('1', (string) $this->wpdb->get_var("SELECT COUNT(*) FROM `{$this->table('long_processes')}`"));
    self::assertSame('ignition', (string) $this->wpdb->get_var("SELECT start_path FROM `{$this->table('long_processes')}`"));
    self::assertTrue($this->ledger()->delivered('ddd8it/ignition:' . V8IgnitedProcess::class . '@' . V8Fact::class, self::EVENT_ID));
  }
}
