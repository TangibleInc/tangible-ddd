<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Conformance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TangibleDDD\Application\Commands\Command;
use TangibleDDD\Application\Commands\ITransactionalCommand;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Core\Tests\Pdo\Conformance\Support\ConformanceDatabase;
use TangibleDDD\Defaults\Pdo\DurableRuntime;
use TangibleDDD\Defaults\Pdo\PdoConnection;
use TangibleDDD\Defaults\Pdo\SchemaSql;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\IConsumerIdentity;
use TangibleDDD\Runtime\Audit\Audit;
use TangibleDDD\Runtime\Audit\IAuditSink;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\RuntimeReset;
use TangibleDDD\Testing\InMemoryAuditSink;

/**
 * CR-W4CE-3 on pdo: DurableRuntime::compose() passes no audit policy, so
 * its act bracket falls back to AttributeAuditPolicy and honours
 * #[Audit(false)] / #[Audit(parameters: false)] with the host's audit sink.
 * The conformance fixture's bus (PdoHostFixture) uses the same policy.
 */
#[Group('pdo')]
final class PdoComposeAuditPolicyTest extends TestCase {

  private string $database;
  private \PDO $pdo;

  protected function setUp(): void {
    $this->database = 'ddd_w4_pdoconf4_audit_' . bin2hex(random_bytes(4));
    ConformanceDatabase::create($this->database);
    HostDefaults::reset_for_tests();
    RuntimeReset::forget_for_tests();
    ConsumerRegistry::reset();
    Correlation::reset();
    HostDefaults::provide(LoggerInterface::class, new NullLogger());
  }

  protected function tearDown(): void {
    HostDefaults::reset_for_tests();
    RuntimeReset::forget_for_tests();
    ConsumerRegistry::reset();
    Correlation::reset();
    ConformanceDatabase::drop($this->database, [ConformanceDatabase::connectionId($this->pdo)]);
  }

  /** @return array<string, array{bool}> */
  public static function modes(): array {
    return ['native' => [false], 'emulated' => [true]];
  }

  #[DataProvider('modes')]
  public function test_compose_honours_audit_attributes_through_the_default_policy(bool $emulatePrepares): void {
    $this->pdo = ConformanceDatabase::connect($this->database, $emulatePrepares);
    $db = new PdoConnection($this->pdo);
    foreach (SchemaSql::statements('pdoaudit_') as $statement) {
      $db->execute($statement);
    }
    $sink = new InMemoryAuditSink();
    HostDefaults::provide(IAuditSink::class, $sink);

    $ran = [];
    $handler = static function (object $c) use (&$ran): void {
      $ran[] = get_class($c);
    };
    $rt = DurableRuntime::compose($db, new AuditConsumer(), [
      AuditedCommand::class => $handler,
      UnauditedCommand::class => $handler,
      ParameterlessCommand::class => $handler,
    ], [], []);

    $rt->bus()->handle(new AuditedCommand('a-1'));
    $rt->bus()->handle(new UnauditedCommand('u-1'));
    $rt->bus()->handle(new ParameterlessCommand('p-1'));

    self::assertSame([AuditedCommand::class, UnauditedCommand::class, ParameterlessCommand::class], $ran, 'every command ran');
    $names = array_map(static fn ($o) => $o->command_name, $sink->opened);
    self::assertCount(2, $sink->opened, '#[Audit(false)] writes no row: ' . implode(', ', $names));
    self::assertCount(2, $sink->closed);
    foreach ($names as $name) {
      self::assertStringNotContainsString('UnauditedCommand', $name);
    }
    self::assertStringContainsString('AuditedCommand', $names[0]);
    self::assertStringContainsString('ParameterlessCommand', $names[1]);

    $paramsOf = static fn ($open) => json_encode((array) ($open->parameters ?? []));
    self::assertStringContainsString('a-1', $paramsOf($sink->opened[0]), 'an audited command keeps its parameters');
    self::assertStringNotContainsString('p-1', $paramsOf($sink->opened[1]), '#[Audit(parameters: false)] drops them');
  }
}

final class AuditConsumer implements IConsumerIdentity {
  public function prefix(): string { return 'pdoaudit'; }
  public function version(): string { return '1.0.0'; }
}

final class AuditedCommand extends Command implements ITransactionalCommand {
  public function __construct(public readonly string $ref) {}
}

#[Audit(false)]
final class UnauditedCommand extends Command implements ITransactionalCommand {
  public function __construct(public readonly string $ref) {}
}

#[Audit(parameters: false)]
final class ParameterlessCommand extends Command implements ITransactionalCommand {
  public function __construct(public readonly string $ref) {}
}
