<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\V8;

use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\Delivery\Subscriber;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistrar;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Tests\Integration\V8\Fakes\V8Charge;
use TangibleDDD\Tests\Integration\V8\Fakes\V8ChargeFailed;
use TangibleDDD\Tests\Integration\V8\Fakes\V8ChargeListener;
use TangibleDDD\Tests\Integration\V8\Fakes\V8Fact;
use TangibleDDD\Tests\Integration\V8\Fakes\V8PatientChargeListener;
use TangibleDDD\WordPress\Adapter\WpDeliveryLedger;
use TangibleDDD\WordPress\Adapter\WpLedgeredDelivery;
use TangibleDDD\WordPress\Adapter\WpOperatorView;
use TangibleDDD\WordPress\Retries;

use function TangibleDDD\WordPress\integration_action;
use function TangibleDDD\WordPress\register_delivery_hooks;

/**
 * The wp delivery budget (wave 5 coordinator decision): a DDD-registered
 * listener of a 0.6-era consumer gets ONE attempt, as in 0.6, and its
 * failure is still recorded in the ledger as exhausted with last_error and
 * shown by the operator view (`wp ddd ops`). A consumer opts in with the
 * option `{prefix}_ddd_delivery_attempts`, a listener with #[Retries(n)],
 * and the filter `tangible_ddd_delivery_attempts` has the last word.
 * Process ignition and resume subscribers keep the core budget.
 */
final class WpDeliveryBudgetV8Test extends V8TestCase {

  private const EVENT_ID = 'f0000000-0000-4000-8000-0000000000bb';

  private string $hook;

  private int $runs = 0;

  protected function setUp(): void {
    parent::setUp();
    $this->installV8();
    WpLedgeredDelivery::reset_for_tests();
    $this->hook = V8Fact::integration_action();
    $this->runs = 0;
    V8Charge::$sends = 0;
    V8ChargeFailed::$sent = [];
    register_delivery_hooks($this->config);
  }

  protected function tearDown(): void {
    WpLedgeredDelivery::reset_for_tests();
    parent::tearDown();
  }

  private function wrapped(int $n = 1): array {
    return IntegrationEnvelope::wrap((new V8Fact($n))->integration_payload(), '44444444-4444-4444-8444-444444444444', 1, self::EVENT_ID);
  }

  private function ledger(): WpDeliveryLedger {
    return new WpDeliveryLedger($this->config->prefix());
  }

  /** Run every pending redelivery of the consumer, up to $rounds times. */
  private function redeliverAll(int $rounds = 10): void {
    for ($i = 0; $i < $rounds; $i++) {
      $pending = $this->pendingActions('ddd8it_ddd_redeliver');
      if ($pending === []) {
        return;
      }
      foreach ($pending as $action) {
        \ActionScheduler::runner()->process_action($action->id, 'ddd-v8-test');
      }
    }
  }

  private function failingAction(?\Closure $callback = null): void {
    integration_action(V8Fact::class, $callback ?? function (): void {
      $this->runs++;
      throw new \RuntimeException('mailer down');
    });
  }

  public function test_a_listener_gets_one_attempt_and_its_failure_is_recorded_as_exhausted(): void {
    $this->failingAction();

    do_action($this->hook, $this->wrapped());
    do_action($this->hook, $this->wrapped());
    WpLedgeredDelivery::redeliver($this->hook, V8Fact::class, $this->wrapped());

    [$id] = WpLedgeredDelivery::subscribers($this->hook);
    $l = $this->ledger();
    self::assertSame(1, $this->runs, 'no surprise repeat of the side effect');
    self::assertSame([true, 1, 'mailer down'], [$l->exhausted($id, self::EVENT_ID), $l->attempts($id, self::EVENT_ID), $l->last_error($id, self::EVENT_ID)]);
    self::assertSame([], $this->pendingActions('ddd8it_ddd_redeliver'), 'nothing is scheduled');
    self::assertSame(0, WpLedgeredDelivery::restore_redeliveries($this->config));
  }

  public function test_the_operator_view_shows_the_exhausted_listener(): void {
    $this->failingAction();
    do_action($this->hook, $this->wrapped());
    [$id] = WpLedgeredDelivery::subscribers($this->hook);

    [$item] = (new WpOperatorView($this->config))->list('delivery');

    self::assertSame("$id @ " . self::EVENT_ID, $item['key']);
    self::assertSame([1, 1, 'mailer down', []], [$item['attempts'], $item['budget'], $item['last_error'], $item['repair_actions']]);
  }

  public function test_the_consumer_option_opts_its_listeners_into_retries(): void {
    update_option($this->config->option(WpLedgeredDelivery::ATTEMPTS_OPTION), 3, false);
    $this->failingAction();

    do_action($this->hook, $this->wrapped());
    [$id] = WpLedgeredDelivery::subscribers($this->hook);
    self::assertSame([false, 1], [$this->ledger()->exhausted($id, self::EVENT_ID), $this->ledger()->attempts($id, self::EVENT_ID)]);
    self::assertCount(1, $this->pendingActions('ddd8it_ddd_redeliver'));

    $this->redeliverAll();

    self::assertSame(3, $this->runs);
    self::assertSame([true, 3, 'mailer down'], [$this->ledger()->exhausted($id, self::EVENT_ID), $this->ledger()->attempts($id, self::EVENT_ID), $this->ledger()->last_error($id, self::EVENT_ID)]);
    self::assertSame(3, (new WpOperatorView($this->config))->list('delivery')[0]['budget']);
  }

  public function test_a_listener_recovers_within_an_opted_in_budget(): void {
    update_option($this->config->option(WpLedgeredDelivery::ATTEMPTS_OPTION), '5', false);
    $this->failingAction(function (): void {
      if (++$this->runs < 3) {
        throw new \RuntimeException('flaky');
      }
    });

    do_action($this->hook, $this->wrapped());
    $this->redeliverAll();

    [$id] = WpLedgeredDelivery::subscribers($this->hook);
    self::assertSame(3, $this->runs);
    self::assertTrue($this->ledger()->delivered($id, self::EVENT_ID));
  }

  public function test_retries_on_an_integration_action_closure(): void {
    $this->failingAction(#[Retries(1)] function (): void {
      $this->runs++;
      throw new \RuntimeException('mailer down');
    });

    do_action($this->hook, $this->wrapped());
    $this->redeliverAll();

    [$id] = WpLedgeredDelivery::subscribers($this->hook);
    self::assertSame(2, $this->runs, 'one retry');
    self::assertSame([true, 2], [$this->ledger()->exhausted($id, self::EVENT_ID), $this->ledger()->attempts($id, self::EVENT_ID)]);
  }

  public function test_the_filter_has_the_last_word(): void {
    update_option($this->config->option(WpLedgeredDelivery::ATTEMPTS_OPTION), 5, false);
    $filter = static fn (int $attempts, string $subscriber, string $prefix): int => $prefix === 'ddd8it' ? 2 : $attempts;
    add_filter(WpLedgeredDelivery::ATTEMPTS_FILTER, $filter, 10, 3);
    try {
      $this->failingAction();
      do_action($this->hook, $this->wrapped());
      $this->redeliverAll();
    } finally {
      remove_filter(WpLedgeredDelivery::ATTEMPTS_FILTER, $filter, 10);
    }

    self::assertSame(2, $this->runs);
  }

  public function test_a_d1_listener_fires_its_failure_command_once_on_the_one_attempt(): void {
    (new SubscriptionRegistrar(HostDefaults::get(ISubscriptionRegistry::class)))->register_listener(V8ChargeListener::class);

    do_action($this->hook, $this->wrapped(7));
    do_action($this->hook, $this->wrapped(7));
    WpLedgeredDelivery::redeliver($this->hook, V8Fact::class, $this->wrapped(7));
    $this->redeliverAll();

    $id = 'listener:' . V8ChargeListener::class;
    self::assertSame(1, V8Charge::$sends, 'the provider is called once');
    self::assertSame([['n' => 7, 'error' => 'provider down for charge 7']], V8ChargeFailed::$sent, 'failure_command fires once');
    self::assertSame([true, 'provider down for charge 7'], [$this->ledger()->exhausted($id, self::EVENT_ID), $this->ledger()->last_error($id, self::EVENT_ID)]);
  }

  public function test_retries_on_a_registrar_listener_class(): void {
    (new SubscriptionRegistrar(HostDefaults::get(ISubscriptionRegistry::class)))->register_listener(V8PatientChargeListener::class);

    do_action($this->hook, $this->wrapped(8));
    self::assertSame([], V8ChargeFailed::$sent, 'not yet: two retries left');
    $this->redeliverAll();

    self::assertSame(3, V8Charge::$sends, '#[Retries(2)]: three attempts');
    self::assertSame([['n' => 8, 'error' => 'provider down for charge 8']], V8ChargeFailed::$sent, 'then failure_command, once');
    self::assertTrue($this->ledger()->exhausted('listener:' . V8PatientChargeListener::class, self::EVENT_ID));
  }

  public function test_ignition_and_resume_subscribers_keep_the_core_budget(): void {
    $runs = ['ignition' => 0, 'resume' => 0];
    foreach (['ignition' => 'ddd8it/ignition:Acme\\Proc@' . V8Fact::class, 'resume' => 'ddd8it/resume:' . V8Fact::class] as $kind => $id) {
      HostDefaults::get(ISubscriptionRegistry::class)->add(new Subscriber(
        $id,
        $kind === 'ignition' ? Subscriber::IGNITION : Subscriber::RESUME,
        V8Fact::class,
        static function () use (&$runs, $kind): void {
          $runs[$kind]++;
          throw new \RuntimeException("$kind lock busy");
        },
      ));
    }

    do_action($this->hook, $this->wrapped());
    $this->redeliverAll();

    self::assertSame(['ignition' => WpLedgeredDelivery::BUDGET, 'resume' => WpLedgeredDelivery::BUDGET], $runs);
    self::assertTrue($this->ledger()->exhausted('ddd8it/resume:' . V8Fact::class, self::EVENT_ID));
    self::assertSame(5, (new WpOperatorView($this->config))->list('delivery')[0]['budget']);
  }
}
