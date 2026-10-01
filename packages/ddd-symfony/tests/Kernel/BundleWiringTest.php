<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel;

use Symfony\Component\DependencyInjection\Exception\LogicException;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\NestedTransactionRejected;
use TangibleDDD\Symfony\Runtime\CompiledSubscriptionRegistry;
use TangibleDDD\Symfony\Tests\Kernel\App\Commands\RegisterWidgetCommand;
use TangibleDDD\Symfony\Tests\Kernel\App\Commands\RenameWidgetCommand;
use TangibleDDD\Symfony\Tests\Kernel\App\Events\WidgetFact;
use TangibleDDD\Symfony\Tests\Kernel\App\Events\WidgetRegistered;
use TangibleDDD\Symfony\Tests\Kernel\App\Listeners\AnyWidgetFactListener;
use TangibleDDD\Symfony\Tests\Kernel\App\Listeners\FlakyListener;
use TangibleDDD\Symfony\Tests\Kernel\App\Listeners\WidgetRegisteredListener;
use TangibleDDD\Symfony\Tests\Kernel\App\Persistence\UnusedReport;
use TangibleDDD\Symfony\Tests\Kernel\App\Persistence\WidgetRepository;
use TangibleDDD\Symfony\Tests\Kernel\App\TestKernel;

final class BundleWiringTest extends KernelTestBase {

  public function test_the_compiled_subscription_map_lists_listeners_without_building_them(): void {
    /** @var CompiledSubscriptionRegistry $registry */
    $registry = self::getContainer()->get(ISubscriptionRegistry::class);
    $specs = array_column($registry->specs(), 'event', 'id');

    self::assertSame(WidgetRegistered::class, $specs['listener:' . WidgetRegisteredListener::class]);
    self::assertSame(WidgetFact::class, $specs['listener:' . AnyWidgetFactListener::class]);
    self::assertSame(WidgetRegistered::class, $specs['listener:' . FlakyListener::class]);
    self::assertSame(0, WidgetRegisteredListener::$constructed);

    $order = array_map(fn ($s) => $s->id, $registry->for(WidgetRegistered::class));
    self::assertSame([
      'listener:' . WidgetRegisteredListener::class,
      'listener:' . AnyWidgetFactListener::class,
      'listener:' . FlakyListener::class,
    ], $order);
  }

  public function test_the_consumer_is_registered_at_boot_and_survives_kernel_reset(): void {
    self::assertSame('sfk', ConsumerRegistry::owner_of(RegisterWidgetCommand::class)->prefix());
    self::assertSame('sfk_integration_widget_registered', WidgetRegistered::integration_action());

    self::getContainer()->get('services_resetter')->reset();
    self::getContainer()->get('services_resetter')->reset();

    (new RegisterWidgetCommand('after-reset'))->send();
    self::assertSame(1, $this->countRows("SELECT count(*) FROM app_widgets WHERE id = 'after-reset'"));
  }

  public function test_a_self_handling_command_reaches_a_private_service_and_returns_its_value(): void {
    (new RegisterWidgetCommand('w1', 'old'))->send();

    $result = (new RenameWidgetCommand('w1', 'new'))->send();

    self::assertSame(['renamed' => 1], $result);
    self::assertSame('new', $this->db->fetchOne("SELECT name FROM app_widgets WHERE id = 'w1'"));
  }

  public function test_a_returning_command_handler_is_autoconfigured_and_its_value_comes_back(): void {
    // L1 (wave3-notes): IReturningCommandHandler carries the command-handler tag (D11 for plain handlers).
    self::assertSame(['team' => 't-1', 'quote' => 500], (new \TangibleDDD\Symfony\Tests\Kernel\App\Commands\QuoteToyCommand('t-1'))->send());
  }

  public function test_the_handle_locator_does_not_keep_unused_private_services_alive(): void {
    self::assertFalse(self::getContainer()->has(UnusedReport::class), 'unused private services are removed');
    self::assertTrue(self::getContainer()->has(WidgetRepository::class), 'handle() dependencies are kept');
  }

  public function test_a_command_dispatched_inside_an_open_transaction_is_rejected(): void {
    $this->db->beginTransaction();
    try {
      (new RegisterWidgetCommand('nested'))->send();
      self::fail('expected NestedTransactionRejected');
    } catch (NestedTransactionRejected) {
      self::assertTrue($this->db->isTransactionActive(), 'the outer transaction is untouched');
    } finally {
      $this->db->rollBack();
    }
    self::assertNull(Correlation::peek());
  }

  public function test_doctrine_transaction_on_the_delivery_bus_fails_compilation(): void {
    $kernel = new TestKernel('test', true, 'doctrine_transaction');
    try {
      $kernel->boot();
      self::fail('expected the health check to refuse the container');
    } catch (LogicException $e) {
      self::assertStringContainsString('doctrine_transaction', $e->getMessage());
      self::assertStringContainsString('messenger.bus.default', $e->getMessage());
    } finally {
      $kernel->shutdown();
    }
  }

  public function test_schema_dump_prints_the_ddl(): void {
    $out = $this->console('ddd:schema:dump', ['--prefix' => 'app_'])->getDisplay();

    self::assertStringContainsString('CREATE TABLE IF NOT EXISTS app_ddd_outbox', $out);
    self::assertStringContainsString('CREATE TABLE IF NOT EXISTS app_ddd_delivery_ledger', $out);
    self::assertLessThan(strpos($out, '002_dlq.sql'), strpos($out, '001_outbox.sql'), 'files in number order');
  }

  public function test_the_effect_journal_is_the_dbal_journal_on_the_command_connection(): void {
    $journal = self::getContainer()->get('test.effect_journal');
    self::assertInstanceOf(\TangibleDDD\Symfony\Persistence\DbalEffectJournal::class, $journal);

    $journal->store('k', new \TangibleDDD\Runtime\Effects\EffectResult(['v' => 1]));
    self::assertSame(1, $this->countRows('SELECT count(*) FROM ddd_effect_journal'));
  }

  public function test_schema_dump_since_prints_only_the_later_files(): void {
    $out = $this->console('ddd:schema:dump', ['--since' => '007'])->getDisplay();

    self::assertStringContainsString('008_workflows.sql', $out);
    self::assertStringNotContainsString('ddd_outbox', $out);
    self::assertStringNotContainsString('007_wakeups.sql', $out);
  }

  public function test_relay_limit_and_time_limit(): void {
    (new RegisterWidgetCommand('a'))->send();
    (new RegisterWidgetCommand('b'))->send();
    (new RegisterWidgetCommand('c'))->send();

    $one = $this->console('ddd:relay', ['--once' => true, '--limit' => '1']);
    self::assertStringContainsString('accepted 1', $one->getDisplay());
    self::assertSame(2, $this->countRows("SELECT count(*) FROM ddd_outbox WHERE status = 'pending'"));

    $loop = $this->console('ddd:relay', ['--limit' => '1', '--time-limit' => '2', '--sleep' => '1']);
    self::assertSame(0, $loop->getStatusCode());
    self::assertSame(0, $this->countRows("SELECT count(*) FROM ddd_outbox WHERE status = 'pending'"), 'the loop drained the rest');
  }

  public function test_a_failed_message_leaks_nothing_into_the_next_one_in_the_same_worker(): void {
    (new RegisterWidgetCommand('boom-1'))->send();
    (new RegisterWidgetCommand('w2'))->send();
    $this->console('ddd:relay', ['--once' => true]);

    $consume = $this->console('messenger:consume', ['receivers' => ['ddd_facts'], '--limit' => 2, '--time-limit' => 10]);
    self::assertSame(0, $consume->getStatusCode(), $consume->getErrorOutput());

    $w2Story = $this->db->fetchOne("SELECT correlation_id FROM ddd_outbox WHERE payload LIKE '%\"w2\"%'");
    self::assertSame($w2Story, $this->db->fetchOne("SELECT cause_id FROM app_listener_runs WHERE listener = 'registered' AND widget_id = 'w2'"),
      'the second message ran in its own story, not the failed one');
    self::assertSame(1, $this->countRows("SELECT count(*) FROM ddd_delivery_ledger WHERE subscriber_id = ? AND delivered_at IS NULL AND attempts = 1",
      ['listener:' . FlakyListener::class]), 'the failure is counted against the flaky subscriber only');
    self::assertSame(1, $this->countRows("SELECT count(*) FROM messenger_messages WHERE queue_name = 'ddd_facts'"), 'boom-1 waits for its Messenger retry');
    self::assertNull(Correlation::peek());
  }
}
