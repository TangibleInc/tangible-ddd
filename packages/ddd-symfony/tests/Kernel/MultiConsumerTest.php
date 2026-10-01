<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Symfony\Messenger\IntegrationFactMessage;
use TangibleDDD\Symfony\Persistence\PostgresSchema;
use TangibleDDD\Symfony\Tests\Kernel\App\Commands\RegisterWidgetCommand;
use TangibleDDD\Symfony\Tests\Kernel\App\Events\WidgetRegistered;
use TangibleDDD\Symfony\Tests\Kernel\Billing\CommandHandlers\BillWidgetHandler;
use TangibleDDD\Symfony\Tests\Kernel\Billing\Events\WidgetBilled;

/**
 * Multi-consumer (wave 5): two consumers in one Symfony app, on Postgres 16.
 *
 * - `sfk`, the test app (namespace TangibleDDD\Symfony\Tests\Kernel\App),
 *   tables in the search path, transport ddd_facts;
 * - `bil`, a reusable bounded context (namespace ...\Kernel\Billing), tables
 *   in Postgres schema `billing`, transports ddd_facts_bil / ddd_wakeups_bil,
 *   delivery budget 2.
 *
 * Each has its own outbox, relay, ledger, process store, wakeups and effect
 * journal; services are assigned to a consumer at compile time by namespace
 * root. A fact raised by `sfk` reaches `bil`'s subscribers through a copy
 * routed to `bil`'s transport, exactly once per subscriber under
 * redelivery (bil's ledger), and a poison fact in `sfk` does not stall `bil`.
 */
final class MultiConsumerTest extends KernelTestBase {

  protected static string $variant = 'multi';

  protected function setUp(): void {
    parent::setUp();
    $this->db->executeStatement('DROP SCHEMA IF EXISTS billing CASCADE');
    PostgresSchema::apply($this->db, 'billing.');
    $this->db->executeStatement('CREATE TABLE billing.bills (id BIGSERIAL PRIMARY KEY, widget_id TEXT NOT NULL)');
    BillWidgetHandler::$ports = ['outbox' => null, 'boundary' => null];
  }

  public function test_each_consumer_has_its_own_service_set_and_ownership_follows_the_namespace(): void {
    $c = self::getContainer();

    self::assertSame(['sfk', 'bil'], array_values(array_map(static fn ($h) => $h->prefix(), ConsumerRegistry::all())));
    self::assertSame('bil', ConsumerRegistry::owner_of(WidgetBilled::class)->prefix());
    self::assertSame('sfk', ConsumerRegistry::owner_of(WidgetRegistered::class)->prefix());
    self::assertNotSame($c->get('tangible_ddd.outbox_store'), $c->get('tangible_ddd.consumer.bil.outbox_store'));

    (new RegisterWidgetCommand('w1'))->send();
    $this->deliverTo('bil');

    self::assertSame($c->get('tangible_ddd.consumer.bil.outbox_store'), BillWidgetHandler::$ports['outbox'], 'a bil handler autowires bil\'s outbox');
    self::assertSame($c->get('tangible_ddd.consumer.bil.transaction_boundary'), BillWidgetHandler::$ports['boundary']);
    self::assertSame(1, $this->countRows('SELECT count(*) FROM billing.ddd_outbox WHERE event_class = ?', [WidgetBilled::class]), 'bil\'s fact is in bil\'s outbox');
    self::assertSame(0, $this->countRows('SELECT count(*) FROM ddd_outbox WHERE event_class = ?', [WidgetBilled::class]));
    self::assertSame('bil_integration_widget_billed', $this->db->fetchOne('SELECT integration_action FROM billing.ddd_outbox'));
  }

  public function test_a_fact_from_one_consumer_reaches_the_other_once_under_redelivery(): void {
    (new RegisterWidgetCommand('w1'))->send();
    $row = $this->db->fetchAssociative('SELECT * FROM ddd_outbox WHERE event_class = ?', [WidgetRegistered::class]);

    $relay = $this->console('ddd:relay', ['--once' => true, '--consumer' => 'sfk']);
    self::assertSame(0, $relay->getStatusCode(), $relay->getDisplay());
    self::assertSame(1, $this->countRows("SELECT count(*) FROM messenger_messages WHERE queue_name = 'ddd_facts'"), 'the raiser\'s own copy');
    self::assertSame(1, $this->countRows("SELECT count(*) FROM messenger_messages WHERE queue_name = 'ddd_facts_bil'"), 'one copy routed to bil');

    $this->consume('ddd_facts_bil');
    self::assertSame(['w1'], $this->bills());
    self::assertSame(1, $this->countRows("SELECT count(*) FROM billing.ddd_delivery_ledger WHERE event_id = ? AND subscriber_id LIKE '%BillOnWidgetRegistered' AND delivered_at IS NOT NULL", [$row['event_id']]));
    self::assertSame(0, $this->countRows('SELECT count(*) FROM ddd_delivery_ledger WHERE subscriber_id LIKE ?', ['%BillOnWidgetRegistered']), 'bil\'s pair is in bil\'s ledger only');

    // The same copy delivered again (a duplicate / Messenger redelivery).
    self::getContainer()->get('messenger.transport.ddd_facts_bil')->send(new Envelope(
      new IntegrationFactMessage('sfk', (string) $row['event_id'], (string) $row['event_type'], WidgetRegistered::class, (string) $row['integration_action'],
        IntegrationEnvelope::wrap(['widget_id' => 'w1'], (string) $row['correlation_id'], (int) $row['sequence'], (string) $row['event_id']), 'bil'),
      [new BusNameStamp('messenger.bus.default')],
    ));
    $this->consume('ddd_facts_bil');
    self::assertSame(['w1'], $this->bills(), 'exactly once per subscriber');

    // The raiser's own copy is delivered to the raiser's subscribers only.
    $this->consume('ddd_facts');
    self::assertSame(1, $this->countRows("SELECT count(*) FROM app_listener_runs WHERE listener = 'registered' AND widget_id = 'w1'"));
    self::assertSame(['w1'], $this->bills());
    self::assertSame(0, $this->countRows('SELECT count(*) FROM messenger_messages'));
  }

  public function test_a_poison_fact_in_one_consumer_does_not_stall_the_other(): void {
    // sfk's FlakyListener refuses boom-* forever; bil is fine with it.
    (new RegisterWidgetCommand('boom-1'))->send();
    $this->console('ddd:relay', ['--once' => true]);
    $this->consume('ddd_facts');
    self::assertSame(1, (int) $this->db->fetchOne("SELECT attempts FROM ddd_delivery_ledger WHERE subscriber_id LIKE '%FlakyListener'"), 'sfk keeps retrying it');
    self::assertSame(1, $this->countRows("SELECT count(*) FROM messenger_messages WHERE queue_name = 'ddd_facts'"), 'sfk\'s copy waits for its retry');

    (new RegisterWidgetCommand('w2'))->send();
    $this->console('ddd:relay', ['--once' => true]);
    $this->consume('ddd_facts_bil', 2);

    self::assertSame(['boom-1', 'w2'], $this->bills(), 'bil got both facts while sfk\'s copy of boom-1 is still failing');
    self::assertSame(0, $this->countRows("SELECT count(*) FROM messenger_messages WHERE queue_name = 'ddd_facts_bil'"));
  }

  public function test_a_process_of_one_consumer_runs_on_its_own_store_and_wakeups(): void {
    (new RegisterWidgetCommand('inv-1'))->send();
    $this->deliverTo('bil'); // ignites InvoiceWidget in bil; its #[Async] step is a Continue intent in bil's wakeups

    self::assertSame(0, $this->countRows('SELECT count(*) FROM ddd_processes'));
    self::assertSame('scheduled', $this->db->fetchOne('SELECT status FROM billing.ddd_processes'));
    self::assertSame(1, $this->countRows("SELECT count(*) FROM billing.ddd_wakeups WHERE consumer = 'bil'"));
    self::assertSame(0, $this->countRows('SELECT count(*) FROM ddd_wakeups'));

    $this->console('ddd:relay', ['--once' => true, '--consumer' => 'bil']);
    self::assertSame(1, $this->countRows("SELECT count(*) FROM messenger_messages WHERE queue_name = 'ddd_wakeups_bil'"));
    $this->consume('ddd_wakeups_bil');

    self::assertSame('completed', $this->db->fetchOne('SELECT status FROM billing.ddd_processes'));
    self::assertSame(['inv-1', 'invoiced:inv-1'], $this->bills());
    self::assertSame(0, $this->countRows('SELECT count(*) FROM billing.ddd_wakeups'));
  }

  public function test_relay_consumer_option_relays_only_that_consumer(): void {
    (new RegisterWidgetCommand('w1'))->send();
    $this->deliverTo('bil'); // bil now has WidgetBilled pending in its own outbox

    self::assertSame('pending', $this->db->fetchOne('SELECT status FROM billing.ddd_outbox'));
    $this->console('ddd:relay', ['--once' => true, '--consumer' => 'sfk']);
    self::assertSame('pending', $this->db->fetchOne('SELECT status FROM billing.ddd_outbox'), 'sfk\'s relay leaves bil\'s outbox alone');

    $this->console('ddd:relay', ['--once' => true, '--consumer' => 'bil']);
    self::assertSame('accepted', $this->db->fetchOne('SELECT status FROM billing.ddd_outbox'));
    self::assertSame(1, $this->countRows("SELECT count(*) FROM messenger_messages WHERE queue_name = 'ddd_facts_bil'"), 'no subscriber elsewhere: no routed copy');

    self::assertSame(1, $this->console('ddd:relay', ['--once' => true, '--consumer' => 'nope'])->getStatusCode());
  }

  public function test_one_operator_view_filterable_by_consumer(): void {
    $c = self::getContainer();
    $c->get('tangible_ddd.delivery_ledger')->mark_failed('listener:App\\A', 'evt-a', 'a down', 1);
    $c->get('tangible_ddd.consumer.bil.delivery_ledger')->mark_failed('listener:Billing\\B', 'evt-b', 'b down', 1);

    $all = $c->get('test.operator_view')->list(Layer::Delivery);
    self::assertSame(['sfk', 'bil'], array_map(static fn ($i) => $i->consumer, $all));

    $json = $this->console('ddd:ops:list', ['--consumer' => 'bil', '--format' => 'json'])->getDisplay();
    $rows = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    self::assertSame(['listener:Billing\\B@evt-b'], array_column($rows, 'key'));
    self::assertSame(['bil'], array_column($rows, 'consumer'));
  }

  public function test_schema_histories_are_per_consumer(): void {
    $bil = $this->console('ddd:schema:dump', ['--consumer' => 'bil'])->getDisplay();
    self::assertStringStartsWith('CREATE SCHEMA IF NOT EXISTS billing;', $bil);
    self::assertStringContainsString('CREATE TABLE IF NOT EXISTS billing.ddd_outbox', $bil);

    $sfk = $this->console('ddd:schema:dump', ['--consumer' => 'sfk', '--since' => '009'])->getDisplay();
    self::assertStringContainsString('ALTER TABLE ddd_outbox ADD COLUMN IF NOT EXISTS unheard_at', $sfk);
    self::assertStringNotContainsString('billing', $sfk);

    // bil's database is at release 009, sfk's at the head: bil's next migration touches bil only.
    $this->db->executeStatement('DROP SCHEMA billing CASCADE');
    PostgresSchema::apply($this->db, 'billing.', null, 9);
    self::assertFalse($this->hasColumn('billing', 'ddd_outbox', 'unheard_at'));
    foreach (PostgresSchema::statements('billing.', 9) as $sql) {
      $this->db->executeStatement($sql);
    }
    self::assertTrue($this->hasColumn('billing', 'ddd_outbox', 'unheard_at'));
    self::assertTrue($this->hasColumn('public', 'ddd_outbox', 'unheard_at'));

    self::assertSame(1, $this->console('ddd:schema:dump', ['--consumer' => 'nope'])->getStatusCode());
  }

  // ── helpers ─────────────────────────────────────────────────────────────

  /** Relay every consumer, then consume everything routed to $consumer's facts transport. */
  private function deliverTo(string $consumer): void {
    $this->console('ddd:relay', ['--once' => true]);
    $this->consume($consumer === 'sfk' ? 'ddd_facts' : 'ddd_facts_' . $consumer);
  }

  private function consume(string $queue, int $limit = 1): void {
    $consume = $this->console('messenger:consume', ['receivers' => [$queue], '--limit' => $limit, '--time-limit' => 10]);
    self::assertSame(0, $consume->getStatusCode(), $consume->getDisplay() . $consume->getErrorOutput());
  }

  /** @return list<string> */
  private function bills(): array {
    return array_map('strval', $this->db->fetchFirstColumn('SELECT widget_id FROM billing.bills ORDER BY id'));
  }

  private function hasColumn(string $schema, string $table, string $column): bool {
    return (bool) $this->db->fetchOne(
      'SELECT 1 FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?',
      [$schema, $table, $column]
    );
  }
}
