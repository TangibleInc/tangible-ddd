<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TangibleDDD\Application\Process\IAwaitKeyed;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Symfony\Persistence\DbalDeliveryLedger;

/**
 * What the sf subscribers note on the delivery ledger beyond the port
 * (wave 5), for the operator and for forensics:
 *
 * - unheard() (AW3): a resume subscriber's fact reached no suspended process
 *   (ResumeReport::is_unheard(): a misrouted key, a duplicate, or an answer a
 *   register-then-check precheck already absorbed). Logged at info level with
 *   the event id, class and await key, and noted on the pair (`unheard_at`).
 *   Not a failure and no signal: the library cannot yet tell an absorbed
 *   answer from a misrouted one.
 * - compensated() (E3): the D1 failure command an exhausted pair sent
 *   (`failure_command`, `failure_command_at`), which the delivery operator
 *   source shows.
 *
 * Never throws: a note that cannot be written is logged (a throw here would
 * fail a delivered subscriber, or re-fire a compensation that already ran).
 */
final class DeliveryNotes {

  private readonly LoggerInterface $logger;

  public function __construct(
    private readonly ?DbalDeliveryLedger $ledger = null,
    ?LoggerInterface $logger = null,
  ) {
    $this->logger = $logger ?? new NullLogger();
  }

  public function unheard(string $subscriberId, IIntegrationEvent $fact, string $eventId): void {
    $key = $fact instanceof IAwaitKeyed ? $fact->await_key() : null;
    $this->logger->info(sprintf(
      '[ddd resume] unheard: no suspended process took %s event %s%s (a misrouted key, a duplicate, or a precheck-absorbed answer)',
      get_class($fact), $eventId === '' ? '(no id)' : $eventId, $key === null || $key === '' ? '' : " key $key"
    ), ['subscriber' => $subscriberId, 'event_id' => $eventId, 'event_class' => get_class($fact), 'await_key' => $key]);
    if ($eventId === '') {
      return;
    }
    $this->write(fn () => $this->ledger?->note_unheard($subscriberId, $eventId), "unheard note for $subscriberId@$eventId");
  }

  public function compensated(string $subscriberId, string $eventId, string $commandClass): void {
    if ($eventId === '') {
      return;
    }
    $this->write(fn () => $this->ledger?->note_failure_command($subscriberId, $eventId, $commandClass), "failure command note for $subscriberId@$eventId");
  }

  private function write(\Closure $note, string $what): void {
    try {
      $note();
    } catch (\Throwable $e) {
      $this->logger->warning("[ddd delivery] could not write the $what: {$e->getMessage()}", ['exception' => $e]);
    }
  }
}
