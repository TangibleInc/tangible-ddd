<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Audit;

final class Actor {

  public function __construct(
    public readonly ActorKind $kind,
    public readonly ?string $id,
    public readonly ?string $label = null,
  ) {}
}
