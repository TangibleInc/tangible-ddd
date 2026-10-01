<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Testing\InMemoryTransactional;
use TangibleDDD\Testing\InMemoryTransactionBoundary;
use TangibleDDD\Testing\InMemoryTransport;

/** CONF-5: a shared-connection mem transport rolls back with the boundary. */
final class InMemoryTransportEnlistmentTest extends TestCase {

  private function claim(string $id): Claim {
    $now = new \DateTimeImmutable('2026-10-01 00:00:00', new \DateTimeZone('UTC'));
    return new Claim($id, 'tok', $now, new OutboxRecord($id, 't', 'a', 'c', 1, null, [], $now), 0);
  }

  public function test_the_transport_is_an_in_memory_participant(): void {
    self::assertInstanceOf(InMemoryTransactional::class, new InMemoryTransport(true));
  }

  public function test_a_shared_transport_passed_the_boundary_enlists_and_rolls_back(): void {
    $boundary = new InMemoryTransactionBoundary();
    $transport = new InMemoryTransport(sharesConnection: true, boundary: $boundary);

    try {
      $boundary->run(function () use ($transport): void {
        $transport->submit($this->claim('e1'), [], new \DateTimeImmutable());
        throw new \RuntimeException('crash before accept');
      });
    } catch (\RuntimeException) {
    }

    self::assertSame([], $transport->submissions, 'the submission rolled back with the transaction');

    $boundary->run(fn () => $transport->submit($this->claim('e2'), [], new \DateTimeImmutable()));
    self::assertSame(['e2'], array_column($transport->submissions, 'event_id'));
    self::assertSame('mem-1', $transport->submissions[0]['ref'], 'the reference sequence rolled back too');
  }

  public function test_a_non_shared_transport_never_enlists(): void {
    $boundary = new InMemoryTransactionBoundary();
    $transport = new InMemoryTransport(sharesConnection: false, boundary: $boundary);

    try {
      $boundary->run(function () use ($transport): void {
        $transport->submit($this->claim('e1'), [], new \DateTimeImmutable());
        throw new \RuntimeException('crash');
      });
    } catch (\RuntimeException) {
    }

    self::assertSame(['e1'], array_column($transport->submissions, 'event_id'), 'a separate connection keeps its submission');
  }
}
