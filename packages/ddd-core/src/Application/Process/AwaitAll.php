<?php

namespace TangibleDDD\Application\Process;

use InvalidArgumentException;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Process\AwaitRoute;

/**
 * Fan-in await (AND-join): suspend until an event of $event_class has arrived
 * for EVERY key in $expected. Routing is set-membership of the event's key —
 * extracted by a STATIC METHOD ON THE PROCESS ($key_by, announced per-await) —
 * against ids the saga itself minted. The framework counts arrivals; each
 * entity owns its own outcome; the coordinator's post-await step judges the
 * group (resume_argument() hands it this mechanism: gathered vs expected).
 *
 * Keyed form (D3, wave 4): AwaitAll::keyed() takes the key from the fact
 * itself (IAwaitKeyed::await_key(); $key_by is the BY_AWAIT_KEY sentinel)
 * and indexes one route per key still missing. Build $expected at step time
 * (a dynamic key set, e.g. the child ids of a parent) and checkpoint it with
 * the step; the set is persisted with the await before the step's commands
 * dispatch. An empty set does not suspend: the runner proceeds at once.
 *
 * timeout_seconds is REQUIRED (pure wall clock; every serious join has one).
 * On fire: TIMEOUT_FAIL → compensation; TIMEOUT_PROCEED → resume with the
 * partial mechanism.
 */
final class AwaitAll implements IAwaitMechanism, IRoutedAwait {

  public const TIMEOUT_FAIL = 'fail';
  public const TIMEOUT_PROCEED = 'proceed';

  /** $key_by sentinel: the key is the fact's IAwaitKeyed::await_key(). */
  public const BY_AWAIT_KEY = [IAwaitKeyed::class, 'await_key'];

  /** @var class-string<IIntegrationEvent> */
  public readonly string $event_class;
  /** @var array{class-string, string} */
  public readonly array $key_by;

  public function __construct(
    string $event_class,
    public readonly array $expected,
    array $key_by,
    public readonly int $timeout_seconds,
    public readonly string $on_timeout = self::TIMEOUT_FAIL,
    public readonly array $gathered = [],
  ) {
    if (!is_a($event_class, IIntegrationEvent::class, true)) {
      throw new InvalidArgumentException("AwaitAll expects an IIntegrationEvent class, got: $event_class");
    }
    if ($timeout_seconds <= 0) {
      throw new InvalidArgumentException('AwaitAll requires a positive timeout_seconds — unbounded joins wedge sagas.');
    }
    if (array_values($key_by) !== self::BY_AWAIT_KEY && (count($key_by) !== 2 || !is_callable($key_by))) {
      throw new InvalidArgumentException('AwaitAll key_by must be a callable [class, static method] pair.');
    }
    if (!in_array($on_timeout, [self::TIMEOUT_FAIL, self::TIMEOUT_PROCEED], true)) {
      throw new InvalidArgumentException("Unknown on_timeout policy: $on_timeout");
    }
    $this->event_class = $event_class;
    $this->key_by = array_values($key_by);
  }

  /**
   * D3 fan-in over a dynamic key set: one $event_class fact per key, each
   * reporting its key through IAwaitKeyed::await_key().
   *
   * @param list<string|int> $keys
   */
  public static function keyed(string $event_class, array $keys, int $timeout_seconds, string $on_timeout = self::TIMEOUT_FAIL): self {
    $keys = array_values(array_unique(array_map('strval', $keys)));
    return new self($event_class, $keys, self::BY_AWAIT_KEY, $timeout_seconds, $on_timeout);
  }

  public function is_keyed(): bool {
    return $this->key_by === self::BY_AWAIT_KEY;
  }

  public function event_class(): string { return $this->event_class; }

  public function accepts(IIntegrationEvent $event): bool {
    if (!$event instanceof $this->event_class) {
      return false;
    }
    $key = $this->key_of($event);
    if ($key === null && $this->is_keyed()) {
      return false;
    }
    return in_array($key, $this->expected, true)
        && !in_array($key, $this->gathered, true);
  }

  public function accumulate(IIntegrationEvent $event): static {
    $key = $this->key_of($event);
    if (($key === null && $this->is_keyed()) || in_array($key, $this->gathered, true)) {
      return $this;
    }
    return new static(
      $this->event_class,
      $this->expected,
      $this->key_by,
      $this->timeout_seconds,
      $this->on_timeout,
      [...$this->gathered, $key],
    );
  }

  public function is_satisfied(): bool {
    return count($this->gathered) >= count($this->expected);
  }

  /** The coordinator sees gathered vs expected — partial detection on timeout. */
  public function resume_argument(?IIntegrationEvent $last_event): mixed { return $this; }

  public function timeout_seconds(): int { return $this->timeout_seconds; }
  public function on_timeout(): string { return $this->on_timeout; }

  public function gathered(): array { return $this->gathered; }
  public function expected(): array { return $this->expected; }
  public function missing(): array { return array_values(array_diff($this->expected, $this->gathered)); }

  /** Keyed: one route per missing key. Extractor form: the class, unkeyed (the key comes from the process). */
  public function routes(): array {
    if (!$this->is_keyed()) {
      return [new AwaitRoute($this->event_class, '')];
    }
    return array_map(fn ($key) => new AwaitRoute($this->event_class, (string) $key), $this->missing());
  }

  public function to_array(): array {
    return [
      'event_class' => $this->event_class,
      'expected' => $this->expected,
      'key_by' => $this->key_by,
      'timeout_seconds' => $this->timeout_seconds,
      'on_timeout' => $this->on_timeout,
      'gathered' => $this->gathered,
    ];
  }

  public static function from_array(array $data): static {
    return new static(
      $data['event_class'],
      $data['expected'] ?? [],
      $data['key_by'] ?? [],
      (int) ($data['timeout_seconds'] ?? 0),
      $data['on_timeout'] ?? self::TIMEOUT_FAIL,
      $data['gathered'] ?? [],
    );
  }

  private function key_of(IIntegrationEvent $event): mixed {
    if ($this->is_keyed()) {
      return AwaitRoute::key_of($event);
    }
    return ($this->key_by)($event);
  }
}
