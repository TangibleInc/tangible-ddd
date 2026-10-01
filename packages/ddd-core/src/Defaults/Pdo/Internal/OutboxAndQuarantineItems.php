<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo\Internal;

use TangibleDDD\Defaults\Pdo\IHostConnection;
use TangibleDDD\Runtime\Ops\IOperatorItemSource;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Runtime\Ops\OperatorItem;
use TangibleDDD\Runtime\PrefixedTableNames;

/**
 * The two pdo items PortOperatorView's port reads cannot see:
 *
 * - layer `relay`: `pending` outbox rows that already failed a submission
 *   (attempts > 0), against the row's max_attempts; repair `retry`. The
 *   port lists only dead letters.
 * - layer `process`: quarantined rows (quarantine_reason set; status
 *   `failed`). The port lists only stranded `running` rows.
 *
 * @internal
 */
final class OutboxAndQuarantineItems implements IOperatorItemSource {

  private readonly PrefixedTableNames $tables;

  public function __construct(private readonly IHostConnection $db, private readonly string $consumer, string $tablePrefix = '') {
    $this->tables = new PrefixedTableNames($tablePrefix);
  }

  public function items(?Layer $layer, int $limit): array {
    if ($limit <= 0) {
      return [];
    }
    $items = [];
    if ($layer === null || $layer === Layer::Relay) {
      foreach ($this->db->fetchAll(
        'SELECT event_id, attempts, max_attempts, last_error, created_at FROM `' . $this->tables->table('ddd_outbox') . "`
         WHERE status = 'pending' AND attempts > 0 ORDER BY created_at, id LIMIT ?",
        [$limit]
      ) as $r) {
        $items[] = new OperatorItem(
          Layer::Relay, $this->consumer, (string) $r['event_id'], (int) $r['attempts'], (int) $r['max_attempts'],
          $r['last_error'] === null ? null : (string) $r['last_error'], Utc::fromDb((string) $r['created_at']), ['retry'],
        );
      }
    }
    if ($layer === null || $layer === Layer::Process) {
      foreach ($this->db->fetchAll(
        'SELECT id, process_class, quarantine_reason, updated_at FROM `' . $this->tables->table('ddd_processes') . '`
         WHERE quarantine_reason IS NOT NULL ORDER BY updated_at, id LIMIT ?',
        [$limit]
      ) as $r) {
        $items[] = new OperatorItem(
          Layer::Process, $this->consumer, (string) $r['id'], 0, null,
          "quarantined {$r['process_class']}: {$r['quarantine_reason']}", Utc::fromDb((string) $r['updated_at']), [],
        );
      }
    }
    return array_slice($items, 0, $limit);
  }
}
