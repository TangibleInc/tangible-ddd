<?php

declare(strict_types=1);

/**
 * Domain fixtures of the DurableRuntime::compose() cases. One consumer
 * (`pdocompose`), whose namespace root is this namespace, so commands sent
 * with ->send() and facts' names route to it through ConsumerRegistry.
 *
 * Orders live in the host's own table `pdocompose_orders`, written through
 * the same IHostConnection the runtime uses (one transaction covers the
 * domain write and the outbox row).
 */

namespace TangibleDDD\Core\Tests\Pdo\Compose;

use TangibleDDD\Application\Commands\ITransactionalCommand;
use TangibleDDD\Application\Commands\SelfHandlingCommand;
use TangibleDDD\Application\EventHandlers\IntegrationTranslator;
use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Application\Process\Awaits;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Application\Process\Result;
use TangibleDDD\Application\Process\StartsOn;
use TangibleDDD\Application\Queries\SelfHandlingQuery;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\CQRS\CommandBusAware;
use TangibleDDD\Defaults\Pdo\IHostConnection;
use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IAnnouncesIntegration;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Events\IntegrationEvent;
use TangibleDDD\Infra\IConsumerIdentity;

final class ComposeConsumer implements IConsumerIdentity {
  public function prefix(): string { return 'pdocompose'; }
  public function version(): string { return '1.0.0'; }
}

/** What ran, for assertions (the drain runs in this same PHP process). */
final class Trace {
  /** @var list<string> */
  public static array $log = [];
  /** @var array<string, int> command short name => failures still to throw */
  public static array $failures = [];

  public static function reset(): void {
    self::$log = [];
    self::$failures = [];
  }

  public static function note(string $what): void {
    self::$log[] = $what;
    if ((self::$failures[$what] ?? 0) > 0) {
      self::$failures[$what]--;
      throw new \RuntimeException("$what failed on purpose");
    }
  }
}

// ── facts ──────────────────────────────────────────────────────────────────

final class OrderPlaced extends IntegrationEvent {
  public function __construct(public readonly int $order_id = 0, public readonly bool $auto_ship = true) {}
}

final class OrderShipped extends IntegrationEvent {
  public function __construct(public readonly int $order_id = 0) {}
}

final class OrderWasPlaced extends DomainEvent implements IAnnouncesIntegration {
  public function __construct(public readonly int $order_id, public readonly bool $auto_ship) {}
  public function payload(): array { return ['order_id' => $this->order_id, 'auto_ship' => $this->auto_ship]; }
  public function to_integration(): OrderPlaced { return new OrderPlaced($this->order_id, $this->auto_ship); }
}

final class OrderWasShipped extends DomainEvent implements IAnnouncesIntegration {
  public function __construct(public readonly int $order_id) {}
  public function payload(): array { return ['order_id' => $this->order_id]; }
  public function to_integration(): OrderShipped { return new OrderShipped($this->order_id); }
}

// ── commands ───────────────────────────────────────────────────────────────

final class PlaceOrder extends SelfHandlingCommand implements ITransactionalCommand {
  public function __construct(public readonly int $order_id, public readonly bool $auto_ship = true, public readonly bool $fail = false) {}

  protected function handle(IHostConnection $db): string {
    $db->execute('INSERT INTO pdocompose_orders (id, status) VALUES (?, ?)', [$this->order_id, 'placed']);
    $this->event(new OrderWasPlaced($this->order_id, $this->auto_ship));
    if ($this->fail) {
      throw new \RuntimeException('PlaceOrder failed after its writes');
    }
    return "placed {$this->order_id}";
  }
}

final class ShipOrder extends SelfHandlingCommand implements ITransactionalCommand {
  public function __construct(public readonly int $order_id) {}

  protected function handle(IHostConnection $db): void {
    Trace::note('ship');
    $db->execute('UPDATE pdocompose_orders SET status = ? WHERE id = ?', ['shipped', $this->order_id]);
    $this->event(new OrderWasShipped($this->order_id));
  }
}

final class CloseOrder extends SelfHandlingCommand implements ITransactionalCommand {
  public function __construct(public readonly int $order_id, public readonly string $outcome) {}

  protected function handle(IHostConnection $db): void {
    Trace::note('close:' . $this->outcome);
    $db->execute('UPDATE pdocompose_orders SET outcome = ? WHERE id = ?', [$this->outcome, $this->order_id]);
  }
}

/** Starts a FulfilmentSaga by hand, inside the command (deferred start). */
final class StartFulfilment extends SelfHandlingCommand implements ITransactionalCommand {
  public function __construct(public readonly int $order_id, public readonly bool $fail = false) {}

  protected function handle(IHostConnection $db, ProcessRunner $runner): void {
    $db->execute('INSERT INTO pdocompose_orders (id, status) VALUES (?, ?)', [$this->order_id, 'manual']);
    $runner->start(new ManualSaga($this->order_id));
    if ($this->fail) {
      throw new \RuntimeException('StartFulfilment failed after starting');
    }
  }
}

/** A plain (not self-handling) command, handled by whatever the handlers map says. */
final class Ping implements ICommand {
  use CommandBusAware;

  public function __construct(public readonly string $word) {}
}

final class OrderStatus extends SelfHandlingQuery {
  public function __construct(public readonly int $order_id) {}

  protected function handle(IHostConnection $db): ?array {
    return $db->fetchOne('SELECT status, outcome FROM pdocompose_orders WHERE id = ?', [$this->order_id]);
  }
}

// ── listener and processes ─────────────────────────────────────────────────

final class ShipOnOrderPlaced extends IntegrationTranslator {
  protected function get_event_class(): string { return OrderPlaced::class; }

  protected function get_command(IIntegrationEvent $event): ?ICommand {
    /** @var OrderPlaced $event */
    return $event->auto_ship ? new ShipOrder($event->order_id) : null;
  }
}

/**
 * Ignited by OrderPlaced; waits up to an hour for the shipment, then closes
 * the order as `shipped` or `timed_out` (TIMEOUT_PROCEED).
 */
#[StartsOn(OrderPlaced::class)]
#[Awaits(OrderShipped::class)]
final class FulfilmentSaga extends LongProcess {
  public const TIMEOUT = 3600;

  public function __construct(public readonly int $order_id = 0) {
    parent::__construct(null);
  }

  public static function from_event(OrderPlaced $e): static {
    return new static($e->order_id);
  }

  protected function wait_for_shipping(): Result {
    Trace::note('wait');
    return new Result(await: new AwaitAll(
      event_class: OrderShipped::class,
      expected: [$this->order_id],
      key_by: [self::class, 'key'],
      timeout_seconds: self::TIMEOUT,
      on_timeout: AwaitAll::TIMEOUT_PROCEED,
    ));
  }

  protected function close(mixed $payload, AwaitAll $shipping): Result {
    return new Result(commands: [new CloseOrder($this->order_id, $shipping->missing() === [] ? 'shipped' : 'timed_out')]);
  }

  public static function key(OrderShipped $e): int {
    return $e->order_id;
  }
}

/** Started by a command (StartFulfilment); ships, then waits like the saga. */
#[Awaits(OrderShipped::class)]
final class ManualSaga extends LongProcess {
  public function __construct(public readonly int $order_id = 0) {
    parent::__construct(null);
  }

  protected function ship(): Result {
    Trace::note('manual-ship');
    return new Result(
      commands: [new ShipOrder($this->order_id)],
      await: new AwaitAll(OrderShipped::class, [$this->order_id], [self::class, 'key'], 600, AwaitAll::TIMEOUT_PROCEED),
    );
  }

  protected function close(mixed $payload, AwaitAll $shipping): Result {
    return new Result(commands: [new CloseOrder($this->order_id, $shipping->missing() === [] ? 'shipped' : 'timed_out')]);
  }

  public static function key(OrderShipped $e): int {
    return $e->order_id;
  }
}
