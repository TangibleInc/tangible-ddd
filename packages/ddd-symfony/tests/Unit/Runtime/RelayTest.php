<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Symfony\Runtime\Relay;
use TangibleDDD\Testing\InMemoryOutboxStore;
use TangibleDDD\Testing\InMemoryTransactionBoundary;
use TangibleDDD\Testing\InMemoryTransport;

final class RelayTest extends TestCase {

  private FrozenClock $clock;
  private InMemoryTransactionBoundary $boundary;
  private InMemoryOutboxStore $outbox;

  protected function setUp(): void {
    $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-01T12:00:00Z'));
    $this->boundary = new InMemoryTransactionBoundary();
    $this->outbox = new InMemoryOutboxStore($this->clock, null, $this->boundary);
    $this->boundary->enlist($this->outbox);
  }

  private function append(string $id, int $maxAttempts = 5, int $delay = 0): void {
    $this->outbox->append(new OutboxRecord($id, 'widget_registered', 'txp_integration_widget_registered', 'c', 1, null, ['id' => $id],
      $this->clock->now()->modify("+{$delay} seconds"), max_attempts: $maxAttempts));
  }

  private function relay(InMemoryTransport $transport, ?IOutboxStore $store = null): Relay {
    return new Relay($store ?? $this->outbox, $transport, $this->boundary, $this->clock, new OutboxConfig(), new NullLogger());
  }

  public function test_accepts_due_rows_and_submits_at_their_absolute_due_time(): void {
    $this->append('a');
    $this->append('later', delay: 120);
    $transport = new InMemoryTransport();

    $report = $this->relay($transport)->runOnce(10);

    self::assertSame(['a'], $report->accepted);
    self::assertSame('accepted', $this->outbox->statusOf('a'));
    self::assertSame('pending', $this->outbox->statusOf('later'));
    self::assertEquals($this->clock->now(), $transport->submissions[0]['due_at']);
    self::assertSame('a', $transport->submissions[0]['envelope']['__event_id']);
    self::assertSame('c', $transport->submissions[0]['envelope']['__correlation_id']);
  }

  public function test_a_shared_connection_transport_runs_submit_and_accept_in_one_transaction(): void {
    $this->append('a');

    $this->relay(new InMemoryTransport(sharesConnection: true))->runOnce(10);

    self::assertSame(1, $this->boundary->commits());
    self::assertSame('accepted', $this->outbox->statusOf('a'));
  }

  public function test_a_separate_connection_transport_submits_then_accepts_without_a_transaction(): void {
    $this->append('a');
    $this->relay(new InMemoryTransport(sharesConnection: false))->runOnce(10);
    self::assertSame(0, $this->boundary->commits());
    self::assertSame('accepted', $this->outbox->statusOf('a'));
  }

  public function test_a_rejection_retries_with_backoff_then_dead_letters_at_max_attempts(): void {
    $this->append('a', maxAttempts: 2);
    $transport = new InMemoryTransport();
    $relay = $this->relay($transport);

    $transport->rejectNext();
    $first = $relay->runOnce(10);
    self::assertSame(['a'], $first->retried);
    self::assertSame(1, $this->outbox->attemptsOf('a'));
    self::assertSame([], $relay->runOnce(10)->claimed, 'backoff: 60 s before the next attempt');

    $this->clock->advance('+60 seconds');
    $transport->rejectNext();
    $second = $relay->runOnce(10);
    self::assertSame(['a'], $second->deadLettered);
    self::assertSame('dlq', $this->outbox->statusOf('a'));
  }

  public function test_a_submission_without_a_reference_is_never_accepted(): void {
    $this->append('a');
    $transport = new InMemoryTransport();
    $transport->returnNoRefNext();

    $report = $this->relay($transport)->runOnce(10);

    self::assertSame(['a'], $report->retried);
    self::assertSame('pending', $this->outbox->statusOf('a'));
  }

  public function test_a_lost_lease_is_reported_and_discarded(): void {
    $this->append('a');
    $store = new class ($this->outbox) implements IOutboxStore {
      public function __construct(private readonly InMemoryOutboxStore $inner) {}
      public function append(OutboxRecord $r): void {
        $this->inner->append($r);
      }
      public function claim(int $limit, \DateTimeImmutable $now, int $leaseSeconds): array {
        return $this->inner->claim($limit, $now, $leaseSeconds);
      }
      public function accept(Claim $c, ?string $transportRef): bool {
        return false;
      }
      public function retryLater(Claim $c, string $error, \DateTimeImmutable $nextAt): bool {
        return false;
      }
      public function deadLetter(Claim $c, string $error): bool {
        return false;
      }
    };

    $report = $this->relay(new InMemoryTransport(), $store)->runOnce(10);

    self::assertSame(['a'], $report->lost);
    self::assertSame([], $report->accepted);
  }

  public function test_backoff_is_60s_doubling_capped_at_an_hour(): void {
    $config = new OutboxConfig();
    self::assertSame(60, Relay::backoffSeconds(1, $config));
    self::assertSame(120, Relay::backoffSeconds(2, $config));
    self::assertSame(3600, Relay::backoffSeconds(12, $config));
  }
}
