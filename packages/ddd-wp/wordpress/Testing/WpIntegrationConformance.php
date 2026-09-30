<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Testing;

use TangibleDDD\Application\EventHandlers\IntegrationListener;
use TangibleDDD\Application\EventHandlers\IntegrationTranslator;
use TangibleDDD\Testing\IntegrationConformance;

/**
 * The WordPress form of IntegrationConformance (register 1.4). The core form
 * scans IntegrationTranslator subclasses; this subclass also treats the
 * IntegrationListener constructor (the one that registers the listener on
 * its integration hook) as framework wiring, exactly as the 0.6 scanner did,
 * so a listener that inherits it is never judged by it.
 *
 * `TangibleDDD\Testing\IntegrationConformance` keeps working for WordPress
 * consumer suites unchanged; this class is the explicit WordPress entry point.
 */
class WpIntegrationConformance extends IntegrationConformance {

  protected static function listener_bases(): array {
    return [IntegrationTranslator::class, IntegrationListener::class];
  }
}
