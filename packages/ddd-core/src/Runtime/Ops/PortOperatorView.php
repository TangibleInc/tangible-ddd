<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Ops;

use TangibleDDD\Infra\IConsumerIdentity;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\Outbox\IOutboxAdministration;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Runtime\SystemClock;

/**
 * IOperatorView over the core ports (register 3.10, D9), for one consumer:
 *
 * - layer `relay`: IOutboxAdministration::deadLetters(), attempts against
 *   the record's max_attempts; repairs retry, replay, discard;
 * - layer `process`: IProcessStore::findStranded() rows still `running`
 *   (a `scheduled` one is re-queued by the stranded scan, not reported);
 *   repairs resume_stranded, fail_stranded;
 * - every other layer from the IOperatorItemSource list (delivery ledger,
 *   wakeups, workflows, the host transport).
 *
 * Read-only. Items are ordered by layer (enum order), then first seen
 * (oldest first, unknown last), and capped at $limit after merging.
 */
final class PortOperatorView implements IOperatorView {

  /** @param list<IOperatorItemSource> $sources */
  public function __construct(
    private readonly IConsumerIdentity $consumer,
    private readonly ?IOutboxAdministration $outbox = null,
    private readonly ?IProcessStore $processes = null,
    private readonly ?IClock $clock = null,
    private readonly array $sources = [],
  ) {}

  public function list(?Layer $layer = null, int $limit = 100): array {
    $limit = max(0, $limit);
    $items = [];

    if ($this->outbox !== null && ($layer === null || $layer === Layer::Relay)) {
      foreach ($this->outbox->deadLetters($limit) as $letter) {
        $items[] = new OperatorItem(
          Layer::Relay, $this->consumer->prefix(), $letter->event_id, $letter->attempts,
          $letter->record->max_attempts, $letter->error, $letter->deadLetteredAt, ['retry', 'replay', 'discard'],
        );
      }
    }

    if ($this->processes !== null && ($layer === null || $layer === Layer::Process)) {
      foreach ($this->processes->findStranded($this->now()) as $stranded) {
        if ($stranded->status !== 'running') {
          continue;
        }
        $items[] = new OperatorItem(
          Layer::Process, $this->consumer->prefix(), (string) $stranded->processId, 0, null,
          "{$stranded->processClass} stranded at step {$stranded->stepIndex}", $stranded->updatedAt,
          ['resume_stranded', 'fail_stranded'],
        );
      }
    }

    foreach ($this->sources as $source) {
      foreach ($source->items($layer, $limit) as $item) {
        if ($layer === null || $item->layer === $layer) {
          $items[] = $item;
        }
      }
    }

    $order = array_flip(array_map(static fn (Layer $l) => $l->value, Layer::cases()));
    usort($items, static function (OperatorItem $a, OperatorItem $b) use ($order): int {
      return [$order[$a->layer->value], $a->firstSeen === null ? 1 : 0, $a->firstSeen]
        <=> [$order[$b->layer->value], $b->firstSeen === null ? 1 : 0, $b->firstSeen];
    });

    return array_slice($items, 0, $limit);
  }

  private function now(): \DateTimeImmutable {
    return ($this->clock ?? HostDefaults::get(IClock::class) ?? new SystemClock())->now();
  }
}
