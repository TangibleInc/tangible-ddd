<?php
/**
 * Forwarding shim (wave 2, register 1.1 and report B5).
 *
 * The self-consumer container moved to packages/ddd-wp/wordpress/self/. Every
 * legacy loader's self-consume hook requires `<winner>/ddd-wordpress/self/index.php`,
 * and a missing file there is an uncatchable fatal, so this path forwards.
 * Owned by packaging from the end of wave 2.
 */
require_once dirname(__DIR__, 2) . '/packages/ddd-wp/wordpress/self/index.php';
