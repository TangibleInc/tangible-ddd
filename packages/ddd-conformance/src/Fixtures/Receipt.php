<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures;

/** The DTO a command returns through every middleware (D11). */
final class Receipt {

  public function __construct(
    public readonly string $widget_id,
    public readonly string $number,
  ) {}
}
