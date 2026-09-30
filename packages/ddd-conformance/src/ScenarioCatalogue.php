<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

/**
 * The stable scenario ids of register section 4, with the wave at whose
 * acceptance each must first pass per host (null = "-", not applicable).
 *
 * This is a copy of the register table, not a second source of truth: a
 * change to either is a register edit, and CatalogueTest pins the per-host
 * wave lists of register section 8 against it.
 */
final class ScenarioCatalogue {

  public const HOSTS = ['mem', 'pdo', 'wp', 'sf'];

  /** @var array<string, array{0: ?int, 1: ?int, 2: ?int, 3: ?int}> id => [mem, pdo, wp, sf] */
  public const WAVES = [
    'cmd.commit-atomic'                       => [1, 3, 2, 2],
    'cmd.commit-failure'                      => [1, 3, 2, 2],
    'cmd.reaction-throws'                     => [1, 3, 2, 2],
    'cmd.no-boundary'                         => [1, 3, 2, 2],
    'cmd.nested-rejected'                     => [1, 3, 2, 2],
    'cmd.guards-without-audit'                => [1, 3, 2, 2],
    'cmd.return-value'                        => [1, 3, 2, 2],
    'relay.fresh-process-pickup'              => [null, 3, 3, 3],
    'relay.crash-after-commit'                => [null, 3, 3, 3],
    'relay.crash-after-submit'                => [1, 3, 3, 2],
    'relay.lease-fencing'                     => [1, 3, 3, 2],
    'relay.invalid-acceptance'                => [1, 3, 2, 2],
    'relay.pause-holders'                     => [1, 3, 3, 2],
    'relay.replay-keeps-identity'             => [1, 3, 2, 3],
    'relay.replay-keeps-identity.process'     => [3, 3, 3, 3],
    'delivery.double-delivery'                => [1, 3, 3, 2],
    'delivery.double-delivery.process'        => [3, 3, 3, 3],
    'delivery.subscriber-isolation'           => [1, 3, 3, 2],
    'delivery.subscriber-isolation.process'   => [3, 3, 3, 3],
    'delivery.phase-order'                    => [1, 3, 2, 2],
    'delivery.phase-order.process'            => [3, 3, 3, 3],
    'delivery.delayed-once'                   => [1, 3, 2, 3],
    'lock.contention'                         => [3, 3, 3, 3],
    'lock.acquire-error'                      => [3, 3, 3, 3],
    'lock.reentrant-balance'                  => [3, 3, 3, 3],
    'lock.namespace'                          => [null, 3, null, 3],
    'process.ignition-race'                   => [3, 3, 3, 3],
    'process.manual-start-in-drain'           => [3, 3, 3, 3],
    'process.await-all-concurrent'            => [null, 3, 3, 3],
    'process.timeout-vs-event'                => [3, 3, 3, 3],
    'process.await-before-dispatch'           => [3, 3, 3, 3],
    'process.intent-survives-queue-failure'   => [3, 3, 3, 3],
    'process.stale-wakeup'                    => [3, 3, 3, 3],
    'process.crash-mid-step'                  => [null, 3, 3, 3],
    'process.fresh-process-resume'            => [null, 3, 3, 3],
    'process.start-from-web'                  => [null, null, null, 3],
    'process.alarm-long'                      => [4, 4, 4, 4],
    'workflow.fact-ignition-once'             => [4, null, null, 4],
    'worker.no-leak'                          => [1, 3, 3, 2],
    'audit.sink-fails'                        => [2, 3, 2, 3],
    'codec.large-payload'                     => [4, 4, 4, 4],
    'decode.unknown-class'                    => [4, 4, 4, 4],
    'effect.journal-reuse'                    => [4, 4, null, 4],
    'wakeup.post-commit'                      => [null, null, null, 4],
  ];

  /** Ids that must pass on $host at the acceptance of $wave (cumulative), in table order. */
  public static function dueBy(string $host, int $wave): array {
    $col = self::column($host);
    $ids = [];
    foreach (self::WAVES as $id => $waves) {
      if ($waves[$col] !== null && $waves[$col] <= $wave) {
        $ids[] = $id;
      }
    }
    return $ids;
  }

  /** Ids first due on $host exactly at $wave. */
  public static function firstDueAt(string $host, int $wave): array {
    $col = self::column($host);
    return array_keys(array_filter(self::WAVES, static fn (array $w) => $w[$col] === $wave));
  }

  public static function isKnown(string $id): bool {
    return isset(self::WAVES[$id]);
  }

  private static function column(string $host): int {
    $col = array_search($host, self::HOSTS, true);
    if ($col === false) {
      throw new \InvalidArgumentException("Unknown host '$host'");
    }
    return $col;
  }
}
