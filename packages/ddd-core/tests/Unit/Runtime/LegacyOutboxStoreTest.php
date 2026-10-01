<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Application\Outbox\OutboxEntry;
use TangibleDDD\Core\Tests\Unit\Fixtures\AcmeConfig;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingLogger;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Infra\IOutboxRepository;
use TangibleDDD\Infra\Services\OutboxProcessor;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\Outbox\LegacyOutboxStore;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Runtime\Outbox\OutboxWriteFailed;
use TangibleDDD\Testing\InMemoryTransport;

/**
 * Register 3.4 / R3: LegacyOutboxStore adapts a consumer's own 0.6
 * IOutboxRepository (LMS Doctrine) to IOutboxStore, unfenced, with a
 * logged warning.
 */
final class LegacyOutboxStoreTest extends TestCase {

  private FrozenClock $clock;

  protected function setUp(): void {
    HostDefaults::resetForTests();
    HostDefaults::provide(\Psr\Log\LoggerInterface::class, new RecordingLogger());
    $this->clock =new FrozenClock(new \DateTimeImmutable('2026-10-01 12:00:00', new \DateTimeZone('UTC')));
  }

  protected function tearDown(): void {
    HostDefaults::resetForTests();
  }

  private function repo(OutboxEntry ...$entries): LegacyRepoFake {
    $repo = new LegacyRepoFake();
    foreach ($entries as $e) {
      $repo->rows[$e->event_id] = $e;
    }
    return $repo;
  }

  private function entry(string $id, string $scheduled = '2026-10-01 11:00:00', int $delay = 0, int $attempts = 0): OutboxEntry {
    return OutboxEntry::from_row((object) [
      'id' => 3, 'event_id' => $id, 'event_type' => 'order_placed', 'integration_action' => 'acme_integration_order_placed',
      'correlation_id' => 'corr-' . $id, 'sequence' => 2, 'command_id' => 'cmd-1', 'payload' => '{"order_id":5}',
      'delay_seconds' => $delay, 'scheduled_at' => $scheduled, 'status' => 'pending', 'attempts' => $attempts,
      'max_attempts' => 4, 'created_at' => '2026-10-01 10:00:00', 'blog_id' => 1,
    ]);
  }

  public function test_it_is_an_outbox_store(): void {
    self::assertInstanceOf(IOutboxStore::class, new LegacyOutboxStore($this->repo()));
  }

  public function test_claim_fetches_through_the_repository_and_maps_the_record(): void {
    $repo = $this->repo($this->entry('e1'));
    $store = new LegacyOutboxStore($repo, null, 'worker-9');

    $claims = $store->claim(10, $this->clock->now(), 120);

    self::assertCount(1, $claims);
    self::assertSame([[120], [10, 'worker-9']], [$repo->released, $repo->fetched], 'stale locks released with the lease, then fetched');
    $c = $claims[0];
    self::assertSame('e1', $c->event_id);
    self::assertSame(0, $c->attempts);
    self::assertEquals($this->clock->now()->modify('+120 seconds'), $c->leaseUntil);
    self::assertSame(['order_id' => 5], $c->record->payload);
    self::assertSame('acme_integration_order_placed', $c->record->integration_action);
    self::assertSame(4, $c->record->max_attempts);
  }

  public function test_a_legacy_delayed_row_past_its_scheduled_time_is_due_once_not_delayed_again(): void {
    // Bug 3, extraction variant: scheduled_at already includes delay_seconds.
    $store = new LegacyOutboxStore($this->repo($this->entry('e1', '2026-10-01 11:59:00', delay: 600)));

    [$c] = $store->claim(10, $this->clock->now(), 60);

    self::assertSame('2026-10-01T11:59:00+00:00', $c->record->due_at->format(DATE_ATOM));
  }

  public function test_accept_retry_and_dead_letter_map_to_the_0_6_methods_unfenced(): void {
    $repo = $this->repo($this->entry('a'), $this->entry('b'), $this->entry('c'));
    $store = new LegacyOutboxStore($repo);
    [$a, $b, $c] = $store->claim(10, $this->clock->now(), 60);

    self::assertTrue($store->accept($a, 'ref-1'));
    self::assertTrue($store->retryLater($b, 'down', $this->clock->now()->modify('+60 seconds')));
    self::assertTrue($store->deadLetter($c, 'poison'));

    self::assertSame(['completed:a', 'failed:b:down', 'dlq:c:poison'], $repo->writes);
  }

  public function test_the_missing_fence_is_logged_once(): void {
    $logger = new RecordingLogger();
    $store = new LegacyOutboxStore($this->repo($this->entry('a')), $logger);

    $store->claim(10, $this->clock->now(), 60);
    $store->claim(10, $this->clock->now(), 60);

    self::assertCount(1, $logger->records);
    self::assertSame('warning', $logger->records[0]['level']);
    self::assertStringContainsString('unfenced', $logger->messages()[0]);
    self::assertStringContainsString(LegacyRepoFake::class, $logger->messages()[0]);
  }

  public function test_append_is_refused_because_write_mints_its_own_event_id(): void {
    $store = new LegacyOutboxStore($this->repo());

    $this->expectException(OutboxWriteFailed::class);
    $store->append(new OutboxRecord('e1', 't', 'a', null, null, null, [], $this->clock->now()));
  }

  public function test_the_core_relay_step_runs_over_the_bridge(): void {
    $repo = $this->repo($this->entry('e1'));
    $transport = new InMemoryTransport();
    $relay = new OutboxProcessor(
      new AcmeConfig(), null, new OutboxConfig(), null,
      null, new RecordingLogger(), $this->clock, new LegacyOutboxStore($repo, new RecordingLogger()), $transport,
    );

    $result = $relay->process_batch();

    self::assertSame(['e1'], $result->accepted);
    self::assertSame(['completed:e1'], $repo->writes);
    self::assertSame('e1', $transport->submissions[0]['envelope']['__event_id']);
  }
}

/** A consumer-authored 0.6 repository (array-backed). */
final class LegacyRepoFake implements IOutboxRepository {
  /** @var array<string, OutboxEntry> */
  public array $rows = [];
  /** @var list<string> */
  public array $writes = [];
  /** @var list<int|string|null> */
  public array $fetched = [];
  /** @var list<int> */
  public array $released = [];

  public function write(IIntegrationEvent $event, string $correlation_id, ?string $command_id = null): string { return 'x'; }
  public function fetch_pending(int $limit = 50, ?string $worker_id = null): array {
    $this->fetched = [$limit, $worker_id];
    return array_values(array_slice($this->rows, 0, $limit));
  }
  public function set_pause(string $holder, string $selector, int $until = -1): void {}
  public function clear_pause(string $holder): void {}
  public function is_paused(string $event_type): bool { return false; }
  public function find_by_event_id(string $event_id): ?OutboxEntry { return $this->rows[$event_id] ?? null; }
  public function mark_completed(string $event_id): void { $this->writes[] = "completed:$event_id"; }
  public function mark_failed(string $event_id, string $error): void { $this->writes[] = "failed:$event_id:$error"; }
  public function move_to_dlq(string $event_id, string $final_error = ''): void { $this->writes[] = "dlq:$event_id:$final_error"; }
  public function release_stale_locks(int $timeout_seconds = 300): int { $this->released = [$timeout_seconds]; return 0; }
  public function cancel_duplicates(string $event_type, array $payload_signature): int { return 0; }
  public function get_stats(): array { return []; }
  public function purge_completed(int $older_than_days = 30): int { return 0; }
}
