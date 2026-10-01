<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Process;

use InvalidArgumentException;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Process\AwaitRoute;

/**
 * Any-of await (D3): suspend until the first fact that one of the branches
 * accepts. Answer branches resume the next step with that fact (type the
 * step's second parameter as the union of the answer classes). Cancellation
 * branches (cancelled_by()) compensate the process instead, with the reason
 * "Cancelled by <class>" (ICancellingAwait), as a failed alarm does.
 *
 *   AwaitAny::of(AwaitEvent::keyed(BackupReplicated::class, $ref),
 *                AwaitEvent::keyed(BackupFailed::class, $ref))
 *     ->cancelled_by(new AwaitEvent(ApplicationDestroyScheduled::class, ['app_id' => $id]))
 *     ->until($deadline, AwaitAll::TIMEOUT_FAIL);
 *
 * Optional alarm: until() (absolute UTC, D7) or within() (seconds from the
 * suspension); TIMEOUT_PROCEED resumes the next step with a null fact.
 *
 * Indexing: routes() lists every branch's route. event_class() (the
 * `waiting_for` column) is the most specific class or interface that every
 * branch class shares. A store that indexes only that column finds the
 * process for every branch either by matching a fact's parents and
 * interfaces itself (mem, pdo: IMatchesFactAncestry) or, on an exact-match
 * store (wp, LegacyProcessStore), because the runner also looks the fact's
 * IIntegrationEvent ancestors up; accepts() filters the rest.
 *
 * Branches are AwaitEvent (one arrival each).
 */
final class AwaitAny implements IAwaitMechanism, IRoutedAwait, ICancellingAwait, IHasDeadline {

  /** @var list<AwaitEvent> */
  public readonly array $answers;
  /** @var list<AwaitEvent> */
  public readonly array $cancellations;

  /**
   * @param list<AwaitEvent> $answers
   * @param list<AwaitEvent> $cancellations
   * @param ?string $until absolute deadline, ISO 8601 UTC
   * @param ?int $arrived index of the branch that took a fact (answers first, then cancellations)
   */
  public function __construct(
    array $answers,
    array $cancellations = [],
    public readonly int $timeout_seconds = 0,
    public readonly string $on_timeout = AwaitAll::TIMEOUT_FAIL,
    public readonly ?string $until = null,
    public readonly ?int $arrived = null,
  ) {
    foreach ([...$answers, ...$cancellations] as $branch) {
      if (!$branch instanceof AwaitEvent) {
        throw new InvalidArgumentException('AwaitAny branches must be AwaitEvent, got ' . get_debug_type($branch));
      }
    }
    if ($answers === []) {
      throw new InvalidArgumentException('AwaitAny needs at least one answer branch');
    }
    if ($timeout_seconds < 0) {
      throw new InvalidArgumentException('AwaitAny timeout_seconds must be >= 0');
    }
    if (!in_array($on_timeout, [AwaitAll::TIMEOUT_FAIL, AwaitAll::TIMEOUT_PROCEED], true)) {
      throw new InvalidArgumentException("Unknown on_timeout policy: $on_timeout");
    }
    $this->answers = array_values($answers);
    $this->cancellations = array_values($cancellations);
    if ($arrived !== null && ($arrived < 0 || $arrived >= count($this->answers) + count($this->cancellations))) {
      throw new InvalidArgumentException("AwaitAny arrived index $arrived is out of range");
    }
  }

  public static function of(AwaitEvent ...$answers): self {
    return new self(array_values($answers));
  }

  /** Add cancellation branches: a fact they accept compensates the process. */
  public function cancelled_by(AwaitEvent ...$cancellations): self {
    return new self($this->answers, [...$this->cancellations, ...$cancellations], $this->timeout_seconds, $this->on_timeout, $this->until, $this->arrived);
  }

  /** Absolute alarm (D7): the Timeout intent is due exactly at $deadline (UTC). */
  public function until(\DateTimeImmutable $deadline, string $on_timeout = AwaitAll::TIMEOUT_FAIL): self {
    $utc = $deadline->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    return new self($this->answers, $this->cancellations, 0, $on_timeout, $utc, $this->arrived);
  }

  /** Relative alarm: due $seconds after the suspension (fixed once, then absolute). */
  public function within(int $seconds, string $on_timeout = AwaitAll::TIMEOUT_FAIL): self {
    return new self($this->answers, $this->cancellations, $seconds, $on_timeout, null, $this->arrived);
  }

  /** @return list<AwaitEvent> */
  public function branches(): array {
    return [...$this->answers, ...$this->cancellations];
  }

  public function event_class(): string {
    return self::common_class(array_map(static fn (AwaitEvent $b) => $b->event_class(), $this->branches()));
  }

  public function accepts(IIntegrationEvent $event): bool {
    return $this->arrived === null && $this->branch_for($event) !== null;
  }

  public function accumulate(IIntegrationEvent $event): static {
    if ($this->arrived !== null) {
      return $this;
    }
    $index = $this->branch_for($event);
    if ($index === null) {
      return $this;
    }
    return new static($this->answers, $this->cancellations, $this->timeout_seconds, $this->on_timeout, $this->until, $index);
  }

  public function is_satisfied(): bool {
    return $this->arrived !== null;
  }

  public function resume_argument(?IIntegrationEvent $last_event): mixed {
    return $last_event;
  }

  public function cancellation_reason(IIntegrationEvent $event): ?string {
    if ($this->arrived === null || $this->arrived < count($this->answers)) {
      return null;
    }
    $class = get_class($event);
    $short = substr($class, (int) strrpos('\\' . $class, '\\'));
    return "Cancelled by $short";
  }

  /** The index of the branch that took a fact, null while waiting. */
  public function arrived(): ?int {
    return $this->arrived;
  }

  public function timeout_seconds(): int {
    return $this->timeout_seconds;
  }

  public function on_timeout(): string {
    return $this->on_timeout;
  }

  public function deadline(): ?\DateTimeImmutable {
    return $this->until === null ? null : new \DateTimeImmutable($this->until, new \DateTimeZone('UTC'));
  }

  public function routes(): array {
    $routes = [];
    foreach ($this->branches() as $branch) {
      foreach ($branch->routes() as $route) {
        $routes[$route->event_class . "\0" . $route->await_key] = $route;
      }
    }
    return array_values($routes);
  }

  public function to_array(): array {
    return [
      'answers' => array_map(static fn (AwaitEvent $b) => $b->to_array(), $this->answers),
      'cancellations' => array_map(static fn (AwaitEvent $b) => $b->to_array(), $this->cancellations),
      'timeout_seconds' => $this->timeout_seconds,
      'on_timeout' => $this->on_timeout,
      'until' => $this->until,
      'arrived' => $this->arrived,
    ];
  }

  public static function from_array(array $data): static {
    return new static(
      array_map(static fn (array $b) => AwaitEvent::from_array($b), array_values((array) ($data['answers'] ?? []))),
      array_map(static fn (array $b) => AwaitEvent::from_array($b), array_values((array) ($data['cancellations'] ?? []))),
      (int) ($data['timeout_seconds'] ?? 0),
      $data['on_timeout'] ?? AwaitAll::TIMEOUT_FAIL,
      isset($data['until']) ? (string) $data['until'] : null,
      isset($data['arrived']) ? (int) $data['arrived'] : null,
    );
  }

  private function branch_for(IIntegrationEvent $event): ?int {
    foreach ($this->branches() as $i => $branch) {
      if ($branch->accepts($event)) {
        return $i;
      }
    }
    return null;
  }

  /**
   * The most specific class or interface every given class is (itself, a
   * parent or an interface). IIntegrationEvent at worst. Ties break by name,
   * so the value is stable across requests.
   *
   * @param list<string> $classes
   */
  private static function common_class(array $classes): string {
    $classes = array_values(array_unique($classes));
    if (count($classes) === 1) {
      return $classes[0];
    }
    $shared = null;
    foreach ($classes as $class) {
      $ancestry = [$class, ...array_values(class_parents($class) ?: []), ...array_values(class_implements($class) ?: [])];
      $shared = $shared === null ? $ancestry : array_values(array_intersect($shared, $ancestry));
    }
    $shared = $shared ?: [IIntegrationEvent::class];
    usort($shared, static function (string $a, string $b): int {
      $depth = static fn (string $c) => count(class_parents($c) ?: []) + count(class_implements($c) ?: []);
      return [$depth($b), $a] <=> [$depth($a), $b];
    });
    return $shared[0];
  }
}
