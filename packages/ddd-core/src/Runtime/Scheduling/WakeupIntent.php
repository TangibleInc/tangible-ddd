<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Scheduling;

/**
 * A durable wakeup (register 3.6). `due_at` is absolute UTC. The wake handler
 * is stale-safe: under the process lock it re-reads the row and no-ops
 * unless `expected_status` and `step_index` still match.
 *
 * `key` identifies the intent; scheduling a key that exists is a
 * no-op. The helpers below fix the key formats for the process kinds.
 *
 * `fact` (wave 5, AW2) is the fact a parked resume carries (resume_fact()):
 * `['class' => IIntegrationEvent class, 'payload' => integration_payload(),
 * 'event_id' => string]`, plain scalars. Null for every other intent. Only
 * a scheduler that implements ICarriesFacts persists it.
 */
final class WakeupIntent {

  public readonly \DateTimeImmutable $due_at;

  /**
   * @param array{class: string, payload: array<string, mixed>, event_id: string}|null $fact
   */
  public function __construct(
    public readonly WakeKind $kind,
    public readonly string $consumer,
    public readonly ?int $process_id,
    public readonly ?int $step_index,
    public readonly ?string $expected_status,
    \DateTimeImmutable $dueAt,
    public readonly string $key,
    public readonly ?array $fact = null,
  ) {
    if ($key === '') {
      throw new \InvalidArgumentException('WakeupIntent needs an idempotency key');
    }
    $this->due_at = $dueAt->setTimezone(new \DateTimeZone('UTC'));
  }

  /** Await timeout of a suspended step: key `timeout:{process_id}:{step_index}`. */
  public static function timeout(string $consumer, int $processId, int $stepIndex, \DateTimeImmutable $dueAt): self {
    return new self(WakeKind::Timeout, $consumer, $processId, $stepIndex, 'suspended', $dueAt, self::timeout_key($processId, $stepIndex));
  }

  /** The key timeout() gives, for cancel() when the awaited fact arrives first. */
  public static function timeout_key(int $processId, int $stepIndex): string {
    return "timeout:$processId:$stepIndex";
  }

  /**
   * Continuation of a scheduled process: key `continue:{process_id}:{step_index}`,
   * or `continue:{process_id}:{step_index}:{discriminator}` when the same
   * step index is continued more than once (compensation continuations, the
   * stranded scan's re-queue), so a retained completed row of an earlier
   * continuation never swallows the new one as a duplicate.
   */
  public static function continuation(string $consumer, int $processId, int $stepIndex, \DateTimeImmutable $dueAt, ?string $discriminator = null): self {
    $key = "continue:$processId:$stepIndex" . ($discriminator !== null && $discriminator !== '' ? ":$discriminator" : '');
    return new self(WakeKind::Continue, $consumer, $processId, $stepIndex, 'scheduled', $dueAt, $key);
  }

  /**
   * Re-queue of a wake that could not take its process lock (bug 1,
   * extraction variant; register 3.7 "on LockNotAcquired the runner
   * schedules a ResumeRetry"). `expected_status` names the wake to repeat:
   * `scheduled` = the continuation, `suspended` = the await timeout of
   * `step_index`, `running` = the first step of a just-started process at
   * row `version`. Key:
   * `resume_retry:{process_id}:{step_index|-}:{expected_status}:{version}:{nonce}`.
   */
  public static function resume_retry(string $consumer, int $processId, ?int $stepIndex, string $expectedStatus, int $version, \DateTimeImmutable $dueAt, string $nonce): self {
    $key = sprintf('resume_retry:%d:%s:%s:%d:%s', $processId, $stepIndex ?? '-', $expectedStatus, $version, $nonce);
    return new self(WakeKind::ResumeRetry, $consumer, $processId, $stepIndex, $expectedStatus, $dueAt, $key);
  }

  /**
   * A fact resume parked because the process lock was taken (AW2, wave 5):
   * a ResumeRetry that repeats the resume of the process suspended at
   * `step_index` with the fact it carries. Key
   * `resume_retry:{process_id}:{step_index}:suspended:0:fact-{event_id}`
   * (the resume_retry() format with a deterministic nonce), so parking the
   * same fact for the same process twice writes one intent.
   *
   * @param array{class: string, payload: array<string, mixed>, event_id: string} $fact
   */
  public static function resume_fact(string $consumer, int $process_id, int $step_index, array $fact, \DateTimeImmutable $due_at): self {
    $event_id = (string) ($fact['event_id'] ?? '');
    if ($event_id === '') {
      throw new \InvalidArgumentException('A parked fact needs its event id');
    }
    $key = sprintf('resume_retry:%d:%d:suspended:0:fact-%s', $process_id, $step_index, $event_id);
    return new self(WakeKind::ResumeRetry, $consumer, $process_id, $step_index, 'suspended', $due_at, $key, $fact);
  }

  /** The row version a ResumeRetry key carries (0 when none or not a ResumeRetry key). */
  public function retry_version(): int {
    if ($this->kind !== WakeKind::ResumeRetry) {
      return 0;
    }
    $parts = explode(':', $this->key);
    return isset($parts[4]) && ctype_digit($parts[4]) ? (int) $parts[4] : 0;
  }
}
