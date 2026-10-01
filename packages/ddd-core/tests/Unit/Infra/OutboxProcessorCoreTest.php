<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Infra;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TangibleDDD\Application\Infrastructure\IInfrastructureEvent;
use TangibleDDD\Application\Outbox\IOutboxPublisher;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Application\Outbox\OutboxEntry;
use TangibleDDD\Core\Tests\Unit\Fixtures\AcmeConfig;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingLogger;
use TangibleDDD\Infra\IConsumerIdentity;
use TangibleDDD\Infra\IOutboxRepository;
use TangibleDDD\Infra\Services\OutboxProcessor;
use TangibleDDD\Runtime\Delivery\ISubscriberProbe;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IInfrastructureSignalDispatcher;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Testing\InMemoryOutboxStore;
use TangibleDDD\Testing\InMemoryTransactionBoundary;
use TangibleDDD\Testing\InMemoryTransport;

/**
 * CONF-3: the relay step (OutboxProcessor::process_batch core form) over
 * IOutboxStore + ITransport + ITransactionBoundary + IClock, with a seam
 * between submit and accept; and the 0.6 form without any host call.
 */
final class OutboxProcessorCoreTest extends TestCase {

  private FrozenClock $clock;
  private InMemoryTransactionBoundary $boundary;
  private InMemoryOutboxStore $store;
  /** @var object{seen: list<string>} */
  private object $signals;

  protected function setUp(): void {
    HostDefaults::resetForTests();
    HostDefaults::provide(LoggerInterface::class, new RecordingLogger());
    $this->signals = new class implements IInfrastructureSignalDispatcher {
      public array $seen = [];
      public function emit(IInfrastructureEvent $e, IConsumerIdentity $c): void { $this->seen[] = $e::action(); }
    };
    HostDefaults::provide(IInfrastructureSignalDispatcher::class, $this->signals);

    $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-01 12:00:00', new \DateTimeZone('UTC')));
    $this->boundary = new InMemoryTransactionBoundary();
    $this->store = new InMemoryOutboxStore($this->clock, null, $this->boundary);
    $this->boundary->enlist($this->store);
  }

  protected function tearDown(): void {
    HostDefaults::resetForTests();
  }

  private function append(string $id, string $due = '2026-10-01 12:00:00', int $max = 5): void {
    $this->store->append(new OutboxRecord(
      $id, 'order_placed', 'acme_integration_order_placed', 'corr-' . $id, 2, 'cmd-1',
      ['order_id' => 1], new \DateTimeImmutable($due, new \DateTimeZone('UTC')), max_attempts: $max,
    ));
  }

  private function relay(InMemoryTransport $transport, ?ISubscriberProbe $probe = null, ?OutboxConfig $config = null): OutboxProcessor {
    return new OutboxProcessor(
      new AcmeConfig(), null, $config ?? new OutboxConfig(), null,
      $probe, null, $this->clock, $this->store, $transport, $this->boundary,
    );
  }

  public function test_due_rows_are_submitted_at_their_absolute_due_time_and_accepted(): void {
    $this->append('e1');
    $this->append('e2', '2026-10-01 12:05:00');
    $transport = new InMemoryTransport();

    $result = $this->relay($transport)->process_batch();

    self::assertSame([1, 0, 0, 1], [$result->completed, $result->failed, $result->dlq, $result->total]);
    self::assertSame('accepted', $this->store->statusOf('e1'));
    self::assertSame('pending', $this->store->statusOf('e2'), 'not due yet');
    self::assertSame('mem-1', $this->store->transportRefOf('e1'));
    self::assertEquals(new \DateTimeImmutable('2026-10-01 12:00:00', new \DateTimeZone('UTC')), $transport->submissions[0]['due_at']);
    self::assertSame(
      ['order_id' => 1, '__correlation_id' => 'corr-e1', '__sequence' => 2, '__event_id' => 'e1'],
      $transport->submissions[0]['envelope'],
      'the 0.6 envelope'
    );
  }

  public function test_a_rejection_is_retried_with_backoff_then_dead_lettered(): void {
    $this->append('e1', max: 2);
    $transport = new InMemoryTransport();
    $relay = $this->relay($transport);

    $transport->rejectNext();
    $first = $relay->process_batch();
    self::assertSame(1, $first->failed);
    self::assertSame('pending', $this->store->statusOf('e1'));
    self::assertSame(1, $this->store->attemptsOf('e1'));
    self::assertSame(['outbox_attempt_failed'], $this->signals->seen);

    self::assertSame(0, $relay->process_batch()->total, 'not before the backoff (60 s)');

    $this->clock->advance('PT61S');
    $transport->rejectNext();
    $second = $relay->process_batch();
    self::assertSame(1, $second->dlq);
    self::assertSame('dlq', $this->store->statusOf('e1'));
    self::assertSame(['outbox_attempt_failed', 'outbox_dlq'], $this->signals->seen);
  }

  public function test_a_submission_without_a_reference_is_a_rejection(): void {
    // CONF-4: null, '' and '0' are rejections, never acceptances.
    $this->append('e1');
    $transport = new InMemoryTransport();
    $transport->returnNoRefNext();

    $result = $this->relay($transport)->process_batch();

    self::assertSame(1, $result->failed);
    self::assertSame('pending', $this->store->statusOf('e1'));
  }

  public function test_a_shared_connection_transport_rolls_back_with_the_accept(): void {
    // relay.crash-after-submit (shared): the seam between submit and accept.
    $this->append('e1');
    $transport = new InMemoryTransport(sharesConnection: true, boundary: $this->boundary);
    $relay = $this->relay($transport);
    $relay->between_submit_and_accept(static function (): void {
      throw new \LogicException('process died here');
    });

    try {
      $relay->process_batch();
      self::fail('the interruption propagates');
    } catch (\LogicException $e) {
      self::assertSame('process died here', $e->getMessage());
    }

    self::assertSame([], $transport->submissions, 'submit rolled back with the transaction');
    self::assertSame('pending', $this->store->statusOf('e1'));
    self::assertSame(0, $this->store->attemptsOf('e1'), 'an interruption is not a failed attempt');
  }

  public function test_a_separate_transport_keeps_its_submission_when_accept_never_happens(): void {
    $this->append('e1');
    $transport = new InMemoryTransport();
    $relay = $this->relay($transport);
    $relay->between_submit_and_accept(static function (): void {
      throw new \LogicException('process died here');
    });

    try {
      $relay->process_batch();
    } catch (\LogicException) {
    }

    self::assertCount(1, $transport->submissions, 'at-least-once: the same event_id may recur');
    self::assertSame('pending', $this->store->statusOf('e1'));
  }

  public function test_an_unheard_fact_is_still_accepted_and_signalled(): void {
    $this->append('e1');
    $probe = new class implements ISubscriberProbe {
      public function hasSubscribers(string $integrationAction): ?bool { return false; }
    };

    $result = $this->relay(new InMemoryTransport(), $probe)->process_batch();

    self::assertSame(1, $result->completed);
    self::assertSame(['fact_delivered_unheard'], $this->signals->seen);
  }

  public function test_the_lease_comes_from_the_outbox_config(): void {
    $this->append('e1');
    $this->relay(new InMemoryTransport(), null, new OutboxConfig(lock_timeout_seconds: 42, batch_size: 1))->process_batch();
    self::assertSame('accepted', $this->store->statusOf('e1'));
  }

  public function test_the_backoff_rule(): void {
    $c = new OutboxConfig();
    self::assertSame(60, OutboxProcessor::backoff_seconds(1, $c));
    self::assertSame(120, OutboxProcessor::backoff_seconds(2, $c));
    self::assertSame(3600, OutboxProcessor::backoff_seconds(12, $c));
  }

  /** A view of the store whose next accept() finds the lease gone (another worker re-claimed the row). */
  private function storeLosingNextAccept(): \TangibleDDD\Runtime\Outbox\IOutboxStore {
    return new class($this->store) implements \TangibleDDD\Runtime\Outbox\IOutboxStore {
      public bool $loseNextAccept = true;
      public function __construct(private InMemoryOutboxStore $inner) {}
      public function append(OutboxRecord $r): void { $this->inner->append($r); }
      public function claim(int $l, \DateTimeImmutable $n, int $s): array { return $this->inner->claim($l, $n, $s); }
      public function accept(\TangibleDDD\Runtime\Outbox\Claim $c, ?string $ref): bool {
        if ($this->loseNextAccept) {
          $this->loseNextAccept = false;
          return false;
        }
        return $this->inner->accept($c, $ref);
      }
      public function retryLater(\TangibleDDD\Runtime\Outbox\Claim $c, string $e, \DateTimeImmutable $n): bool { return $this->inner->retryLater($c, $e, $n); }
      public function deadLetter(\TangibleDDD\Runtime\Outbox\Claim $c, string $e): bool { return $this->inner->deadLetter($c, $e); }
    };
  }

  public function test_sfc1_a_lost_lease_on_accept_rolls_back_a_shared_submission(): void {
    $this->append('e1');
    $transport = new InMemoryTransport(sharesConnection: true, boundary: $this->boundary);
    $relay = new OutboxProcessor(
      new AcmeConfig(), null, new OutboxConfig(), null,
      null, null, $this->clock, $this->storeLosingNextAccept(), $transport, $this->boundary,
    );

    $result = $relay->process_batch();

    self::assertSame([], $transport->submissions, 'the submission rolled back, so the new lease holder submits it once');
    self::assertSame([0, 0, 0, 1], [$result->completed, $result->failed, $result->dlq, $result->total]);
    self::assertSame(['e1'], $result->leaseLost);
    self::assertSame(0, $this->store->attemptsOf('e1'), 'a lost lease is not a failed attempt');
    self::assertSame([], $this->signals->seen);
  }

  public function test_sfc1_a_separate_transport_keeps_its_submission_on_a_lost_lease(): void {
    $this->append('e1');
    $transport = new InMemoryTransport();
    $relay = new OutboxProcessor(
      new AcmeConfig(), null, new OutboxConfig(), null,
      null, null, $this->clock, $this->storeLosingNextAccept(), $transport, $this->boundary,
    );

    $result = $relay->process_batch();

    self::assertCount(1, $transport->submissions, 'at-least-once on a separate connection');
    self::assertSame(['e1'], $result->leaseLost);
  }

  public function test_sfc3_process_batch_takes_an_optional_limit(): void {
    foreach (['e1', 'e2', 'e3'] as $id) {
      $this->append($id);
    }
    $relay = $this->relay(new InMemoryTransport(), null, new OutboxConfig(batch_size: 50));

    $first = $relay->process_batch(2);
    $rest = $relay->process_batch();

    self::assertSame(2, $first->total);
    self::assertSame(1, $rest->total, 'without a limit the configured batch size applies');
  }

  public function test_sfc4_the_result_lists_event_ids_per_outcome(): void {
    $this->append('ok');
    $this->append('retry');
    $this->append('dead', max: 1);
    $transport = new class extends \stdClass implements \TangibleDDD\Runtime\Delivery\ITransport {
      public function submit(\TangibleDDD\Runtime\Outbox\Claim $c, array $w, \DateTimeImmutable $d): ?string {
        if ($c->event_id !== 'ok') {
          throw new \RuntimeException('down');
        }
        return 'ref-ok';
      }
      public function sharesConnectionWith(\TangibleDDD\Runtime\Outbox\IOutboxStore $s): bool { return false; }
    };
    $relay = new OutboxProcessor(
      new AcmeConfig(), null, new OutboxConfig(), null,
      null, null, $this->clock, $this->store, $transport, $this->boundary,
    );

    $r = $relay->process_batch();

    self::assertSame(['ok', 'retry', 'dead'], $r->claimed);
    self::assertSame(['ok'], $r->accepted);
    self::assertSame(['retry'], $r->retried);
    self::assertSame(['dead'], $r->deadLettered);
    self::assertSame([], $r->leaseLost);
  }

  public function test_sfc4_the_counts_only_constructor_stays_valid(): void {
    $r = new \TangibleDDD\Infra\Services\ProcessingResult(1, 2, 3, 6);
    self::assertSame([], $r->claimed);
    self::assertSame([], $r->leaseLost);
  }

  public function test_the_0_6_form_runs_without_any_host_call(): void {
    // No WordPress here: the 0.6 path must not reach has_action, WP_DEBUG
    // or wp_json_encode (the latter fataled outside WordPress in 0.6).
    $entry = OutboxEntry::from_row((object) [
      'id' => 5, 'event_id' => 'evt-5', 'event_type' => 'order_placed', 'integration_action' => 'acme_integration_order_placed',
      'correlation_id' => 'corr-5', 'sequence' => 1, 'command_id' => null, 'payload' => '{"order_id":5}',
      'scheduled_at' => '2026-10-01 12:00:00', 'status' => 'pending', 'attempts' => 4, 'max_attempts' => 5,
      'created_at' => '2026-10-01 12:00:00',
    ]);
    $repo = $this->createMock(IOutboxRepository::class);
    $repo->method('fetch_pending')->willReturn([$entry]);
    $repo->expects(self::once())->method('move_to_dlq')->with('evt-5', 'broker down');
    $publisher = $this->createMock(IOutboxPublisher::class);
    $publisher->method('publish')->willThrowException(new \RuntimeException('broker down'));
    $logger = new RecordingLogger();

    $result = (new OutboxProcessor(new AcmeConfig(), $repo, new OutboxConfig(), $publisher, null, $logger))->process_batch();

    self::assertSame(1, $result->dlq);
    self::assertSame(['outbox_dlq'], $this->signals->seen);
    self::assertStringStartsWith('[acme-outbox] DLQ: {', $logger->messages()[0]);
    self::assertStringContainsString('"error":"broker down"', $logger->messages()[0]);
  }
}
