<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Bundle;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use TangibleDDD\Symfony\Persistence\TableNames;
use TangibleDDD\Symfony\Runtime\SymfonyConsumerConfig;

/**
 * The bundle's consumers, resolved from `tangible_ddd` config (wave 5,
 * multi-consumer). `consumer:` is the one-consumer shorthand; `consumers:` a
 * map of name → settings, whose first entry is the primary consumer.
 *
 * Every consumer gets: a prefix (default: its name), a namespace root (or the
 * namespace of its `bundle`), a DBAL connection service, its tables
 * (`[schema.]prefix`, TableNames), its Messenger facts and wakeup transports,
 * and its delivery budget. The primary consumer keeps today's service ids
 * (`tangible_ddd.outbox_store`, ...) and the global table prefix and
 * transports; every other consumer's services are `tangible_ddd.consumer.
 * {name}.*` (id()), its transports default to `{transport}_{prefix}`, its
 * tables to no prefix.
 *
 * Refused (InvalidConfigurationException): both or neither of `consumer` and
 * `consumers`, a missing namespace root, two consumers with the same prefix
 * or namespace root, two consumers with the same tables on one connection,
 * and one transport shared with different delivery settings.
 *
 * @internal
 */
final class ConsumerSettings {

  /**
   * @param array<string, mixed> $config the processed `tangible_ddd` config
   * @return list<array{name: string, primary: bool, prefix: string, namespace_root: string, version: string, label: ?string,
   *   connection: string, connection_service: string, tables: string, transport: string, wakeup_transport: string,
   *   delivery: array{budget: int, retry_delay_ms: int, retry_multiplier: float, max_retry_delay_ms: int}}>
   */
  public static function resolve(array $config): array {
    $single = !empty($config['consumer']);
    $multi = !empty($config['consumers']);
    if ($single === $multi) {
      throw new InvalidConfigurationException('tangible_ddd: configure either `consumer` (one consumer) or `consumers` (a map of name => settings), not both and not neither.');
    }
    $entries = $single ? [(string) $config['consumer']['prefix'] => $config['consumer']] : $config['consumers'];

    $out = [];
    $primary = true;
    foreach ($entries as $name => $e) {
      $name = (string) $name;
      $prefix = (string) ($e['prefix'] ?? $name);
      $root = $e['namespace_root'] ?? null;
      if (($root === null || $root === '') && !empty($e['bundle'])) {
        $root = substr((string) $e['bundle'], 0, (int) strrpos((string) $e['bundle'], '\\'));
      }
      if ($root === null || trim((string) $root, '\\') === '') {
        throw new InvalidConfigurationException("tangible_ddd.consumers.$name: give a namespace_root (or the bundle whose namespace it owns).");
      }
      $connection = (string) ($e['connection'] ?? $config['connection']);
      $connectionService = $e['connection_service'] ?? (isset($e['connection']) ? null : $config['connection_service'])
        ?? sprintf('doctrine.dbal.%s_connection', $connection);
      $tablePrefix = $e['table_prefix'] ?? ($primary ? (string) $config['table_prefix'] : '');
      $m = $config['messenger'];
      $out[] = [
        'name' => $name,
        'primary' => $primary,
        'prefix' => $prefix,
        'namespace_root' => trim((string) $root, '\\'),
        'version' => SymfonyConsumerConfig::normalise_version(isset($e['version']) ? (string) $e['version'] : null),
        'label' => $e['label'] ?? null,
        'connection' => $connection,
        'connection_service' => (string) $connectionService,
        'tables' => TableNames::join($e['schema'] ?? null, (string) $tablePrefix),
        'transport' => (string) ($e['transport'] ?? ($primary ? $m['transport'] : $m['transport'] . '_' . $prefix)),
        'wakeup_transport' => (string) ($e['wakeup_transport'] ?? ($primary ? $m['wakeup_transport'] : $m['wakeup_transport'] . '_' . $prefix)),
        'delivery' => array_replace($config['delivery'], array_filter((array) ($e['delivery'] ?? []), static fn ($v) => $v !== null)),
      ];
      $primary = false;
    }
    self::assert_distinct($out);
    return $out;
  }

  /** A consumer's service id: today's id for the primary consumer, `tangible_ddd.consumer.{name}.{service}` otherwise. */
  public static function id(array $consumer, string $service): string {
    return $consumer['primary'] ? 'tangible_ddd.' . $service : sprintf('tangible_ddd.consumer.%s.%s', $consumer['name'], $service);
  }

  /** @param list<array<string, mixed>> $consumers */
  private static function assert_distinct(array $consumers): void {
    $seen = ['prefix' => [], 'namespace_root' => [], 'tables' => [], 'transport' => []];
    foreach ($consumers as $c) {
      foreach (['prefix', 'namespace_root'] as $field) {
        if (isset($seen[$field][$c[$field]])) {
          throw new InvalidConfigurationException("tangible_ddd.consumers: {$seen[$field][$c[$field]]} and {$c['name']} have the same $field \"{$c[$field]}\".");
        }
        $seen[$field][$c[$field]] = $c['name'];
      }
      $tables = $c['connection_service'] . '|' . $c['tables'];
      if (isset($seen['tables'][$tables])) {
        throw new InvalidConfigurationException("tangible_ddd.consumers: {$seen['tables'][$tables]} and {$c['name']} would share their tables on one connection; give one a schema or a table_prefix.");
      }
      $seen['tables'][$tables] = $c['name'];
      $other = $seen['transport'][$c['transport']] ?? null;
      if ($other !== null && $other['delivery'] !== $c['delivery']) {
        throw new InvalidConfigurationException("tangible_ddd.consumers: {$other['name']} and {$c['name']} share the transport \"{$c['transport']}\" with different delivery settings (its retry strategy is the delivery budget).");
      }
      $seen['transport'][$c['transport']] ??= $c;
    }
  }
}
