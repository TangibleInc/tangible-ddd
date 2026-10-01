<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Conformance\Support;

use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Conformance\Support\InterleavingProcessLock;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;
use TangibleDDD\Runtime\Lock\ReentrantProcessLock;
use TangibleDDD\Symfony\Messenger\MessengerFactTransport;
use TangibleDDD\Symfony\Persistence\DbalDeliveryLedger;
use TangibleDDD\Symfony\Persistence\DbalOutboxAdministration;
use TangibleDDD\Symfony\Persistence\DbalPostgresOutboxStore;
use TangibleDDD\Symfony\Persistence\DbalProcessStore;
use TangibleDDD\Symfony\Persistence\DbalRelayPauseStore;
use TangibleDDD\Symfony\Persistence\DbalTransactionBoundary;
use TangibleDDD\Symfony\Persistence\DbalWakeupScheduler;
use TangibleDDD\Symfony\Runtime\Relay;
use TangibleDDD\Symfony\Runtime\Wakeup\WakeupRelay;

/**
 * Everything one sf worker runs on: the ddd-symfony classes the bundle
 * wires (config/services.php), all bound to ONE DBAL connection (the
 * worker's "php-fpm child / Messenger consumer"). SfHostFixture composes
 * worker 1 on its own connection and worker n > 1 on a new one.
 */
final class SfWorkerPorts {

  public function __construct(
    public readonly int $n,
    public readonly Connection $connection,
    public readonly DbalTransactionBoundary $boundary,
    public readonly DbalRelayPauseStore $pauses,
    public readonly DbalPostgresOutboxStore $outbox,
    public readonly DbalOutboxAdministration $administration,
    public readonly DoctrineTransport $facts,
    public readonly FaultInjectingSender $factSender,
    public readonly MessengerFactTransport $transport,
    public readonly Relay $relay,
    public readonly DbalDeliveryLedger $ledger,
    public readonly SubscriptionRegistry $subscriptions,
    public readonly DbalProcessStore $processStore,
    public readonly DbalWakeupScheduler $wakeups,
    public readonly DoctrineTransport $wakeTransport,
    public readonly FaultInjectingSender $wakeSender,
    public readonly WakeupRelay $wakeupRelay,
    public readonly ?InterleavingProcessLock $interleaving,
    public readonly ReentrantProcessLock $lock,
    public readonly ProcessRunner $runner,
  ) {}
}
