<?php

/**
 * tangible/ddd-core in plain PHP: a command, a domain event and a query, end
 * to end, with nothing but Composer's autoloader and core. No database, no
 * WordPress, no framework (register section 8 wave 2 acceptance).
 *
 *   php examples/plain-php/run.php            # from the repository root
 *   DDD_AUTOLOAD=/path/to/vendor/autoload.php php run.php
 *
 * What it wires, in the frozen middleware order (register 3.2):
 *   Correlation (act bracket; audit off) → Transaction (in-memory boundary)
 *   → DomainEventsPublish → SelfExecuting → the command's own handle().
 * The command records an order and raises OrderWasPlaced; a local listener
 * (OrderedListenerDispatcher) updates a read model; a query reads it back.
 * OrderWasPlaced also announces an integration fact, which lands in the
 * in-memory outbox with an absolute due time.
 *
 * Exits 0 and prints "ok" when every step behaved; exits 1 otherwise.
 */

declare(strict_types=1);

namespace Example\PlainPhp;

$autoload = getenv('DDD_AUTOLOAD') ?: null;
foreach ([$autoload, __DIR__ . '/vendor/autoload.php', dirname(__DIR__, 2) . '/vendor/autoload.php'] as $candidate) {
  if ($candidate !== null && is_file($candidate)) {
    require $candidate;
    break;
  }
}
if (!class_exists(\TangibleDDD\Runtime\HostDefaults::class)) {
  fwrite(STDERR, "No Composer autoloader with tangible/ddd-core found (set DDD_AUTOLOAD).\n");
  exit(1);
}

use League\Tactician\CommandBus;
use Psr\Container\ContainerInterface;
use TangibleDDD\Application\Commands\ITransactionalCommand;
use TangibleDDD\Application\Commands\SelfHandlingCommand;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\CorrelationMiddleware;
use TangibleDDD\Application\CQRS\SelfExecutingCommandMiddleware;
use TangibleDDD\Application\Events\DomainEventsPublishMiddleware;
use TangibleDDD\Application\Events\EventRouter;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Logging\Redactor;
use TangibleDDD\Application\Persistence\TransactionalCommandMiddleware;
use TangibleDDD\Application\Queries\SelfHandlingQuery;
use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IAnnouncesIntegration;
use TangibleDDD\Domain\Events\IntegrationEvent;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\Services\OutboxIntegrationEventBus;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\OrderedListenerDispatcher;
use TangibleDDD\Testing\InMemoryOutboxStore;
use TangibleDDD\Testing\InMemoryTransactionBoundary;

// ── The consumer ───────────────────────────────────────────────────────────

/** The host's consumer configuration (every name derives from the prefix). */
final class ShopConfig implements IDDDConfig {
  public function prefix(): string { return 'shop'; }
  public function version(): string { return '1.0.0'; }
  public function table(string $name): string { return 'shop_' . $name; }
  public function hook(string $name): string { return 'shop_' . $name; }
  public function as_group(string $name): string { return 'shop-' . $name; }
  public function option(string $name): string { return 'shop_' . $name; }
  public function domain_action(string $event_name): string { return 'shop_domain_' . $event_name; }
  public function integration_action(string $event_name): string { return 'shop_integration_' . $event_name; }
}

/** A tiny PSR-11 container: id => object or factory. */
final class Services implements ContainerInterface {
  /** @param array<string, object|\Closure> $entries */
  public function __construct(private array $entries = []) {}
  public function set(string $id, object $entry): void { $this->entries[$id] = $entry; }
  public function has(string $id): bool { return isset($this->entries[$id]); }
  public function get(string $id): mixed {
    $entry = $this->entries[$id] ?? throw new \RuntimeException("No service $id");
    return $entry instanceof \Closure ? ($this->entries[$id] = $entry($this)) : $entry;
  }
}

// ── The domain ─────────────────────────────────────────────────────────────

final class OrderBook {
  /** @var array<int, string> order id => sku */
  public array $orders = [];
  /** @var array<string, int> the read model: sku => orders placed */
  public array $placedPerSku = [];
}

/** The integration fact announced to other contexts. */
final class OrderPlaced extends IntegrationEvent {
  public function __construct(public readonly int $order_id = 0, public readonly string $sku = '') {}
}

/** The domain moment; announcing, so the router also puts the fact on the outbox. */
final class OrderWasPlaced extends DomainEvent implements IAnnouncesIntegration {
  public function __construct(public readonly int $order_id, public readonly string $sku) {}
  public function payload(): array { return ['order_id' => $this->order_id, 'sku' => $this->sku]; }
  public function to_integration(): OrderPlaced { return new OrderPlaced($this->order_id, $this->sku); }
}

/** A self-handling, transactional command that returns a receipt (D11). */
final class PlaceOrder extends SelfHandlingCommand implements ITransactionalCommand {
  public function __construct(public readonly int $order_id, public readonly string $sku) {}

  protected function handle(OrderBook $book): string {
    $book->orders[$this->order_id] = $this->sku;
    $this->event(new OrderWasPlaced($this->order_id, $this->sku));
    return "order {$this->order_id} placed";
  }
}

/** A self-handling query over the read model. */
final class OrdersPlacedFor extends SelfHandlingQuery {
  public function __construct(public readonly string $sku) {}

  protected function handle(OrderBook $book): int {
    return $book->placedPerSku[$this->sku] ?? 0;
  }
}

// ── Composition root ───────────────────────────────────────────────────────

$config = new ShopConfig();
$clock = new FrozenClock(new \DateTimeImmutable('2026-10-01 09:00:00', new \DateTimeZone('UTC')));
$book = new OrderBook();
$events = new EventsUnitOfWork();
$outbox = new InMemoryOutboxStore($clock);
$boundary = new InMemoryTransactionBoundary();
$boundary->enlist($outbox);

$listeners = new OrderedListenerDispatcher();
$listeners->listen(OrderWasPlaced::class, static function (OrderWasPlaced $e) use ($book): void {
  $book->placedPerSku[$e->sku] = ($book->placedPerSku[$e->sku] ?? 0) + 1;
});

$services = new Services();
$services->set(OrderBook::class, $book);
$services->set(EventsUnitOfWork::class, $events);
$services->set(CommandBus::class, new CommandBus(
  new CorrelationMiddleware($config, $events, new Redactor()),
  new TransactionalCommandMiddleware($boundary),
  new DomainEventsPublishMiddleware($events, new EventRouter(
    $listeners,
    new OutboxIntegrationEventBus(null, $config, null, $clock, $outbox),
  )),
  new SelfExecutingCommandMiddleware($services),
));
$services->set('tactician.query_bus', new CommandBus(new SelfExecutingCommandMiddleware($services)));

ConsumerRegistry::add($config, static fn () => $services, 'Shop', __NAMESPACE__);

// ── The round trip ─────────────────────────────────────────────────────────

$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
  echo ($ok ? '  ok   ' : '  FAIL ') . $what . "\n";
  if (!$ok) {
    $failures[] = $what;
  }
};

$receipt = (new PlaceOrder(1, 'tea'))->send();
(new PlaceOrder(2, 'tea'))->send();
(new PlaceOrder(3, 'cake'))->send();

$check($receipt === 'order 1 placed', 'the command returns its receipt through every middleware');
$check($book->orders === [1 => 'tea', 2 => 'tea', 3 => 'cake'], 'the handler changed state');
$check((new OrdersPlacedFor('tea'))->send() === 2, 'the domain event updated the read model; the query reads it');
$check((new OrdersPlacedFor('coffee'))->send() === 0, 'a query for nothing reads zero');
$check($boundary->commits() === 3, 'each transactional command committed once');

$ids = $outbox->event_ids();
$first = $ids === [] ? null : $outbox->record_of($ids[0]);
$check(count($ids) === 3, 'each announcement reached the outbox');
$check($first !== null && $first->payload === ['order_id' => 1, 'sku' => 'tea'], 'the fact carries its payload');
$check($first !== null && $first->due_at == $clock->now(), 'with an absolute due time from the clock');
$check($first !== null && $first->command_id !== null, 'raised by its command (the raiser edge)');
$check(Correlation::peek() === null, 'no correlation scope leaks out of the bus');

// ── One bounded drain pass (register 3.6) ──────────────────────────────────
// A cron line would call this; here the relay hands the facts to an
// in-memory transport (a real host passes its queue's ITransport).

$transport = new \TangibleDDD\Testing\InMemoryTransport();
$drain = new \TangibleDDD\Runtime\Drain(
  relay: new \TangibleDDD\Infra\Services\OutboxProcessor(
    $config, null, new \TangibleDDD\Application\Outbox\OutboxConfig(), null,
    null, new \Psr\Log\NullLogger(), $clock, $outbox, $transport, $boundary,
  ),
  clock: $clock,
);
$report = $drain->run_once(maxItems: 2, maxSeconds: 5);
$rest = $drain->run_once();

$check(count($report->relay?->accepted ?? []) === 2 && $report->stopped_by === 'max_items', 'runOnce is bounded by its item budget');
$check(count($rest->relay?->accepted ?? []) === 1 && $rest->stopped_by === 'idle', 'the next pass drains the rest');
$check(count($transport->submissions) === 3 && $outbox->status_of($ids[0]) === 'accepted', 'every fact reached the transport once');

if ($failures !== []) {
  fwrite(STDERR, count($failures) . " check(s) failed\n");
  exit(1);
}
echo "ok\n";
exit(0);
