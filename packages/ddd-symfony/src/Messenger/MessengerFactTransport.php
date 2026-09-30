<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Messenger;

use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use TangibleDDD\Runtime\Delivery\ITransport;
use TangibleDDD\Runtime\Delivery\TransportRejected;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\SystemClock;
use TangibleDDD\Symfony\Persistence\DbalPostgresOutboxStore;

/**
 * ITransport onto the `ddd_facts` Messenger transport (register 3.5, E
 * section 7): one IntegrationFactMessage per fact.
 *
 * - submit() sends through the transport's sender, stamped with the bus the
 *   delivery handler lives on (BusNameStamp), and returns the transport
 *   message id. A missing, '' or '0' id is a rejection (CONF-4). A fact whose
 *   class cannot be resolved is rejected before anything is sent.
 * - $dueAt is absolute: a due fact gets no DelayStamp (bug 3); a future one
 *   gets exactly the remaining time.
 * - sharesConnectionWith() is true when the sender is a Messenger Doctrine
 *   transport writing through the very DBAL connection of a
 *   DbalPostgresOutboxStore. The relay then runs submit + accept in ONE
 *   transaction, so the hand-off is exactly-once; the insert into
 *   messenger_messages joins that transaction as a savepoint, and its
 *   pg_notify is delivered on commit only.
 */
final class MessengerFactTransport implements ITransport {

  private readonly IClock $clock;

  public function __construct(
    private readonly SenderInterface $sender,
    private readonly string $consumer,
    private readonly IFactClassResolver $classes,
    private readonly ?string $busName = null,
    ?IClock $clock = null,
    private readonly ?Connection $senderConnection = null,
  ) {
    $this->clock = $clock ?? new SystemClock();
  }

  public function submit(Claim $c, array $wrappedEnvelope, \DateTimeImmutable $dueAt): ?string {
    $class = $this->classes->classFor($c);
    if ($class === null || $class === '') {
      throw new TransportRejected("Fact {$c->event_id} ({$c->record->event_type}) has no resolvable PHP class; it cannot be delivered.");
    }

    $stamps = [];
    if ($this->busName !== null) {
      $stamps[] = new BusNameStamp($this->busName);
    }
    $delayMs = (int) floor(($dueAt->format('U.u') - $this->clock->now()->format('U.u')) * 1000);
    if ($delayMs > 0) {
      $stamps[] = new DelayStamp($delayMs);
    }

    $sent = $this->sender->send(new Envelope(new IntegrationFactMessage(
      $this->consumer,
      $c->event_id,
      $c->record->event_type,
      $class,
      $c->record->integration_action,
      $wrappedEnvelope,
    ), $stamps));

    $ref = $sent->last(TransportMessageIdStamp::class)?->getId();
    $ref = $ref === null ? null : (string) $ref;
    if ($ref === null || $ref === '' || $ref === '0') {
      throw new TransportRejected("Messenger took fact {$c->event_id} without a transport message id.");
    }
    return $ref;
  }

  public function sharesConnectionWith(IOutboxStore $store): bool {
    if (!$store instanceof DbalPostgresOutboxStore) {
      return false;
    }
    $mine = $this->senderConnection ?? DoctrineTransportConnection::of($this->sender);
    return $mine !== null && $mine === $store->connection();
  }
}
