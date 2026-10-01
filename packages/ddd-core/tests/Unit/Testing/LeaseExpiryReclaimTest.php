<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TangibleDDD\Application\Infrastructure\IInfrastructureEvent;
use TangibleDDD\Application\Infrastructure\OutboxDeadLettered;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Core\Tests\Unit\Fixtures\AcmeConfig;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingLogger;
use TangibleDDD\Infra\IConsumerIdentity;
use TangibleDDD\Infra\Services\OutboxProcessor;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IInfrastructureSignalDispatcher;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Runtime\Ops\PortOperatorView;
use TangibleDDD\Runtime\Outbox\IReportsClaimDeadLetters;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Testing\InMemoryOutboxStore;
use TangibleDDD\Testing\InMemoryTransactionBoundary;
use TangibleDDD\Testing\InMemoryTransport;
use TangibleDDD\Testing\StaticConsumerIdentity;

/**
 * CR-PDO-6 ruling (wave3-notes; was CR sf-8): a re-claim of an expired lease
 * counts as a relay attempt, and a row that reaches max_attempts through
 * re-claims is dead-lettered AT CLAIM and shows in the operator view. Core
 * relay step + the InMemoryOutboxStore double.
 */
final class LeaseExpiryReclaimTest extends TestCase {

  private const LEASE = 300;

  private FrozenClock $clock;
  private InMemoryTransactionBoundary $tx;
  private InMemoryOutboxStore $store;
  /** @var object{seen: list<IInfrastructureEvent>} */
  private object $signals;

  protected function setUp(): void {
    HostDefaults::reset_for_tests();
    HostDefaults::provide(LoggerInterface::class, new RecordingLogger());
    $this->signals = new class implements IInfrastructureSignalDispatcher {
      public array $seen = [];
      public function emit(IInfrastructureEvent $e, IConsumerIdentity $c): void { $this->seen[] = $e; }
    };
    HostDefaults::provide(IInfrastructureSignalDispatcher::class, $this->signals);

    $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-01 12:00:00', new \DateTimeZone('UTC')));
    $this->tx = new InMemoryTransactionBoundary();
    $this->store = new InMemoryOutboxStore($this->clock, null, $this->tx);
    $this->tx->enlist($this->store);
  }

  protected function tearDown(): void {
    HostDefaults::reset_for_tests();
  }

  private function append(string $id, int $max): void {
    $this->store->append(new OutboxRecord(
      $id, 'order_placed', 'acme_integration_order_placed', 'corr-' . $id, 1, null,
      ['order_id' => 1], $this->clock->now(), max_attempts: $max,
    ));
  }

  /** A submitter that claims and then dies without writing an outcome. */
  private function crashAfterClaim(): void {
    $this->store->claim(10, $this->clock->now(), self::LEASE);
    $this->clock->advance('PT' . (self::LEASE + 1) . 'S');
  }

  private function relay(): OutboxProcessor {
    return new OutboxProcessor(
      new AcmeConfig(), null, new OutboxConfig(lock_timeout_seconds: self::LEASE), null,
      null, null, $this->clock, $this->store, new InMemoryTransport(), $this->tx,
    );
  }

  public function test_the_store_reports_claim_time_dead_letters(): void {
    self::assertInstanceOf(IReportsClaimDeadLetters::class, $this->store);
  }

  public function test_a_first_claim_counts_nothing(): void {
    $this->append('e1', 5);

    $claims = $this->store->claim(10, $this->clock->now(), self::LEASE);

    self::assertSame(0, $claims[0]->attempts);
    self::assertSame(0, $this->store->attempts_of('e1'));
  }

  public function test_re_claiming_an_expired_lease_counts_an_attempt(): void {
    $this->append('e1', 5);
    $this->crashAfterClaim();

    $claims = $this->store->claim(10, $this->clock->now(), self::LEASE);

    self::assertCount(1, $claims);
    self::assertSame(1, $claims[0]->attempts);
    self::assertSame(1, $this->store->attempts_of('e1'));
    self::assertSame([], $this->store->take_claim_dead_letters());
  }

  public function test_a_row_reaching_max_attempts_through_re_claims_is_dead_lettered_at_claim(): void {
    $this->append('e1', 2);
    $this->crashAfterClaim();
    $this->crashAfterClaim();

    $claims = $this->store->claim(10, $this->clock->now(), self::LEASE);

    self::assertSame([], $claims, 'not handed out again');
    self::assertSame('dlq', $this->store->status_of('e1'));
    self::assertSame(2, $this->store->attempts_of('e1'));
    $letters = $this->store->dead_letters(10);
    self::assertCount(1, $letters);
    self::assertSame('e1', $letters[0]->event_id);
    self::assertStringContainsString(IReportsClaimDeadLetters::LEASE_EXPIRED_ERROR, $letters[0]->error);

    $taken = $this->store->take_claim_dead_letters();
    self::assertCount(1, $taken);
    self::assertSame('e1', $taken[0][0]->event_id);
    self::assertSame($letters[0]->error, $taken[0][1]);
    self::assertSame([], $this->store->take_claim_dead_letters(), 'emptied');
  }

  public function test_the_relay_step_reports_and_signals_a_claim_time_dead_letter_and_the_operator_view_lists_it(): void {
    $this->append('e1', 2);
    $this->append('e2', 5);
    $this->crashAfterClaim();
    $this->crashAfterClaim();

    $result = $this->relay()->process_batch();

    self::assertSame(['e1'], $result->claim_dead_letters);
    self::assertSame(['e2'], $result->accepted, 'the rest of the batch still relays');
    self::assertNotContains('e1', $result->claimed);
    $dead = array_values(array_filter($this->signals->seen, static fn ($s) => $s instanceof OutboxDeadLettered));
    self::assertCount(1, $dead);
    self::assertSame('e1', $dead[0]->entry()->event_id);

    $items = (new PortOperatorView(new StaticConsumerIdentity('acme'), $this->store))->list(Layer::Relay);
    self::assertSame(['e1'], array_map(static fn ($i) => $i->key, $items));
    self::assertSame(2, $items[0]->attempts);
    self::assertSame(2, $items[0]->budget);
  }

  public function test_a_relay_rejection_after_a_re_claim_continues_the_same_count(): void {
    $this->append('e1', 3);
    $this->crashAfterClaim();

    $transport = new InMemoryTransport();
    $transport->reject_next();
    $relay = new OutboxProcessor(
      new AcmeConfig(), null, new OutboxConfig(lock_timeout_seconds: self::LEASE), null,
      null, null, $this->clock, $this->store, $transport, $this->tx,
    );
    $result = $relay->process_batch();

    self::assertSame(['e1'], $result->retried);
    self::assertSame(2, $this->store->attempts_of('e1'), 'one re-claim + one rejection');
  }
}
