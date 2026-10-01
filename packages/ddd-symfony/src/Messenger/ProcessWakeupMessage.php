<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Messenger;

use TangibleDDD\Runtime\Scheduling\ClaimedWakeup;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;

/**
 * One leased wakeup intent on the `ddd_wakeups` Messenger transport
 * (register 3.6, 5.3). It carries the intent and its lease (claim token),
 * so the handler can complete or retry exactly the lease it was sent for;
 * a message whose lease was re-taken after expiry completes nothing.
 * Times are ISO-8601 UTC strings, so the message serializes with any
 * Messenger serializer.
 */
final class ProcessWakeupMessage {

  public function __construct(
    public readonly string $kind,
    public readonly string $consumer,
    public readonly ?int $processId,
    public readonly ?int $stepIndex,
    public readonly ?string $expectedStatus,
    public readonly string $dueAt,
    public readonly string $idempotencyKey,
    public readonly string $claimToken,
    public readonly string $leaseUntil,
    public readonly int $attempts,
  ) {}

  public static function fromClaim(ClaimedWakeup $w): self {
    $i = $w->intent;
    return new self(
      $i->kind->value, $i->consumer, $i->processId, $i->stepIndex, $i->expectedStatus,
      self::iso($i->dueAt), $i->idempotencyKey, $w->claimToken, self::iso($w->leaseUntil), $w->attempts,
    );
  }

  public function toClaim(): ClaimedWakeup {
    return new ClaimedWakeup(
      new WakeupIntent(
        WakeKind::from($this->kind), $this->consumer, $this->processId, $this->stepIndex, $this->expectedStatus,
        new \DateTimeImmutable($this->dueAt), $this->idempotencyKey,
      ),
      $this->claimToken,
      new \DateTimeImmutable($this->leaseUntil),
      $this->attempts,
    );
  }

  private static function iso(\DateTimeImmutable $t): string {
    return $t->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
  }
}
