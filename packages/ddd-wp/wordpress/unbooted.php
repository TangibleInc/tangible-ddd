<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress;

// ddd-wp init for a WordPress that never boots (wave 5 cleanup of the LMS
// fix). A consumer test bootstrap that defines its add_action stub BEFORE
// vendor/autoload.php makes the loader defer registration and the winner's
// initializer to plugins_loaded:0/1; when that bootstrap never fires
// plugins_loaded, hooks.php is never included and neither its eager wiring
// nor register_lazily() runs. The loader requires this file at its include
// time in that branch and calls wire_unbooted(its root). Guarded by
// function_exists: every 0.7+ copy ships it, and the first include wins.
if (!function_exists(__NAMESPACE__ . '\\wire_unbooted')) {

  /**
   * Install HostDefaultsWiring::register_unbooted($root) when this process
   * can be a WordPress that never fires plugins_loaded: add_action exists,
   * plugins_loaded has not fired, and it is not a real WordPress load
   * (WPINC is undefined; a real one always fires plugins_loaded, where the
   * winner wires itself). A no-op everywhere else, and it loads no class
   * then. Idempotent.
   *
   * @param string $root the distribution root of the loader that calls it
   */
  function wire_unbooted(string $root): void {
    if (defined('WPINC') || !function_exists('add_action')) {
      return;
    }
    if (function_exists('did_action') && did_action('plugins_loaded')) {
      return;
    }
    \TangibleDDD\WordPress\Adapter\HostDefaultsWiring::register_unbooted($root);
  }
}
