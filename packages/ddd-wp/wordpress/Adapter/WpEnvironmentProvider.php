<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Runtime\Audit\IEnvironmentProvider;

/**
 * The wp IEnvironmentProvider (register 3.9): the PHP and WordPress
 * versions, as 0.6 wrote them into `environment`. CorrelationMiddleware adds
 * `plugin` (the consumer's version) after these keys, giving 0.6's
 * {php, wp, plugin}. Never throws.
 */
final class WpEnvironmentProvider implements IEnvironmentProvider {

  public function describe(): array {
    return [
      'php' => PHP_VERSION,
      'wp' => function_exists('get_bloginfo') ? (string) get_bloginfo('version') : 'unknown',
    ];
  }
}
