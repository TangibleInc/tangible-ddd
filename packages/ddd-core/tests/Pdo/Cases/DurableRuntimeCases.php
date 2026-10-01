<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Cases;

require_once dirname(__DIR__) . '/Compose/Fixtures.php';

use League\Tactician\CommandBus;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TangibleDDD\Core\Tests\Pdo\Compose\CloseOrder;
use TangibleDDD\Core\Tests\Pdo\Compose\ComposeConsumer;
use TangibleDDD\Core\Tests\Pdo\Compose\FulfilmentSaga;
use TangibleDDD\Core\Tests\Pdo\Compose\ManualSaga;
use TangibleDDD\Core\Tests\Pdo\Compose\OrderPlaced;
use TangibleDDD\Core\Tests\Pdo\Compose\OrderStatus;
use TangibleDDD\Core\Tests\Pdo\Compose\Ping;
use TangibleDDD\Core\Tests\Pdo\Compose\PlaceOrder;
use TangibleDDD\Core\Tests\Pdo\Compose\ShipOnOrderPlaced;
use TangibleDDD\Core\Tests\Pdo\Compose\ShipOrder;
use TangibleDDD\Core\Tests\Pdo\Compose\StartFulfilment;
use TangibleDDD\Core\Tests\Pdo\Compose\Trace;
use TangibleDDD\Core\Tests\Pdo\PdoTestCase;
use TangibleDDD\Defaults\Pdo\DurableRuntime;
use TangibleDDD\Defaults\Pdo\PdoOperatorView;
use TangibleDDD\Defaults\Pdo\SchemaSql;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Runtime\Drain;
use TangibleDDD\Runtime\DrainReport;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Runtime\RuntimeReset;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;

/**
 * DurableRuntime::compose() (register 3.3 frozen signature, ruling #80,
 * W3C-R5) end to end on MySQL 8: the command bus in the core middleware
 * order, the outbox bus, the relay step, IntegrationDelivery, the
 * SubscriptionRegistrar (listeners + #[StartsOn]/#[Awaits] processes), the
 * ProcessRunner on the pdo stores and a core Drain.
 */
abstract class DurableRuntimeCases extends PdoTestCase {

  private const TP = 'pdocompose_';

  /** @var array<string, bool> per database */
  private static array $schemaReady = [];

  private FrozenClock $clock;

  protected function setUp(): void {
    parent::setUp();
    $db = self::databaseName();
    if (!isset(self::$schemaReady[$db])) {
      foreach (SchemaSql::statements(self::TP) as $statement) {
        $this->pdo()->exec($statement);
      }
      $this->pdo()->exec('CREATE TABLE IF NOT EXISTS pdocompose_orders (id INT NOT NULL PRIMARY KEY, status VARCHAR(32) NOT NULL, outcome VARCHAR(32) NULL) ENGINE=InnoDB');
      self::$schemaReady[$db] = true;
    }
    foreach ([...SchemaSql::TABLES] as $logical) {
      $this->pdo()->exec('DELETE FROM `' . self::TP . $logical . '`');
    }
    $this->pdo()->exec('DELETE FROM pdocompose_orders');

    $this->clock = new FrozenClock(self::utc('2026-10-01 12:00:00'));
    ConsumerRegistry::reset();
    HostDefaults::resetForTests();
    RuntimeReset::forgetRegistrationsForTests();
    HostDefaults::provide(LoggerInterface::class, new NullLogger());
    Trace::reset();
  }

  protected function tearDown(): void {
    ConsumerRegistry::reset();
    HostDefaults::resetForTests();
    RuntimeReset::forgetRegistrationsForTests();
    parent::tearDown();
  }

  private function runtime(ContainerInterface|array $handlers = []): DurableRuntime {
    return DurableRuntime::compose($this->db, new ComposeConsumer(), $handlers, [ShipOnOrderPlaced::class], [FulfilmentSaga::class, ManualSaga::class], $this->clock);
  }

  private function order(int $id): ?array {
    return $this->db->fetchOne('SELECT status, outcome FROM pdocompose_orders WHERE id = ?', [$id]);
  }

  /** @return array<string, mixed> */
  private function processOf(string $class): array {
    $row = $this->db->fetchOne('SELECT * FROM pdocompose_ddd_processes WHERE process_class = ? ORDER BY id DESC LIMIT 1', [$class]);
    self::assertNotNull($row, "a $class row");
    return $row;
  }

  private function rows(string $logical, string $where = '1 = 1', array $params = []): int {
    return (int) $this->db->fetchOne('SELECT COUNT(*) AS n FROM `' . self::TP . $logical . "` WHERE $where", $params)['n'];
  }

  private static function assertClean(DrainReport $report): void {
    self::assertSame([], $report->errors, 'no stage failed');
    self::assertSame([], $report->leaks, 'nothing leaked across a message boundary');
  }

  public function test_the_command_bus_commits_the_domain_write_and_the_fact_together(): void {
    $rt = $this->runtime();

    self::assertInstanceOf(CommandBus::class, $rt->bus());
    self::assertSame('placed 1', $rt->bus()->handle(new PlaceOrder(1)));
    self::assertSame('placed 2', (new PlaceOrder(2))->send(), 'send() routes to the composed consumer');

    try {
      $rt->bus()->handle(new PlaceOrder(3, fail: true));
      self::fail('expected the command to fail');
    } catch (\RuntimeException $e) {
      self::assertSame('PlaceOrder failed after its writes', $e->getMessage());
    }

    self::assertNull($this->order(3), 'the failed command rolled back its write');
    self::assertSame(2, $this->rows('ddd_outbox'), '... and its fact');
    $row = $this->db->fetchOne('SELECT event_class, event_type, status FROM pdocompose_ddd_outbox ORDER BY id LIMIT 1');
    self::assertSame([OrderPlaced::class, 'pending'], [$row['event_class'], $row['status']]);
    self::assertSame(['status' => 'placed', 'outcome' => null], $rt->queryBus()->handle(new OrderStatus(1)));
  }

  public function test_a_drain_relays_delivers_ignites_and_resumes_the_process_to_completion(): void {
    $rt = $this->runtime();
    $rt->bus()->handle(new PlaceOrder(1));

    $first = $rt->drain();
    self::assertClean($first);
    self::assertCount(1, $first->relay?->accepted ?? []);
    self::assertSame(1, $first->delivered);
    self::assertSame(['ship', 'wait'], Trace::$log, 'the listener (priority 10) runs before the ignition (50)');
    $saga = $this->processOf(FulfilmentSaga::class);
    self::assertSame('suspended', $saga['status']);
    $timeoutKey = "timeout:{$saga['id']}:0";
    self::assertSame('2026-10-01 13:00:00.000000', $this->db->fetchOne('SELECT due_at FROM pdocompose_ddd_jobs WHERE idempotency_key = ?', [$timeoutKey])['due_at'] ?? null);
    self::assertSame(1, $this->rows('ddd_outbox', "status = 'pending'"), 'OrderShipped waits for the next relay step');

    $second = $rt->drain();
    self::assertClean($second);
    self::assertSame(1, $second->delivered);
    self::assertSame('completed', $this->processOf(FulfilmentSaga::class)['status']);
    self::assertSame(['status' => 'shipped', 'outcome' => 'shipped'], $this->order(1));
    self::assertSame(0, $this->rows('ddd_jobs'), 'the satisfied await cancelled its timeout');

    $idle = $rt->drain();
    self::assertSame([0, DrainReport::STOPPED_IDLE], [$idle->items, $idle->stoppedBy]);
  }

  public function test_the_timeout_proceeds_when_the_fact_never_comes_and_a_late_fact_is_a_noop(): void {
    $rt = $this->runtime();
    $rt->bus()->handle(new PlaceOrder(1, auto_ship: false));
    $rt->drain();
    $saga = $this->processOf(FulfilmentSaga::class);
    self::assertSame('suspended', $saga['status']);

    self::assertSame([], $rt->drain()->wakesCompleted, 'not due yet');
    $this->clock->advance('PT1H1S');
    $report = $rt->drain();

    self::assertClean($report);
    self::assertSame(["timeout:{$saga['id']}:0"], $report->wakesCompleted);
    self::assertSame('completed', $this->processOf(FulfilmentSaga::class)['status']);
    self::assertSame('timed_out', $this->order(1)['outcome']);

    $rt->bus()->handle(new ShipOrder(1));
    $late = $rt->drain();
    self::assertClean($late);
    self::assertSame(1, $late->delivered);
    self::assertSame('timed_out', $this->order(1)['outcome'], 'the late fact never resurrects the process');
    self::assertSame(['wait', 'close:timed_out', 'ship'], Trace::$log);
  }

  public function test_a_stale_timeout_copy_after_completion_is_a_noop(): void {
    $rt = $this->runtime();
    $rt->bus()->handle(new PlaceOrder(1));
    $rt->drain();
    $rt->drain();
    $done = $this->processOf(FulfilmentSaga::class);
    self::assertSame('completed', $done['status']);

    $this->clock->advance('PT2H');
    $copy = WakeupIntent::timeout('pdocompose', (int) $done['id'], 0, self::utc('2026-10-01 13:00:00'));
    $rt->boundary()->run(fn () => $rt->jobs()->schedule($copy));
    $report = $rt->drain();

    self::assertClean($report);
    self::assertSame([$copy->idempotencyKey], $report->wakesCompleted, 'claimed, run, completed');
    $after = $this->processOf(FulfilmentSaga::class);
    self::assertSame([$done['status'], $done['version'], $done['updated_at']], [$after['status'], $after['version'], $after['updated_at']], 'the process row is untouched');
    self::assertSame('shipped', $this->order(1)['outcome']);
    self::assertSame(0, $this->rows('ddd_jobs'));
  }

  public function test_a_command_starts_a_process_deferred_and_atomically_with_its_own_writes(): void {
    $rt = $this->runtime();
    $rt->bus()->handle(new StartFulfilment(5));
    $manual = $this->processOf(ManualSaga::class);
    self::assertSame('scheduled', $manual['status']);
    self::assertSame(1, $this->rows('ddd_jobs', "kind = 'continue' AND process_id = ?", [$manual['id']]));
    self::assertSame([], Trace::$log, 'no step ran inside the command');

    try {
      $rt->bus()->handle(new StartFulfilment(6, fail: true));
      self::fail('expected the command to fail');
    } catch (\RuntimeException) {
    }
    self::assertNull($this->order(6));
    self::assertSame(1, $this->rows('ddd_processes'), 'the failed command rolled its process back too');

    self::assertClean($rt->drain());
    self::assertSame('suspended', $this->processOf(ManualSaga::class)['status']);
    self::assertClean($rt->drain());
    self::assertSame('completed', $this->processOf(ManualSaga::class)['status']);
    self::assertSame('shipped', $this->order(5)['outcome']);
  }

  public function test_handlers_come_from_the_array_form_or_a_container(): void {
    $rt = $this->runtime([Ping::class => static fn (Ping $p): string => "pong {$p->word}"]);
    self::assertSame('pong hi', $rt->bus()->handle(new Ping('hi')));

    $services = new class implements ContainerInterface {
      public function has(string $id): bool { return $id === 'Handlers\\PingHandler'; }
      public function get(string $id): mixed { throw new \LogicException("no $id"); }
    };
    $rt = $this->runtime($services);
    self::assertSame('placed 9', $rt->bus()->handle(new PlaceOrder(9)), 'built-in services resolve before the host container');
    $this->expectException(\LogicException::class);
    $rt->bus()->handle(new Ping('nobody'));
  }

  public function test_the_operator_view_shows_a_failing_listener_and_its_queued_fact(): void {
    Trace::$failures['ship'] = 99;
    $rt = $this->runtime();
    $rt->bus()->handle(new PlaceOrder(1));
    $rt->drain();

    $view = $rt->operatorView();
    self::assertInstanceOf(PdoOperatorView::class, $view);
    $keys = array_map(static fn ($i) => $i->key, $view->list(Layer::Delivery));
    $eventId = $this->db->fetchOne('SELECT event_id FROM pdocompose_ddd_outbox LIMIT 1')['event_id'];
    self::assertSame(['listener:' . ShipOnOrderPlaced::class . "@$eventId", "deliver:$eventId"], $keys);
    self::assertSame('suspended', $this->processOf(FulfilmentSaga::class)['status'], 'the ignition still ran (subscriber isolation)');
  }

  public function test_drain_is_bounded_and_the_core_drain_is_exposed(): void {
    $rt = $this->runtime();
    foreach ([1, 2, 3] as $id) {
      $rt->bus()->handle(new PlaceOrder($id, auto_ship: false));
    }

    self::assertInstanceOf(Drain::class, $rt->drainer());
    $report = $rt->drain(maxItems: 2, maxSeconds: 5);
    self::assertSame(DrainReport::STOPPED_MAX_ITEMS, $report->stoppedBy);
    self::assertSame(2, $report->items);
  }

  public function test_compose_rejects_what_it_cannot_register(): void {
    foreach ([[[Trace::class], []], [[], [CloseOrder::class]]] as [$listeners, $processes]) {
      try {
        DurableRuntime::compose($this->db, new ComposeConsumer(), [], $listeners, $processes, $this->clock);
        self::fail('expected compose() to refuse ' . json_encode([$listeners, $processes]));
      } catch (\InvalidArgumentException $e) {
        self::assertNotSame('', $e->getMessage());
      }
    }
  }
}
