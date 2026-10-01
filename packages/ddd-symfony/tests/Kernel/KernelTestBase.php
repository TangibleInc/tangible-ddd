<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Runtime\RuntimeReset;
use TangibleDDD\Symfony\Tests\Kernel\App\Listeners\WidgetRegisteredListener;
use TangibleDDD\Symfony\Tests\Kernel\App\Reactions\CountRegistrations;
use TangibleDDD\Symfony\Tests\Kernel\App\TestKernel;
use TangibleDDD\Symfony\Tests\Support\PostgresDatabase;

/**
 * Boots the test kernel on a fresh schema: the ddd tables, the app tables
 * and the Messenger tables (messenger:setup-transports). Nothing is wrapped
 * in a per-test transaction.
 */
abstract class KernelTestBase extends KernelTestCase {

  protected Connection $db;

  /** TestKernel variant this class boots. */
  protected static string $variant = 'default';

  protected static function createKernel(array $options = []): KernelInterface {
    return new TestKernel('test', true, $options['variant'] ?? 'default');
  }

  protected function setUp(): void {
    WidgetRegisteredListener::$constructed = 0;
    CountRegistrations::$seen = [];
    self::bootKernel(['variant' => static::$variant]);
    $this->db = self::getContainer()->get('tangible_ddd.connection');

    PostgresDatabase::resetDddSchema($this->db);
    $this->db->executeStatement('DROP TABLE IF EXISTS app_widgets');
    $this->db->executeStatement('DROP TABLE IF EXISTS app_listener_runs');
    $this->db->executeStatement('DROP TABLE IF EXISTS messenger_messages');
    $this->db->executeStatement('CREATE TABLE app_widgets (id TEXT PRIMARY KEY, name TEXT NOT NULL)');
    $this->db->executeStatement('CREATE TABLE app_listener_runs (id BIGSERIAL PRIMARY KEY, listener TEXT NOT NULL, widget_id TEXT NOT NULL, cause_id TEXT NULL)');
    $this->console('messenger:setup-transports');
  }

  protected function tearDown(): void {
    parent::tearDown();
    ConsumerRegistry::reset();
    RuntimeReset::forgetRegistrationsForTests();
    Correlation::reset();
    \TangibleDDD\Runtime\HostDefaults::resetForTests();
  }

  /** @param array<string, mixed> $input */
  protected function console(string $command, array $input = []): CommandTester {
    $application = new Application(self::$kernel);
    $application->setAutoExit(false);
    $tester = new CommandTester($application->find($command));
    $tester->execute($input, ['capture_stderr_separately' => true]);
    return $tester;
  }

  protected function countRows(string $sql, array $params = []): int {
    return (int) $this->db->fetchOne($sql, $params);
  }
}
