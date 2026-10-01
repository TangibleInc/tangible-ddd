<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Persistence;

use Doctrine\DBAL\Connection;

/**
 * The plain SQL schema under packages/ddd-symfony/schema/postgres (X9: no
 * migration framework). The host copies `ddd:schema:dump` output into its own
 * migrations; tests apply it directly.
 *
 * Every file is idempotent (CREATE ... IF NOT EXISTS). `{{prefix}}` is the
 * configured table prefix. Wave 2 shipped the outbox, DLQ, relay pauses and
 * the delivery ledger; wave 3 adds processes, process waits, wakeup intents
 * and the D10 workflow tables (workflows, meta, items, ignition ledger).
 *
 * The Messenger Doctrine transport table (messenger_messages) is NOT here:
 * create it with `messenger:setup-transports`. It must exist before the relay
 * runs, because the relay submits inside a transaction, where Messenger's
 * auto-setup cannot run.
 */
final class PostgresSchema {

  /** @return list<string> absolute paths, in apply order */
  public static function files(): array {
    $files = glob(self::dir() . '/*.sql') ?: [];
    sort($files);
    return array_values($files);
  }

  /** @return list<string> logical table names (unprefixed), in apply order */
  public static function tables(): array {
    return [
      'ddd_outbox', 'ddd_dlq', 'ddd_relay_pauses', 'ddd_delivery_ledger',
      'ddd_processes', 'ddd_process_waits', 'ddd_wakeups',
      'ddd_behaviour_workflows', 'ddd_behaviour_workflow_meta', 'ddd_behaviour_workflow_items', 'ddd_workflow_ignitions',
    ];
  }

  public static function render(string $prefix = ''): string {
    self::assertPrefix($prefix);
    $out = [];
    foreach (self::files() as $file) {
      $out[] = str_replace('{{prefix}}', $prefix, (string) file_get_contents($file));
    }
    return implode("\n", $out);
  }

  /** @return list<string> executable statements, comments stripped */
  public static function statements(string $prefix = ''): array {
    $lines = array_filter(
      explode("\n", self::render($prefix)),
      static fn (string $line) => !str_starts_with(ltrim($line), '--')
    );
    $statements = [];
    foreach (explode(';', implode("\n", $lines)) as $chunk) {
      $sql = trim($chunk);
      if ($sql !== '') {
        $statements[] = $sql;
      }
    }
    return $statements;
  }

  public static function apply(Connection $connection, string $prefix = ''): void {
    foreach (self::statements($prefix) as $sql) {
      $connection->executeStatement($sql);
    }
  }

  public static function dir(): string {
    return dirname(__DIR__, 2) . '/schema/postgres';
  }

  private static function assertPrefix(string $prefix): void {
    if (!preg_match('/^[a-z0-9_]*$/', $prefix)) {
      throw new \InvalidArgumentException("Table prefix '$prefix' must match [a-z0-9_]*");
    }
  }
}
