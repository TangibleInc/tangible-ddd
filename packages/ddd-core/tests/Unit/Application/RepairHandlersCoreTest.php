<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\CommandHandlers\DiscardDeadLetterHandler;
use TangibleDDD\Application\CommandHandlers\PurgeOutboxHandler;
use TangibleDDD\Application\CommandHandlers\ReplayDeadLetterHandler;
use TangibleDDD\Application\CommandHandlers\RetryDeliveryHandler;
use TangibleDDD\Application\Commands\DiscardDeadLetterCommand;
use TangibleDDD\Application\Commands\PurgeOutboxCommand;
use TangibleDDD\Application\Commands\ReplayDeadLetterCommand;
use TangibleDDD\Application\Commands\RetryDeliveryCommand;
use TangibleDDD\Infra\IConsumerIdentity;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IHostPortFactory;
use TangibleDDD\Runtime\Outbox\IOutboxAdministration;
use TangibleDDD\Runtime\Outbox\IOutboxRowIds;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Runtime\Outbox\OutboxRowNotFound;
use TangibleDDD\Testing\InMemoryOutboxStore;

/** Register 1.4: the four repair handlers are orchestration over IOutboxAdministration. */
final class RepairHandlersCoreTest extends TestCase {

  private FrozenClock $clock;
  private InMemoryOutboxStore $store;

  protected function setUp(): void {
    HostDefaults::reset_for_tests();
    $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-01 12:00:00', new \DateTimeZone('UTC')));
    $this->store = new InMemoryOutboxStore($this->clock);
  }

  protected function tearDown(): void {
    HostDefaults::reset_for_tests();
  }

  private function deadLetter(string $id): int {
    $this->store->append(new OutboxRecord($id, 't', 'a', 'c', 1, null, [], $this->clock->now(), max_attempts: 1));
    [$claim] = $this->store->claim(1, $this->clock->now(), 60);
    $this->store->dead_letter($claim, 'boom');
    return $this->store->dead_letters(10)[0]->dlq_id;
  }

  public function test_replay_keeps_the_event_id(): void {
    $dlq = $this->deadLetter('evt-1');

    (new ReplayDeadLetterHandler($this->store))->handle(new ReplayDeadLetterCommand('acme', $dlq));

    self::assertSame('pending', $this->store->status_of('evt-1'), 'the original row, same event_id');
    self::assertSame([], $this->store->dead_letters(10));
  }

  public function test_discard_removes_the_dead_letter(): void {
    $dlq = $this->deadLetter('evt-2');

    (new DiscardDeadLetterHandler($this->store))->handle(new DiscardDeadLetterCommand('acme', $dlq));

    self::assertSame([], $this->store->dead_letters(10));
    $this->expectException(OutboxRowNotFound::class);
    (new DiscardDeadLetterHandler($this->store))->handle(new DiscardDeadLetterCommand('acme', $dlq));
  }

  public function test_purge_passes_the_cutoff(): void {
    $admin = $this->createMock(IOutboxAdministration::class);
    $admin->expects(self::once())->method('purge')
      ->with(self::equalTo(new \DateTimeImmutable('2026-09-01 12:00:00', new \DateTimeZone('UTC'))))
      ->willReturn(3);

    (new PurgeOutboxHandler($admin, $this->clock))->handle(new PurgeOutboxCommand('acme', 30));
  }

  public function test_retry_maps_the_integer_id_to_the_event_id(): void {
    $admin = $this->createMockForIntersectionOfInterfaces([IOutboxAdministration::class, IOutboxRowIds::class]);
    $admin->method('event_id_of')->with(17)->willReturn('evt-17');
    $admin->expects(self::once())->method('retry')->with('evt-17', false);

    (new RetryDeliveryHandler($admin))->handle(new RetryDeliveryCommand('acme', 17));
  }

  public function test_retry_of_an_unknown_integer_id_is_not_found(): void {
    $admin = $this->createMockForIntersectionOfInterfaces([IOutboxAdministration::class, IOutboxRowIds::class]);
    $admin->method('event_id_of')->willReturn(null);

    $this->expectException(OutboxRowNotFound::class);
    (new RetryDeliveryHandler($admin))->handle(new RetryDeliveryCommand('acme', 99));
  }

  public function test_retry_needs_integer_ids(): void {
    $this->expectException(\LogicException::class);
    (new RetryDeliveryHandler($this->store))->handle(new RetryDeliveryCommand('acme', 1));
  }

  public function test_the_host_supplies_the_administration_for_the_command_prefix(): void {
    $dlq = $this->deadLetter('evt-3');
    $store = $this->store;
    HostDefaults::provide(IHostPortFactory::class, new class($store) implements IHostPortFactory {
      public array $prefixes = [];
      public function __construct(private object $store) {}
      public function create(string $port, IConsumerIdentity $consumer, ?object $legacy = null): ?object {
        $this->prefixes[] = $consumer->prefix();
        return $port === IOutboxAdministration::class ? $this->store : null;
      }
    });

    (new DiscardDeadLetterHandler())->handle(new DiscardDeadLetterCommand('ghost_plugin', $dlq));

    self::assertSame([], $this->store->dead_letters(10));
  }

  public function test_without_an_administration_the_handler_fails_loudly(): void {
    $this->expectException(\LogicException::class);
    (new DiscardDeadLetterHandler())->handle(new DiscardDeadLetterCommand('acme', 1));
  }
}
