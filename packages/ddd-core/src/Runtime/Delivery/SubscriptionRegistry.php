<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Delivery;

/** In-process ISubscriptionRegistry (pdo default, mem, raw PHP). */
final class SubscriptionRegistry implements ISubscriptionRegistry {

  /** @var array<string, array{sub: Subscriber, seq: int}> */
  private array $subscribers = [];

  private int $seq = 0;

  public function add(Subscriber $s): void {
    if (isset($this->subscribers[$s->id])) {
      return;
    }
    $this->subscribers[$s->id] = ['sub' => $s, 'seq' => ++$this->seq];
  }

  public function for(string $eventClass): array {
    $matching = array_filter(
      $this->subscribers,
      static fn (array $e) => is_a($eventClass, $e['sub']->event_class, true)
    );
    usort($matching, static fn (array $a, array $b) => [$a['sub']->priority, $a['seq']] <=> [$b['sub']->priority, $b['seq']]);

    return array_map(static fn (array $e) => $e['sub'], $matching);
  }
}
