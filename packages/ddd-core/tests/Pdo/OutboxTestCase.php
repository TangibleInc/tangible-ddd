<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo;

use TangibleDDD\Defaults\Pdo\IHostConnection;
use TangibleDDD\Defaults\Pdo\PdoOutboxStore;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\Outbox\IRelayPauseStore;
use TangibleDDD\Runtime\Outbox\OutboxRecord;

/** Shared helpers of the outbox store, administration and job-transport cases. */
abstract class OutboxTestCase extends PdoTestCase {

  protected FrozenClock $clock;

  protected function setUp(): void {
    parent::setUp();
    $this->clock = new FrozenClock(self::utc('2026-10-01 12:00:00'));
  }

  protected function store(?IHostConnection $db = null, ?IRelayPauseStore $pauses = null): PdoOutboxStore {
    return new PdoOutboxStore($db ?? $this->db, $pauses, self::PREFIX, $this->clock);
  }

  protected static function record(string $id, string $due = '2026-10-01 12:00:00', array $extra = []): OutboxRecord {
    return new OutboxRecord(...$extra + [
      'event_id' => $id,
      'event_type' => 'acme_order_placed',
      'integration_action' => 'acme_integration_order_placed',
      'correlation_id' => 'corr-' . $id,
      'sequence' => 3,
      'command_id' => 'cmd-' . $id,
      'payload' => ['order_id' => 7, 'total' => 12.0, 'note' => "naïve \u{1F600}", 'nested' => ['a' => [1, 2]]],
      'due_at' => self::utc($due),
      'is_unique' => false,
      'payload_signature' => null,
      'max_attempts' => 5,
      'blog_id' => null,
    ]);
  }
}
