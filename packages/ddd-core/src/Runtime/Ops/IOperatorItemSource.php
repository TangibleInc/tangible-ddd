<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Ops;

/**
 * One layer's contribution to PortOperatorView (wave3-core CR-W3C-5): the
 * delivery ledger (failed / exhausted subscribers), the wakeup store
 * (intents that failed at least once), a workflow store, or a host
 * transport's failure queue (sf: the Messenger failure transport).
 *
 * Error behaviour: storage errors propagate. Returns at most $limit items,
 * only of $layer when it is given (an empty list for a layer it does not
 * hold).
 */
interface IOperatorItemSource {

  /** @return list<OperatorItem> */
  public function items(?Layer $layer, int $limit): array;
}
