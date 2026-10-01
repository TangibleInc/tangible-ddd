<?php
/**
 * Forwarding shim (register 1.1 and X5; report B5). Owned by packaging from
 * the end of wave 2; ships for the whole compatibility window (>=0.6.2 <0.7).
 *
 * The self-consumer container moved to packages/ddd-wp/wordpress/self/. Every
 * legacy loader's self-consume hook (`tangible_ddd_self_consume`, defined by
 * whichever copy loads first) requires `<winner>/ddd-wordpress/self/index.php`,
 * and a missing file there is an uncatchable fatal at plugins_loaded:20, so
 * this path forwards. It defines nothing itself.
 */
require_once dirname(__DIR__, 2) . '/packages/ddd-wp/wordpress/self/index.php';
