<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Audit;

/**
 * Host context for the audit row (register 3.9). wp: blog_id, WP version;
 * sf: environment name. Error behaviour: never throws. Values are scalars.
 */
interface IEnvironmentProvider {

  /** @return array<string, scalar|null> */
  public function describe(): array;
}
