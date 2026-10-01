<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Unit\Console;

use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Symfony\Console\RelayCommand;
use TangibleDDD\Symfony\Runtime\Relay;
use TangibleDDD\Testing\InMemoryOutboxStore;
use TangibleDDD\Testing\InMemoryTransactionBoundary;
use TangibleDDD\Testing\InMemoryTransport;

final class RelayCommandTest extends TestCase {

  private FrozenClock $clock;
  private InMemoryTransactionBoundary $boundary;
  private InMemoryOutboxStore $inner;

  /** @var list<int> */
  private array $slept = [];

  private AbstractLogger $logger;

  protected function setUp(): void {
    $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-01T12:00:00Z'));
    $this->boundary = new InMemoryTransactionBoundary();
    $this->inner = new InMemoryOutboxStore($this->clock, null, $this->boundary);
    $this->boundary->enlist($this->inner);
    $this->logger = new class extends AbstractLogger {
      /** @var list<array{string, string}> */
      public array $lines = [];
      public function log($level, \Stringable|string $message, array $context = []): void {
        $this->lines[] = [(string) $level, (string) $message];
      }
    };
  }

  /** A store whose claim() raises the given exceptions first, then delegates. */
  private function flakyStore(array $failures): IOutboxStore {
    return new class($this->inner, $failures) implements IOutboxStore {
      public int $claims = 0;
      public function __construct(private readonly IOutboxStore $inner, private array $failures) {}
      public function append(OutboxRecord $r): void { $this->inner->append($r); }
      public function claim(int $limit, \DateTimeImmutable $now, int $leaseSeconds): array {
        $this->claims++;
        if ($this->failures !== []) {
          throw array_shift($this->failures);
        }
        return $this->inner->claim($limit, $now, $leaseSeconds);
      }
      public function accept(Claim $c, ?string $transportRef): bool { return $this->inner->accept($c, $transportRef); }
      public function retryLater(Claim $c, string $error, \DateTimeImmutable $nextAt): bool { return $this->inner->retryLater($c, $error, $nextAt); }
      public function deadLetter(Claim $c, string $error): bool { return $this->inner->deadLetter($c, $error); }
    };
  }

  private function command(IOutboxStore $store, int $maxConsecutiveFailures = 10): RelayCommand {
    $relay = new Relay($store, new InMemoryTransport(), $this->boundary, $this->clock, new OutboxConfig());
    return new RelayCommand($relay, 10, 0, $this->logger, function (int $seconds): void {
      $this->slept[] = $seconds;
    }, $maxConsecutiveFailures);
  }

  private static function dbalError(string $message): DriverException {
    return new ConnectionLost(new class($message) extends \RuntimeException implements \Doctrine\DBAL\Driver\Exception {
      public function getSQLState(): ?string { return '08006'; }
    }, null);
  }

  public function test_a_transient_dbal_error_in_the_loop_is_logged_backed_off_and_the_loop_continues(): void {
    $this->inner->append(new OutboxRecord('a', 'widget_registered', 'txp_integration_widget_registered', 'c', 1, null, [], $this->clock->now()));
    $store = $this->flakyStore([self::dbalError('server closed the connection'), self::dbalError('still down')]);

    $tester = new CommandTester($this->command($store));
    $exit = $tester->execute(['--time-limit' => '0.5']);

    self::assertSame(Command::SUCCESS, $exit);
    self::assertSame('accepted', $this->inner->statusOf('a'), 'the loop survived two failed steps');
    self::assertSame([1, 2], array_slice($this->slept, 0, 2), 'exponential back-off after consecutive failures');
    $errors = array_values(array_filter($this->logger->lines, static fn ($l) => $l[0] === 'error'));
    self::assertCount(2, $errors);
    self::assertStringContainsString('server closed the connection', $errors[0][1]);
  }

  public function test_the_loop_gives_up_after_too_many_consecutive_failures(): void {
    $store = $this->flakyStore(array_map(fn ($i) => self::dbalError("down $i"), range(1, 5)));

    $tester = new CommandTester($this->command($store, maxConsecutiveFailures: 3));
    $exit = $tester->execute([]);

    self::assertSame(Command::FAILURE, $exit);
    self::assertSame(3, $store->claims);
    self::assertStringContainsString('3 consecutive', $tester->getDisplay());
  }

  public function test_once_reports_a_storage_error_as_failure_without_retrying(): void {
    $store = $this->flakyStore([self::dbalError('down')]);

    $tester = new CommandTester($this->command($store));
    $exit = $tester->execute(['--once' => true]);

    self::assertSame(Command::FAILURE, $exit);
    self::assertSame(1, $store->claims);
    self::assertSame([], $this->slept);
  }

  public function test_an_idle_loop_waits_on_the_listen_waiter_instead_of_sleeping(): void {
    $waiter = new class implements \TangibleDDD\Symfony\Runtime\Wakeup\IRelayWaiter {
      /** @var list<float> */
      public array $waits = [];
      public function wait(float $seconds): bool { $this->waits[] = $seconds; usleep(50_000); return false; }
    };
    $relay = new Relay($this->inner, new InMemoryTransport(), $this->boundary, $this->clock, new OutboxConfig());
    $command = new RelayCommand($relay, 10, 2, $this->logger, function (int $s): void { $this->slept[] = $s; }, 10, null, $waiter);

    (new CommandTester($command))->execute(['--time-limit' => '0.2']);

    self::assertNotEmpty($waiter->waits);
    self::assertSame(2.0, $waiter->waits[0]);
    self::assertSame([], $this->slept, 'no plain sleep when a waiter is wired');
  }

  public function test_each_step_also_runs_the_wakeup_relay_and_reports_it(): void {
    $wakeups = new class {
      public int $runs = 0;
    };
    $wakeupRelay = $this->createStub(\TangibleDDD\Symfony\Runtime\Wakeup\IWakeupRelayStep::class);
    $wakeupRelay->method('runOnce')->willReturnCallback(function () use ($wakeups) {
      $wakeups->runs++;
      return new \TangibleDDD\Symfony\Runtime\Wakeup\WakeupRelayReport(['continue:1:0'], [], [], []);
    });
    $relay = new Relay($this->inner, new InMemoryTransport(), $this->boundary, $this->clock, new OutboxConfig());
    $command = new RelayCommand($relay, 10, 0, $this->logger, null, 10, $wakeupRelay);

    $tester = new CommandTester($command);
    $tester->execute(['--once' => true]);

    self::assertSame(1, $wakeups->runs);
    self::assertStringContainsString('wakeups projected 1', $tester->getDisplay());
  }

  public function test_a_non_storage_error_is_not_swallowed(): void {
    $store = $this->flakyStore([new \LogicException('programming error')]);

    $this->expectException(\LogicException::class);
    (new CommandTester($this->command($store)))->execute([]);
  }
}
