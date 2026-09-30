<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Effects;

/**
 * The journaled outcome of IExternalEffectCommand::perform() (D1). Scalars
 * only, so every journal can persist it as JSON.
 */
final class EffectResult {

  /** @param array<string, scalar|array|null> $data */
  public function __construct(
    public readonly array $data = [],
    public readonly ?string $externalRef = null,
  ) {}
}
