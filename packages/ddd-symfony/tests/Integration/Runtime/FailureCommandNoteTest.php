<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Integration\Runtime;

use Symfony\Component\DependencyInjection\ServiceLocator;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Symfony\Ops\DbalLedgerOperatorSource;
use TangibleDDD\Symfony\Persistence\DbalDeliveryLedger;
use TangibleDDD\Symfony\Runtime\CompiledSubscriptionRegistry;
use TangibleDDD\Symfony\Runtime\DeliveryNotes;
use TangibleDDD\Symfony\Tests\Integration\PostgresTestCase;
use TangibleDDD\Symfony\Tests\Support\Fixtures\PingEffectListener;
use TangibleDDD\Symfony\Tests\Support\Fixtures\PingFact;
use TangibleDDD\Symfony\Tests\Support\Fixtures\PingListener;
use TangibleDDD\Symfony\Tests\Support\Fixtures\RecordingCommand;

/**
 * E3 (TXP process-kernel demands): the delivery operator source says that,
 * and which, D1 failure command ran for an exhausted pair; a pair exhausted
 * without one (a plain listener) says nothing more.
 */
final class FailureCommandNoteTest extends PostgresTestCase {

  protected function setUp(): void {
    parent::setUp();
    RecordingCommand::$sent = [];
  }

  public function test_an_exhausted_effect_pair_shows_its_failure_command_in_the_operator_view(): void {
    $ledger = new DbalDeliveryLedger($this->db);
    $registry = new CompiledSubscriptionRegistry(
      [CompiledSubscriptionRegistry::listener_spec('app.effect', PingEffectListener::class, PingFact::class, 10)],
      new ServiceLocator(['app.effect' => static fn () => new PingEffectListener()]),
      null, null, new DeliveryNotes($ledger),
    );
    $delivery = new IntegrationDelivery($registry, $ledger, 2);
    $fact = IntegrationEnvelope::wrap(['n' => 7], 'corr-1', 1, 'evt-e3');

    $first = $delivery->deliver(PingFact::class, $fact);
    self::assertTrue($first->needs_retry());
    self::assertNull($this->db->fetchOne('SELECT failure_command FROM ddd_delivery_ledger'), 'nothing sent yet');

    $second = $delivery->deliver(PingFact::class, $fact);
    self::assertSame(['listener:' . PingEffectListener::class], $second->exhausted);
    self::assertSame(['compensate:7'], RecordingCommand::$sent);

    $row = $this->db->fetchAssociative('SELECT failure_command, failure_command_at FROM ddd_delivery_ledger');
    self::assertSame(RecordingCommand::class, $row['failure_command']);
    self::assertNotNull($row['failure_command_at']);

    [$item] = (new DbalLedgerOperatorSource($this->db, 'txp', '', 2))->items(Layer::Delivery, 10);
    self::assertSame(2, $item->attempts);
    self::assertStringStartsWith('exhausted: provider down', (string) $item->last_error);
    self::assertStringContainsString('failure command ' . RecordingCommand::class . ' ran at ', (string) $item->last_error);
  }

  public function test_a_plain_listener_exhausted_without_a_failure_command_names_none(): void {
    $ledger = new DbalDeliveryLedger($this->db);
    $ledger->mark_failed('listener:' . PingListener::class, 'evt-x', 'smtp down', 2);
    $ledger->mark_exhausted('listener:' . PingListener::class, 'evt-x');

    [$item] = (new DbalLedgerOperatorSource($this->db, 'txp', '', 2))->items(Layer::Delivery, 10);
    self::assertSame('exhausted: smtp down', $item->last_error);
  }
}
