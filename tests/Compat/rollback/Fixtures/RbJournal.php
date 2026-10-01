<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Compat\Rollback\Fixtures;

/**
 * What the rollback fixtures' steps and listeners did, in a table both the
 * N test process and the legacy (0.6.x) child processes write through the
 * global `$wpdb`. Code here uses no TangibleDDD class, so it is the same in
 * both runtimes.
 */
final class RbJournal {

  public static function table(): string {
    return $GLOBALS['wpdb']->prefix . 'ddd_rb_journal';
  }

  public static function create(): void {
    $db = $GLOBALS['wpdb'];
    $db->query('CREATE TABLE IF NOT EXISTS `' . self::table() . '` (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      label VARCHAR(191) NOT NULL,
      runtime VARCHAR(32) NOT NULL,
      KEY label (label)
    ) ENGINE=InnoDB');
  }

  public static function drop(): void {
    $GLOBALS['wpdb']->query('DROP TABLE IF EXISTS `' . self::table() . '`');
  }

  public static function mark(string $label): void {
    $db = $GLOBALS['wpdb'];
    $runtime = defined('DDD_ROLLBACK_RUNTIME') ? (string) constant('DDD_ROLLBACK_RUNTIME') : 'n';
    if ($db->insert(self::table(), ['label' => $label, 'runtime' => $runtime]) === false) {
      throw new \RuntimeException("RbJournal: could not record $label: {$db->last_error}");
    }
  }

  public static function count(string $label): int {
    $db = $GLOBALS['wpdb'];
    return (int) $db->get_var($db->prepare('SELECT COUNT(*) FROM `' . self::table() . '` WHERE label = %s', $label));
  }

  /** @return list<array{label: string, runtime: string}> in order */
  public static function all(): array {
    $rows = $GLOBALS['wpdb']->get_results('SELECT label, runtime FROM `' . self::table() . '` ORDER BY id ASC', ARRAY_A);
    return array_map(static fn (array $r) => ['label' => (string) $r['label'], 'runtime' => (string) $r['runtime']], is_array($rows) ? $rows : []);
  }
}
