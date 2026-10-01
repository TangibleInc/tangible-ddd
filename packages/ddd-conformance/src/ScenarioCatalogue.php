<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

/**
 * The stable scenario ids of register section 4, with the wave at whose
 * acceptance each must first pass per host (null = "-", not applicable).
 *
 * This is a copy of the register table, not a second source of truth: a
 * change to either is a register edit, and CatalogueTest pins the per-host
 * wave lists of register section 8 against it. The three D3 ids
 * `process.await-*` are change request CR-W4C4-1
 * (docs/extraction/wave4-conformance-4-change-requests.md), pending their
 * register row.
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
    // CR-W4C4-1 (not yet in the register table): D3 for TXP process-kernel
    'process.await-keyed-precheck'            => [4, 4, null, 4],
    'process.await-any-cancellation'          => [4, 4, null, 4],
    'process.await-all-dynamic'               => [4, 4, null, 4],
    'workflow.fact-ignition-once'             => [4, null, null, 4],
    'worker.no-leak'                          => [1, 3, 3, 2],
    'audit.sink-fails'                        => [2, 3, 2, 3],
    'codec.large-payload'                     => [4, 4, 4, 4],
    'decode.unknown-class'                    => [4, 4, 4, 4],
    'effect.journal-reuse'                    => [4, 4, null, 4],
    'wakeup.post-commit'                      => [null, null, null, 4],
    // wave 5 (CR-W5C5-1): TXP process-kernel demands AW1, AW2, E2, W4 and sf multi-consumer
    'lock.parked-answer'                      => [5, 5, null, 5],
    'process.resume-contention-keeps-answer'  => [5, 5, null, 5],
    'process.resume-cause'                    => [5, 5, 5, 5],
    'effect.performed-not-recorded'           => [5, 5, null, 5],
    'workflow.item-deterministic-id'          => [5, 5, 5, 5],
    'delivery.cross-consumer-once'            => [null, null, null, 5],
  ];

  private const S = 'TangibleDDD\\Conformance\\Scenarios\\';

  /**
   * The abstract scenario case (src/Scenarios) that declares each id, i.e.
   * the class a host extends to run it. Wave-4 ids live in cases of their
   * own, so a host class written for wave 3 runs unchanged.
   * CatalogueTest pins this map against reflection.
   *
   * @var array<string, class-string>
   */
  public const CASES = [
    'cmd.commit-atomic'                       => self::S . 'CommandScenarios',
    'cmd.commit-failure'                      => self::S . 'CommandScenarios',
    'cmd.reaction-throws'                     => self::S . 'CommandScenarios',
    'cmd.no-boundary'                         => self::S . 'CommandScenarios',
    'cmd.nested-rejected'                     => self::S . 'CommandScenarios',
    'cmd.guards-without-audit'                => self::S . 'CommandScenarios',
    'cmd.return-value'                        => self::S . 'CommandScenarios',
    'audit.sink-fails'                        => self::S . 'CommandScenarios',
    'relay.crash-after-submit'                => self::S . 'RelayScenarios',
    'relay.lease-fencing'                     => self::S . 'RelayScenarios',
    'relay.invalid-acceptance'                => self::S . 'RelayScenarios',
    'relay.pause-holders'                     => self::S . 'RelayScenarios',
    'relay.replay-keeps-identity'             => self::S . 'RelayScenarios',
    'delivery.double-delivery'                => self::S . 'DeliveryScenarios',
    'delivery.subscriber-isolation'           => self::S . 'DeliveryScenarios',
    'delivery.phase-order'                    => self::S . 'DeliveryScenarios',
    'delivery.delayed-once'                   => self::S . 'DeliveryScenarios',
    'worker.no-leak'                          => self::S . 'WorkerScenarios',
    'relay.replay-keeps-identity.process'     => self::S . 'ProcessDeliveryScenarios',
    'delivery.double-delivery.process'        => self::S . 'ProcessDeliveryScenarios',
    'delivery.subscriber-isolation.process'   => self::S . 'ProcessDeliveryScenarios',
    'delivery.phase-order.process'            => self::S . 'ProcessDeliveryScenarios',
    'lock.contention'                         => self::S . 'LockScenarios',
    'lock.acquire-error'                      => self::S . 'LockScenarios',
    'lock.reentrant-balance'                  => self::S . 'LockScenarios',
    'process.ignition-race'                   => self::S . 'ProcessScenarios',
    'process.manual-start-in-drain'           => self::S . 'ProcessScenarios',
    'process.timeout-vs-event'                => self::S . 'ProcessScenarios',
    'process.await-before-dispatch'           => self::S . 'ProcessScenarios',
    'process.intent-survives-queue-failure'   => self::S . 'ProcessScenarios',
    'process.stale-wakeup'                    => self::S . 'ProcessScenarios',
    'lock.namespace'                          => self::S . 'ConcurrencyScenarios',
    'process.await-all-concurrent'            => self::S . 'ConcurrencyScenarios',
    'relay.fresh-process-pickup'              => self::S . 'FreshProcessScenarios',
    'relay.crash-after-commit'                => self::S . 'FreshProcessScenarios',
    'process.crash-mid-step'                  => self::S . 'FreshProcessScenarios',
    'process.fresh-process-resume'            => self::S . 'FreshProcessScenarios',
    'process.start-from-web'                  => self::S . 'WebStartScenarios',
    // wave 4: new cases only, so a wave-3 host class runs unchanged
    'process.alarm-long'                      => self::S . 'AlarmScenarios',
    'process.await-keyed-precheck'            => self::S . 'AwaitScenarios',
    'process.await-any-cancellation'          => self::S . 'AwaitScenarios',
    'process.await-all-dynamic'               => self::S . 'AwaitScenarios',
    'decode.unknown-class'                    => self::S . 'DecodeScenarios',
    'codec.large-payload'                     => self::S . 'CodecScenarios',
    'effect.journal-reuse'                    => self::S . 'EffectScenarios',
    'workflow.fact-ignition-once'             => self::S . 'WorkflowScenarios',
    'wakeup.post-commit'                      => self::S . 'PostCommitWakeupScenarios',
    // wave 5: new cases only, so a wave-4 host class runs unchanged
    'lock.parked-answer'                      => self::S . 'ParkedAnswerScenarios',
    'process.resume-contention-keeps-answer'  => self::S . 'ParkedAnswerScenarios',
    'process.resume-cause'                    => self::S . 'ResumeCauseScenarios',
    'effect.performed-not-recorded'           => self::S . 'EffectStateScenarios',
    'workflow.item-deterministic-id'          => self::S . 'WorkItemScenarios',
    'delivery.cross-consumer-once'            => self::S . 'CrossConsumerScenarios',
  ];

  /** The abstract scenario case declaring $id, or null for an unknown id. */
  public static function case_of(string $id): ?string {
    return self::CASES[$id] ?? null;
  }

  /**
   * The abstract cases $host extends to run every id due on it by $wave.
   *
   * @return list<class-string>
   */
  public static function cases_for(string $host, int $wave): array {
    $cases = [];
    foreach (self::due_by($host, $wave) as $id) {
      if (isset(self::CASES[$id])) {
        $cases[self::CASES[$id]] = true;
      }
    }
    return array_keys($cases);
  }

  /** Ids that must pass on $host at the acceptance of $wave (cumulative), in table order. */
  public static function due_by(string $host, int $wave): array {
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
  public static function first_due_at(string $host, int $wave): array {
    $col = self::column($host);
    return array_keys(array_filter(self::WAVES, static fn (array $w) => $w[$col] === $wave));
  }

  public static function is_known(string $id): bool {
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
