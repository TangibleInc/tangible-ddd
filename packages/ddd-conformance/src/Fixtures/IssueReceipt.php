<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures;

use TangibleDDD\Application\Commands\ITransactionalCommand;

/** Transactional command whose handler returns a Receipt (D11). */
final class IssueReceipt implements ITransactionalCommand {

  public function __construct(public readonly string $widget_id) {}
}
