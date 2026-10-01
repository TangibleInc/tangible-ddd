<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use TangibleDDD\Runtime\Ops\IOperatorView;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Symfony\Messenger\IntegrationFactMessage;
use TangibleDDD\Symfony\Tests\Support\Fixtures\OrderProcess;

/**
 * D9: the bundle's one failure view (core PortOperatorView over the sf
 * ports and sources) and `ddd:ops:list`, on the test kernel and Postgres 16:
 * relay DLQ, delivery ledger, exhausted wakeups, stranded processes and the
 * Messenger failure transport.
 */
final class OperatorViewTest extends KernelTestBase {

  private function seedEveryLayer(): int {
    $c = self::getContainer();
    $now = new \DateTimeImmutable();

    $outbox = $c->get('tangible_ddd.outbox_store');
    $outbox->appendFact(new OutboxRecord('evt-dlq', 'widget_registered', 'sfk_integration_widget_registered', 'c', 1, null, [], $now), 'App\\W');
    [$claim] = $outbox->claim(1, $now, 60);
    $outbox->deadLetter($claim, 'broker refused');

    $ledger = $c->get('tangible_ddd.delivery_ledger');
    $ledger->markFailed('listener:App\\Mailer', 'evt-led', 'smtp down', 5);
    $ledger->markExhausted('listener:App\\Mailer', 'evt-led');

    $wakeups = $c->get('tangible_ddd.wakeup_scheduler');
    $c->get('tangible_ddd.transaction_boundary')->run(fn () => $wakeups->schedule(WakeupIntent::timeout('sfk', 4242, 1, $now)));
    [$w] = $wakeups->claimDue($now, 10, 60);
    $wakeups->exhaust($w, 'lock busy x10');

    $processId = $c->get('tangible_ddd.process_store')->insert(OrderProcess::started(1));
    $this->db->executeStatement("UPDATE ddd_processes SET updated_at = now() - interval '2 hours' WHERE id = ?", [$processId]);

    $c->get('messenger.transport.ddd_failed')->send(new Envelope(
      new IntegrationFactMessage('sfk', 'evt-msg', 'widget_registered', 'App\\W', 'sfk_integration_widget_registered', []),
      [new RedeliveryStamp(4), new SentToFailureTransportStamp('ddd_facts'), new ErrorDetailsStamp(\RuntimeException::class, 0, 'handler blew up')],
    ));
    return $processId;
  }

  public function test_the_view_merges_every_layer_in_layer_order(): void {
    $processId = $this->seedEveryLayer();

    /** @var IOperatorView $view */
    $view = self::getContainer()->get('test.operator_view');
    $items = $view->list();

    self::assertSame(
      [Layer::Relay, Layer::Delivery, Layer::Wakeup, Layer::Process, Layer::Transport],
      array_map(static fn ($i) => $i->layer, $items)
    );
    $keys = array_map(static fn ($i) => $i->key, $items);
    self::assertSame(['evt-dlq', 'listener:App\\Mailer@evt-led', 'timeout:4242:1', (string) $processId], array_slice($keys, 0, 4));
    self::assertSame(5, $items[4]->attempts);
    self::assertStringContainsString('evt-msg', (string) $items[4]->lastError);
    self::assertSame(['resume_stranded', 'fail_stranded'], $items[3]->repairActions);
    self::assertSame([Layer::Transport], array_map(static fn ($i) => $i->layer, $view->list(Layer::Transport)));
  }

  public function test_ops_list_prints_every_layer_with_attempts_against_budget(): void {
    $this->seedEveryLayer();

    $t = $this->console('ddd:ops:list');
    self::assertSame(Command::SUCCESS, $t->getStatusCode());
    $out = $t->getDisplay();

    foreach (['relay', 'delivery', 'wakeup', 'process', 'transport'] as $layer) {
      self::assertStringContainsString($layer, $out);
    }
    self::assertStringContainsString('evt-dlq', $out);
    self::assertStringContainsString('broker refused', $out);
    self::assertStringContainsString('5/5', $out, 'the exhausted ledger pair, attempts against the handler budget');
    self::assertStringContainsString('timeout:4242:1', $out);
    self::assertStringContainsString('handler blew up', $out);
    self::assertStringContainsString('messenger:failed:retry ddd_failed', $out);
  }

  public function test_ops_list_filters_by_layer_and_prints_json(): void {
    $this->seedEveryLayer();

    $t = $this->console('ddd:ops:list', ['--layer' => 'delivery', '--format' => 'json']);
    self::assertSame(Command::SUCCESS, $t->getStatusCode());

    $rows = json_decode($t->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
    self::assertCount(1, $rows);
    self::assertSame('delivery', $rows[0]['layer']);
    self::assertSame('listener:App\\Mailer@evt-led', $rows[0]['key']);
    self::assertSame(5, $rows[0]['budget']);
  }

  public function test_ops_list_rejects_an_unknown_layer_and_reports_an_empty_view(): void {
    self::assertSame(Command::INVALID, $this->console('ddd:ops:list', ['--layer' => 'nope'])->getStatusCode());

    $t = $this->console('ddd:ops:list');
    self::assertSame(Command::SUCCESS, $t->getStatusCode());
    self::assertStringContainsString('No failures', $t->getDisplay());
  }
}
