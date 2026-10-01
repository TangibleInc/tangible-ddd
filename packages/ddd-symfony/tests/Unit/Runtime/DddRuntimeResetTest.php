<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Runtime\RuntimeReset;
use TangibleDDD\Symfony\Runtime\Actor\ActorContext;
use TangibleDDD\Symfony\Runtime\DddRuntimeReset;
use TangibleDDD\Symfony\Tests\Support\Fixtures\PingDomainEvent;

final class DddRuntimeResetTest extends TestCase {

  private EventsUnitOfWork $events;
  private ActorContext $actors;
  private object $logger;
  private DddRuntimeReset $reset;

  protected function setUp(): void {
    RuntimeReset::forget_for_tests();
    $this->events = new EventsUnitOfWork();
    $this->actors = new ActorContext();
    $this->logger = new class extends AbstractLogger {
      public array $lines = [];
      public function log($level, \Stringable|string $message, array $context = []): void {
        $this->lines[] = [$level, (string) $message];
      }
    };
    $this->reset = new DddRuntimeReset($this->events, $this->actors, $this->logger);
    $this->reset->install();
  }

  protected function tearDown(): void {
    RuntimeReset::forget_for_tests();
    Correlation::reset();
  }

  public function test_it_listens_after_each_handled_or_failed_message(): void {
    $events = DddRuntimeReset::getSubscribedEvents();
    self::assertArrayHasKey(WorkerMessageHandledEvent::class, $events);
    self::assertArrayHasKey(WorkerMessageFailedEvent::class, $events);
  }

  public function test_a_clean_boundary_clears_the_unit_of_work_and_actor_quietly(): void {
    $this->events->record(new PingDomainEvent());
    $this->actors->set(new \TangibleDDD\Runtime\Audit\Actor(\TangibleDDD\Runtime\Audit\ActorKind::Machine, 'm'));

    $this->reset->on_handled(new WorkerMessageHandledEvent(new Envelope(new \stdClass()), 'ddd_facts'));

    self::assertSame([], $this->events->drain());
    self::assertNull($this->actors->get());
    self::assertSame([], $this->logger->lines);
  }

  public function test_a_leaked_correlation_scope_is_logged_loudly_and_the_next_message_starts_clean(): void {
    // A bracket bug: a flat caller minted an ambient story and left it behind.
    Correlation::current();
    self::assertNotNull(Correlation::peek());

    $this->reset->on_failed(new WorkerMessageFailedEvent(new Envelope(new \stdClass()), 'ddd_facts', new \RuntimeException('x')));

    self::assertNull(Correlation::peek(), 'cleaned before reporting');
    self::assertCount(1, $this->logger->lines);
    self::assertSame('critical', $this->logger->lines[0][0]);
    self::assertStringContainsString('Correlation', $this->logger->lines[0][1]);

    $this->reset->reset(); // kernel.reset path
    self::assertCount(1, $this->logger->lines, 'the next boundary is quiet');
  }
}
