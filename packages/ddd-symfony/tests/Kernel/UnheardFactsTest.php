<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel;

use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Symfony\Runtime\DddSignal;
use TangibleDDD\Symfony\Tests\Kernel\App\Commands\AnnounceCommand;
use TangibleDDD\Symfony\Tests\Kernel\App\Events\ToyCharged;
use TangibleDDD\Symfony\Tests\Kernel\App\Events\ToyJobFinished;

/**
 * AW3 (TXP process-kernel demands): unheard facts are visible on Symfony.
 *
 * - A fact no subscriber of any consumer listens to is still delivered, and
 *   the relay raises FactDeliveredUnheard (a DddSignal through the signal
 *   dispatcher) and notes it on the outbox row, which the operator view lists
 *   in layer `relay`.
 * - A keyed answer no process waits for (ResumeReport::is_unheard()) is acked
 *   with no failure and no signal (it may be a precheck-absorbed answer), but
 *   the resume subscriber no longer drops the report: the ledger pair carries
 *   unheard_at.
 */
final class UnheardFactsTest extends KernelTestBase {

  /** @var list<DddSignal> */
  private array $signals = [];

  protected function setUp(): void {
    parent::setUp();
    $this->signals = [];
    self::getContainer()->get('event_dispatcher')->addListener(DddSignal::class, function (DddSignal $s): void {
      $this->signals[] = $s;
    });
  }

  public function test_a_fact_nobody_subscribes_to_raises_fact_delivered_unheard_and_shows_in_the_operator_view(): void {
    (new AnnounceCommand(new ToyCharged('team-1', 'ch-1')))->send();
    $eventId = (string) $this->db->fetchOne('SELECT event_id FROM ddd_outbox WHERE event_class = ?', [ToyCharged::class]);

    $this->console('ddd:relay', ['--once' => true]);

    self::assertSame('accepted', $this->db->fetchOne('SELECT status FROM ddd_outbox WHERE event_id = ?', [$eventId]), 'still delivered');
    $unheard = array_values(array_filter($this->signals, static fn (DddSignal $s) => $s->action() === 'fact_delivered_unheard'));
    self::assertCount(1, $unheard);
    self::assertSame($eventId, $unheard[0]->event->entry()->event_id);
    self::assertNotNull($this->db->fetchOne('SELECT unheard_at FROM ddd_outbox WHERE event_id = ?', [$eventId]));

    $items = self::getContainer()->get('test.operator_view')->list(Layer::Relay);
    self::assertCount(1, $items);
    self::assertSame($eventId, $items[0]->key);
    self::assertStringContainsString('unheard', (string) $items[0]->last_error);
    self::assertStringContainsString('sfk_integration_toy_charged', (string) $items[0]->last_error);
  }

  public function test_a_fact_with_a_subscriber_raises_no_unheard_signal(): void {
    (new AnnounceCommand(new ToyJobFinished('job-1')))->send();
    $this->console('ddd:relay', ['--once' => true]);

    self::assertSame([], array_filter($this->signals, static fn (DddSignal $s) => $s->action() === 'fact_delivered_unheard'));
    self::assertSame(0, $this->countRows('SELECT count(*) FROM ddd_outbox WHERE unheard_at IS NOT NULL'));
  }

  public function test_a_keyed_answer_no_process_waits_for_is_noted_on_the_ledger_without_a_failure_or_signal(): void {
    (new AnnounceCommand(new ToyJobFinished('job-nobody-minted')))->send();
    $eventId = (string) $this->db->fetchOne('SELECT event_id FROM ddd_outbox WHERE event_class = ?', [ToyJobFinished::class]);

    $this->console('ddd:relay', ['--once' => true]);
    $consume = $this->console('messenger:consume', ['receivers' => ['ddd_facts'], '--limit' => 1, '--time-limit' => 10]);
    self::assertSame(0, $consume->getStatusCode(), $consume->getDisplay() . $consume->getErrorOutput());

    $row = $this->db->fetchAssociative('SELECT * FROM ddd_delivery_ledger WHERE subscriber_id = ? AND event_id = ?', ['resume:' . ToyJobFinished::class, $eventId]);
    self::assertIsArray($row);
    self::assertNotNull($row['delivered_at'], 'acked as delivered');
    self::assertSame(0, (int) $row['attempts']);
    self::assertNotNull($row['unheard_at'], 'the resume report is no longer dropped');
    self::assertSame(0, $this->countRows('SELECT count(*) FROM messenger_messages'));
    self::assertSame([], $this->signals, 'no signal: an unheard answer may be a precheck-absorbed one');
    self::assertSame([], self::getContainer()->get('test.operator_view')->list(Layer::Delivery));
  }
}
