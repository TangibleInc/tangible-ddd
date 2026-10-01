<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Unit\Messenger;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\Kind;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Delivery\Subscriber;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;
use TangibleDDD\Symfony\Messenger\FactDeliveryIncomplete;
use TangibleDDD\Symfony\Messenger\IntegrationFactHandler;
use TangibleDDD\Symfony\Messenger\IntegrationFactMessage;
use TangibleDDD\Symfony\Tests\Support\Fixtures\PingFact;
use TangibleDDD\Testing\InMemoryDeliveryLedger;

final class IntegrationFactHandlerTest extends TestCase {

  private SubscriptionRegistry $registry;
  private InMemoryDeliveryLedger $ledger;

  /** @var list<string> */
  private array $calls = [];

  protected function setUp(): void {
    $this->registry = new SubscriptionRegistry();
    $this->ledger = new InMemoryDeliveryLedger();
  }

  protected function tearDown(): void {
    Correlation::reset();
  }

  private function handler(int $budget = 5): IntegrationFactHandler {
    $delivery = new IntegrationDelivery($this->registry, $this->ledger, $budget, new NullLogger());
    return new IntegrationFactHandler($delivery, 'txp');
  }

  private function message(string $consumer = 'txp', string $class = PingFact::class): IntegrationFactMessage {
    return new IntegrationFactMessage($consumer, 'evt-1', 'ping_fact', $class, 'sft_integration_ping_fact',
      IntegrationEnvelope::wrap(['n' => 7], 'corr-1', 4, 'evt-1'));
  }

  private function subscribe(string $id, int $priority, ?\Closure $body = null): void {
    $this->registry->add(new Subscriber($id, $priority, PingFact::class, function (PingFact $e, string $eventId) use ($id, $body) {
      $this->calls[] = $id;
      $body?->__invoke($e, $eventId);
    }));
  }

  public function test_runs_listeners_then_ignition_then_resume_inside_the_fact_scope(): void {
    $seen = [];
    $this->subscribe('resume', Subscriber::RESUME);
    $this->subscribe('ignite', Subscriber::IGNITION);
    $this->subscribe('listener', Subscriber::LISTENER, function (PingFact $e, string $eventId) use (&$seen) {
      $ctx = Correlation::peek();
      $seen = [$e->n, $eventId, $ctx?->correlation_id, $ctx?->cause?->kind, $ctx?->cause?->id];
    });

    $outcome = ($this->handler())($this->message());

    self::assertSame(['listener', 'ignite', 'resume'], $this->calls);
    self::assertSame([7, 'evt-1', 'corr-1', Kind::Fact, 'evt-1'], $seen);
    self::assertSame(['listener', 'ignite', 'resume'], $outcome->delivered);
    self::assertNull(Correlation::peek(), 'the fact scope is closed afterwards');
  }

  public function test_a_second_delivery_of_the_same_fact_runs_nothing(): void {
    $this->subscribe('listener', Subscriber::LISTENER);
    $handler = $this->handler();

    $handler($this->message());
    $again = $handler($this->message());

    self::assertSame(['listener'], $this->calls);
    self::assertSame(['listener'], $again->skipped);
  }

  public function test_a_failed_subscriber_makes_messenger_retry_and_the_retry_runs_only_it(): void {
    $failOnce = true;
    $this->subscribe('a', Subscriber::LISTENER);
    $this->subscribe('b', Subscriber::LISTENER, function () use (&$failOnce) {
      if ($failOnce) {
        $failOnce = false;
        throw new \RuntimeException('b down');
      }
    });
    $handler = $this->handler();

    try {
      $handler($this->message());
      self::fail('expected FactDeliveryIncomplete');
    } catch (FactDeliveryIncomplete $e) {
      self::assertSame(['b'], $e->outcome->failed);
      self::assertStringContainsString('evt-1', $e->getMessage());
    }
    $handler($this->message());

    self::assertSame(['a', 'b', 'b'], $this->calls);
  }

  public function test_an_exhausted_subscriber_no_longer_asks_for_a_retry(): void {
    $this->subscribe('b', Subscriber::LISTENER, function () {
      throw new \RuntimeException('always');
    });
    $handler = $this->handler(budget: 2);

    try {
      $handler($this->message());
      self::fail('first attempt retries');
    } catch (FactDeliveryIncomplete) {
    }
    $outcome = $handler($this->message());

    self::assertSame(['b'], $outcome->exhausted);
    self::assertTrue($this->ledger->exhausted('b', 'evt-1'));
  }

  public function test_a_message_for_another_consumer_is_unrecoverable(): void {
    $this->expectException(UnrecoverableMessageHandlingException::class);
    ($this->handler())($this->message(consumer: 'other'));
  }

  public function test_an_unknown_or_non_fact_class_is_unrecoverable(): void {
    try {
      ($this->handler())($this->message(class: 'App\\Gone'));
      self::fail('missing class');
    } catch (UnrecoverableMessageHandlingException) {
    }
    $this->expectException(UnrecoverableMessageHandlingException::class);
    ($this->handler())($this->message(class: \stdClass::class));
  }
}
