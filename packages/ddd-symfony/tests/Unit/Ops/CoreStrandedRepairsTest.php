<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Unit\Ops;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Symfony\Console\Ops\StrandedCommand;
use TangibleDDD\Symfony\Ops\CoreStrandedRepairs;
use TangibleDDD\Symfony\Persistence\DbalWakeupScheduler;

/**
 * WP8-10: `ddd:ops:stranded --resume|--fail` dispatch core's
 * ResumeStrandedProcess / FailStrandedProcess once they exist (class_exists
 * guard until core-process merges).
 */
final class CoreStrandedRepairsTest extends TestCase {

  public function test_the_core_commands_are_built_from_their_constructors(): void {
    $dispatched = [];
    $repairs = new CoreStrandedRepairs(
      static function (object $command) use (&$dispatched): void {
        $dispatched[] = $command;
      },
      FakeResumeStrandedProcess::class,
      FakeFailStrandedProcess::class,
    );

    self::assertTrue($repairs->available());
    $repairs->resume(7);
    $repairs->fail(8, 'payment provider gone');

    self::assertEquals([new FakeResumeStrandedProcess(7), new FakeFailStrandedProcess(8, 'payment provider gone')], $dispatched);
  }

  public function test_until_core_ships_them_the_repairs_are_unavailable(): void {
    $repairs = new CoreStrandedRepairs(static fn () => null, 'TangibleDDD\\Nope\\ResumeStrandedProcess', 'TangibleDDD\\Nope\\FailStrandedProcess');

    self::assertFalse($repairs->available());
    $this->expectException(\LogicException::class);
    $repairs->resume(1);
  }

  public function test_the_default_class_names_are_core_s(): void {
    self::assertSame('TangibleDDD\\Application\\Process\\ResumeStrandedProcess', CoreStrandedRepairs::RESUME);
    self::assertSame('TangibleDDD\\Application\\Process\\FailStrandedProcess', CoreStrandedRepairs::FAIL);
  }

  public function test_ops_stranded_dispatches_the_core_repairs_when_available(): void {
    $dispatched = [];
    $repairs = new CoreStrandedRepairs(
      static function (object $command) use (&$dispatched): void {
        $dispatched[] = $command;
      },
      FakeResumeStrandedProcess::class,
      FakeFailStrandedProcess::class,
    );
    $store = $this->createMock(IProcessStore::class);
    $store->expects(self::never())->method('find'); // the inline repair is not used
    $command = new StrandedCommand(
      $store,
      new DbalWakeupScheduler($this->createStub(Connection::class)),
      $this->createStub(ITransactionBoundary::class),
      $this->createStub(IProcessLock::class),
      new FrozenClock(new \DateTimeImmutable('2026-10-01T12:00:00Z')),
      'txp',
      repairs: $repairs,
    );

    $t = new CommandTester($command);
    self::assertSame(Command::SUCCESS, $t->execute(['--resume' => ['7'], '--fail' => ['8'], '--reason' => 'gone']));

    self::assertEquals([new FakeResumeStrandedProcess(7), new FakeFailStrandedProcess(8, 'gone')], $dispatched);
  }

  public function test_a_failing_core_repair_fails_the_command(): void {
    $repairs = new CoreStrandedRepairs(
      static fn () => throw new \RuntimeException('process #7 is not stranded'),
      FakeResumeStrandedProcess::class,
      FakeFailStrandedProcess::class,
    );
    $command = new StrandedCommand(
      $this->createStub(IProcessStore::class),
      new DbalWakeupScheduler($this->createStub(Connection::class)),
      $this->createStub(ITransactionBoundary::class),
      $this->createStub(IProcessLock::class),
      new FrozenClock(new \DateTimeImmutable('2026-10-01T12:00:00Z')),
      'txp',
      repairs: $repairs,
    );

    $t = new CommandTester($command);
    self::assertSame(Command::FAILURE, $t->execute(['--resume' => ['7']]));
    self::assertStringContainsString('not stranded', $t->getDisplay());
  }
}

final class FakeResumeStrandedProcess {
  public function __construct(public readonly int $processId) {}
}

final class FakeFailStrandedProcess {
  public function __construct(public readonly int $processId, public readonly string $reason = 'operator') {}
}
