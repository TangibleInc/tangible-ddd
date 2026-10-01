<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Effects;

use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\Ops\IOperatorItemSource;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Runtime\Ops\OperatorItem;
use TangibleDDD\Runtime\SystemClock;

/**
 * The operator view's `effect` layer (TXP demand E2, wave 5): journal
 * entries performed more than $after_seconds ago and still not recorded.
 * The provider charged, purged or created, and the domain never learned of
 * it; a compensation must undo the upstream effect.
 *
 * Item: key = the idempotency key, first_seen = performed_at, no budget,
 * repair `invalidate` (IEffectJournal::invalidate() in a repair command's
 * transaction, then re-dispatch). A host adds it to PortOperatorView's
 * sources (or its own view) next to the ledger and wakeup sources.
 *
 * Error behaviour: storage errors propagate.
 */
final class UnrecordedEffects implements IOperatorItemSource {

  public const DEFAULT_AFTER_SECONDS = 300;

  public function __construct(
    private readonly ITracksEffectState $journal,
    private readonly string $consumer,
    private readonly ?IClock $clock = null,
    private readonly int $after_seconds = self::DEFAULT_AFTER_SECONDS,
  ) {}

  public function items(?Layer $layer, int $limit): array {
    if ($layer !== null && $layer !== Layer::Effect) {
      return [];
    }

    $before = ($this->clock ?? new SystemClock())->now()->modify('-' . max(0, $this->after_seconds) . ' seconds');
    $items = [];
    foreach ($this->journal->find_unrecorded($before, $limit) as $entry) {
      $items[] = new OperatorItem(
        Layer::Effect, $this->consumer, $entry->key, 0, null,
        sprintf('performed at %s, not recorded', $entry->performed_at->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM)),
        $entry->performed_at, ['invalidate'],
      );
    }
    return $items;
  }
}
