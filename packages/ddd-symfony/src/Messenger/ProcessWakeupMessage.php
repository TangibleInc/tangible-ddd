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
    public readonly ?int $process_id,
    public readonly ?int $step_index,
    public readonly ?string $expected_status,
    public readonly string $due_at,
    public readonly string $key,
    public readonly string $claim_token,
    public readonly string $lease_until,
    public readonly int $attempts,
  ) {}

  public static function from_claim(ClaimedWakeup $w): self {
    $i = $w->intent;
    return new self(
      $i->kind->value, $i->consumer, $i->process_id, $i->step_index, $i->expected_status,
      self::iso($i->due_at), $i->key, $w->token, self::iso($w->lease_until), $w->attempts,
    );
  }

  public function to_claim(): ClaimedWakeup {
    return new ClaimedWakeup(
      new WakeupIntent(
        WakeKind::from($this->kind), $this->consumer, $this->process_id, $this->step_index, $this->expected_status,
        new \DateTimeImmutable($this->due_at), $this->key,
      ),
      $this->claim_token,
      new \DateTimeImmutable($this->lease_until),
      $this->attempts,
    );
  }

  private static function iso(\DateTimeImmutable $t): string {
    return $t->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
  }
}
