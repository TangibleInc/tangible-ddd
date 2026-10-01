<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TangibleDDD\Application\Infrastructure\OutboxAttemptFailed;
use TangibleDDD\Application\Infrastructure\OutboxDeadLettered;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Infra\Services\ProcessingResult;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IInfrastructureSignalDispatcher;
use TangibleDDD\Symfony\Runtime\SymfonyConsumerConfig;
use TangibleDDD\Testing\RecordingSignalDispatcher;
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
    return new Relay($store ?? $this->outbox, $transport, $this->boundary, $this->clock, new OutboxConfig(), new NullLogger(),
      new SymfonyConsumerConfig('sfr', 'TangibleDDD\\Symfony\\Tests'));
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

  public function test_the_step_is_the_core_relay_step_and_emits_its_signals(): void {
    $signals = new RecordingSignalDispatcher();
    HostDefaults::provide(IInfrastructureSignalDispatcher::class, $signals);
    try {
      $this->append('a', maxAttempts: 2);
      $transport = new InMemoryTransport();
      $relay = $this->relay($transport);

      $transport->rejectNext();
      $relay->runOnce(10);
      $this->clock->advance('+60 seconds');
      $transport->rejectNext();
      $relay->runOnce(10);

      $kinds = array_map(static fn (array $s) => get_class($s['event']), $signals->emitted);
      self::assertSame([OutboxAttemptFailed::class, OutboxDeadLettered::class], $kinds, 'the core OutboxProcessor signals, not a private loop');
      self::assertSame('sfr', $signals->emitted[0]['consumer']->prefix());
    } finally {
      HostDefaults::resetForTests();
    }
  }

  public function test_the_limit_overrides_the_configured_batch_size(): void {
    $this->append('a');
    $this->append('b');
    $this->append('c');

    $report = $this->relay(new InMemoryTransport())->runOnce(2);

    self::assertSame(['a', 'b'], $report->claimed);
    self::assertSame(['a', 'b'], $report->accepted);
  }

  public function test_the_seam_between_submit_and_accept_aborts_the_step_without_counting_an_attempt(): void {
    $this->append('a');
    $transport = new InMemoryTransport();
    $relay = $this->relay($transport);
    $crash = new \RuntimeException('process died');
    $relay->betweenSubmitAndAccept(static function () use ($crash): void { throw $crash; });

    $thrown = null;
    try {
      $relay->runOnce(10);
    } catch (\Throwable $e) {
      $thrown = $e;
    }

    self::assertSame($crash, $thrown, 'the seam\'s throwable propagates unchanged');
    self::assertCount(1, $transport->submissions, 'the transport took it');
    self::assertSame('pending', $this->outbox->statusOf('a'), 'never accepted');
    self::assertSame(0, $this->outbox->attemptsOf('a'), 'a simulated crash is not an attempt');

    $relay->betweenSubmitAndAccept(null);
    $this->clock->advance('+301 seconds');
    self::assertSame(['a'], $relay->runOnce(10)->accepted, 'the lease expired; the next step relays it');
  }

  public function test_a_lost_lease_on_a_shared_connection_rolls_the_submission_back(): void {
    $this->append('a');
    $transport = new InMemoryTransport(sharesConnection: true);
    $this->boundary->enlist($transport);
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

    $report = $this->relay($transport, $store)->runOnce(10);

    self::assertSame(['a'], $report->lost);
    self::assertSame([], $report->retried, 'a lost lease is not a failed attempt');
    self::assertSame([], $transport->submissions, 'the submission rolled back with the failed accept (CR sf-3)');
  }

  public function test_the_report_is_the_core_step_outcome_by_event_id(): void {
    $this->append('rejected');
    $this->append('ok');
    $transport = new InMemoryTransport();
    $relay = $this->relay($transport);
    $transport->rejectNext(); // the first submission: 'rejected'

    $report = $relay->runOnce(10);

    self::assertInstanceOf(ProcessingResult::class, $report->result, 'the core process_batch($limit) result (CR sfc-3, sfc-4)');
    self::assertSame($report->result->claimed, $report->claimed);
    self::assertSame($report->result->accepted, $report->accepted);
    self::assertSame($report->result->retried, $report->retried);
    self::assertSame($report->result->leaseLost, $report->lost);
    self::assertSame(['ok'], $report->accepted);
    self::assertSame(['rejected'], $report->retried);
  }

  public function test_the_limit_is_the_core_step_limit_not_a_copied_config(): void {
    $this->append('a');
    $this->append('b');
    $relay = new Relay($this->outbox, new InMemoryTransport(), $this->boundary, $this->clock, new OutboxConfig(batch_size: 1), new NullLogger());

    self::assertSame(['a', 'b'], $relay->runOnce(5)->claimed, 'runOnce(5) claims up to 5 whatever batch_size says');
    $this->append('c');
    $this->append('d');
    self::assertSame(['c'], $relay->runOnce()->claimed, 'no limit: OutboxConfig::batch_size');
  }

  public function test_backoff_is_60s_doubling_capped_at_an_hour(): void {
    $config = new OutboxConfig();
    self::assertSame(60, Relay::backoffSeconds(1, $config));
    self::assertSame(120, Relay::backoffSeconds(2, $config));
    self::assertSame(3600, Relay::backoffSeconds(12, $config));
  }
}
