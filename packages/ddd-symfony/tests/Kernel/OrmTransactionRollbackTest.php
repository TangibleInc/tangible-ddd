<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel;

use Doctrine\ORM\EntityManagerInterface;
use TangibleDDD\Symfony\Tests\Kernel\App\Orm\Commands\SaveOrmWidgetCommand;

/**
 * L6 (TXP tenancy-reference, correctness): with
 * `tangible_ddd.transaction.entity_manager` set to a real Doctrine ORM
 * EntityManager on the command connection, a rolled-back act must not leave
 * its scheduled ORM changes behind for the next act's flush, and a flush that
 * closed the EntityManager must not break the acts after it. Real ORM 3,
 * DoctrineBundle and Postgres 16; one kernel serves every act of a test, as
 * in a long-running worker.
 */
final class OrmTransactionRollbackTest extends KernelTestBase {

  protected static string $variant = 'orm';

  protected function setUp(): void {
    parent::setUp();
    $this->db->executeStatement('DROP TABLE IF EXISTS app_orm_widgets');
    $this->db->executeStatement('CREATE TABLE app_orm_widgets (id VARCHAR(255) PRIMARY KEY, name VARCHAR(255) NOT NULL UNIQUE)');
  }

  public function test_a_rolled_back_act_leaves_no_scheduled_insert_for_the_next_act(): void {
    $this->expectFailure(fn () => (new SaveOrmWidgetCommand('a', 'first', failAfter: true))->send());

    (new SaveOrmWidgetCommand('b', 'second'))->send();

    self::assertSame(['b'], $this->ids(), 'the failed act\'s persist() must not be flushed by the next act');
  }

  public function test_a_rolled_back_act_leaves_no_scheduled_update_for_the_next_act(): void {
    (new SaveOrmWidgetCommand('a', 'original'))->send();

    $this->expectFailure(fn () => (new SaveOrmWidgetCommand('a', 'renamed', failAfter: true, rename: true))->send());
    (new SaveOrmWidgetCommand('b', 'second'))->send();

    self::assertSame('original', $this->db->fetchOne("SELECT name FROM app_orm_widgets WHERE id = 'a'"));
  }

  public function test_a_flush_that_closed_the_entity_manager_does_not_break_the_next_act(): void {
    $this->db->executeStatement("INSERT INTO app_orm_widgets (id, name) VALUES ('x', 'taken')");

    $this->expectFailure(fn () => (new SaveOrmWidgetCommand('y', 'taken'))->send());
    (new SaveOrmWidgetCommand('z', 'free'))->send();

    self::assertSame(['x', 'z'], $this->ids());
    self::assertFalse($this->db->isTransactionActive());
  }

  public function test_the_application_sees_an_open_entity_manager_after_a_failed_flush(): void {
    $this->db->executeStatement("INSERT INTO app_orm_widgets (id, name) VALUES ('x', 'taken')");

    $this->expectFailure(fn () => (new SaveOrmWidgetCommand('y', 'taken'))->send());

    /** @var EntityManagerInterface $em */
    $em = self::getContainer()->get('doctrine')->getManager();
    self::assertTrue($em->isOpen(), 'the registry hands out a usable EntityManager after the rollback');
    self::assertSame(0, $em->getUnitOfWork()->size(), 'nothing of the failed act is still managed');
  }

  private function expectFailure(callable $act): void {
    try {
      $act();
    } catch (\Throwable) {
      return;
    }
    self::fail('the act was expected to fail');
  }

  /** @return list<string> */
  private function ids(): array {
    return array_map('strval', $this->db->fetchFirstColumn('SELECT id FROM app_orm_widgets ORDER BY id'));
  }
}
