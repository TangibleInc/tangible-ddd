<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Ops;

use TangibleDDD\Runtime\Ops\IOperatorView;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Runtime\Ops\OperatorItem;

/**
 * Wave 5, several consumers: the one operator view of the app, over each
 * consumer's own view (a PortOperatorView on its stores). Items come in
 * layer order (Layer::cases()), within a layer in consumer order, and carry
 * their consumer's prefix (OperatorItem::$consumer); list() can be narrowed
 * to one consumer by name.
 *
 * Error behaviour: an unknown consumer name is an \InvalidArgumentException;
 * storage errors propagate.
 */
final class ConsumersOperatorView implements IOperatorView {

  /** @param array<string, IOperatorView> $views consumer name → its view, primary first */
  public function __construct(private readonly array $views) {}

  /** @return list<string> */
  public function consumers(): array {
    return array_keys($this->views);
  }

  /** @return list<OperatorItem> */
  public function list(?Layer $layer = null, int $limit = 100, ?string $consumer = null): array {
    if ($consumer !== null && !isset($this->views[$consumer])) {
      throw new \InvalidArgumentException(sprintf('Unknown consumer "%s"; one of %s.', $consumer, implode(', ', $this->consumers())));
    }
    $views = $consumer === null ? $this->views : [$consumer => $this->views[$consumer]];
    $order = array_flip(array_map(static fn (Layer $l) => $l->value, Layer::cases()));

    $rows = [];
    $n = 0;
    foreach (array_values($views) as $c => $view) {
      foreach ($view->list($layer, $limit) as $item) {
        $rows[] = [$order[$item->layer->value], $c, $n++, $item];
      }
    }
    usort($rows, static fn (array $a, array $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

    return array_slice(array_map(static fn (array $r) => $r[3], $rows), 0, max(0, $limit));
  }
}
