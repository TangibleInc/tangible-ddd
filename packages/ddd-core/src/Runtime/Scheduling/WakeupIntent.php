<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Scheduling;

/**
 * A durable wakeup (register 3.6). `dueAt` is absolute UTC. The wake handler
 * is stale-safe: under the process lock it re-reads the row and no-ops
 * unless `expectedStatus` and `stepIndex` still match.
 *
 * `idempotencyKey` identifies the intent; scheduling a key that exists is a
 * no-op. The helpers below fix the key formats for the process kinds.
 */
final class WakeupIntent {

  public readonly \DateTimeImmutable $dueAt;

  public function __construct(
    public readonly WakeKind $kind,
    public readonly string $consumer,
    public readonly ?int $processId,
    public readonly ?int $stepIndex,
    public readonly ?string $expectedStatus,
    \DateTimeImmutable $dueAt,
    public readonly string $idempotencyKey,
  ) {
    if ($idempotencyKey === '') {
      throw new \InvalidArgumentException('WakeupIntent needs an idempotency key');
    }
    $this->dueAt = $dueAt->setTimezone(new \DateTimeZone('UTC'));
  }

  /** Await timeout of a suspended step: key `timeout:{process_id}:{step_index}`. */
  public static function timeout(string $consumer, int $processId, int $stepIndex, \DateTimeImmutable $dueAt): self {
    return new self(WakeKind::Timeout, $consumer, $processId, $stepIndex, 'suspended', $dueAt, "timeout:$processId:$stepIndex");
  }

  /** Continuation of a scheduled process: key `continue:{process_id}:{step_index}`. */
  public static function continuation(string $consumer, int $processId, int $stepIndex, \DateTimeImmutable $dueAt): self {
    return new self(WakeKind::Continue, $consumer, $processId, $stepIndex, 'scheduled', $dueAt, "continue:$processId:$stepIndex");
  }
}
