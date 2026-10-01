<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Ops;

/**
 * The one failure view across every layer (register 3.10, 5.1, D9).
 * Read-only; each host merges its layers (PortOperatorView composes the
 * core ports plus IOperatorItemSource layers). Repairs are separate
 * ITransactionalCommands with status and lease guards (C23).
 *
 * Error behaviour: storage errors propagate. Ordering: by layer (enum
 * order), then first seen, oldest first; $limit caps the merged list.
 */
interface IOperatorView {

  /** @return list<OperatorItem> */
  public function list(?Layer $layer = null, int $limit = 100): array;
}
