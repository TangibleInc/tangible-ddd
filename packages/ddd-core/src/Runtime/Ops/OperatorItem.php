<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Ops;

/**
 * One row of the operator view (register 3.10, D9): what failed, where,
 * how often against which budget, the last error, when it was first seen,
 * and the repair commands that apply.
 */
final class OperatorItem {

  /**
   * @param string $key event_id (relay), `subscriber@event_id` (delivery),
   *   the intent key (wakeup), the process id (process), or the host's id
   * @param int|null $budget null when the layer has no retry budget
   * @param list<string> $repairs repair command names, e.g. retry, replay, discard, resume_stranded, fail_stranded
   */
  public function __construct(
    public readonly Layer $layer,
    public readonly string $consumer,
    public readonly string $key,
    public readonly int $attempts,
    public readonly ?int $budget,
    public readonly ?string $last_error,
    public readonly ?\DateTimeImmutable $first_seen,
    public readonly array $repairs = [],
  ) {}

  /**
   * The array form a host renders (pdo `OperatorView::list()` returns these;
   * snake_case keys, times ISO 8601 UTC).
   *
   * @return array{layer: string, layer_label: string, consumer: string, key: string, attempts: int, budget: ?int,
   *   last_error: ?string, first_seen: ?string, repair_actions: list<string>}
   */
  public function to_array(): array {
    return [
      'layer' => $this->layer->value,
      'layer_label' => $this->layer->label(),
      'consumer' => $this->consumer,
      'key' => $this->key,
      'attempts' => $this->attempts,
      'budget' => $this->budget,
      'last_error' => $this->last_error,
      'first_seen' => $this->first_seen?->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
      'repair_actions' => $this->repairs,
    ];
  }
}
