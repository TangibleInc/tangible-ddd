<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Unit\Messenger;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Symfony\Messenger\OutboxFactClassResolver;
use TangibleDDD\Symfony\Persistence\DbalPostgresOutboxStore;
use TangibleDDD\Symfony\Tests\Support\Fixtures\PingFact;
use TangibleDDD\Symfony\Tests\Support\Fixtures\PingMarker;

final class OutboxFactClassResolverTest extends TestCase {

  private function claim(string $action): Claim {
    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    return new Claim('evt-1', 't', $now, new OutboxRecord('evt-1', 'ping_fact', $action, null, null, null, [], $now), 0);
  }

  private function storeWithoutClass(): DbalPostgresOutboxStore {
    $conn = $this->createMock(Connection::class);
    $conn->method('fetchOne')->willReturn(null);
    return new DbalPostgresOutboxStore($conn);
  }

  public function test_the_row_class_wins(): void {
    $conn = $this->createMock(Connection::class);
    $conn->method('fetchOne')->willReturn('App\\Stored');
    $resolver = new OutboxFactClassResolver(new DbalPostgresOutboxStore($conn), [PingFact::class]);

    self::assertSame('App\\Stored', $resolver->resolve($this->claim('sft_integration_ping_fact')));
  }

  public function test_falls_back_to_the_known_facts_by_integration_action(): void {
    $resolver = new OutboxFactClassResolver($this->storeWithoutClass(), [PingMarker::class, 'App\\Missing', PingFact::class]);

    self::assertSame(PingFact::class, $resolver->resolve($this->claim('sft_integration_ping_fact')));
    self::assertNull($resolver->resolve($this->claim('sft_integration_unknown')));
  }
}
