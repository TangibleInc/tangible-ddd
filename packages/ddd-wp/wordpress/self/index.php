<?php

declare(strict_types=1);

/**
 * TangibleDDD self-consumer DI container.
 *
 * Stands up the framework as a consumer of itself: a Symfony container loading
 * the self tactician + services yaml, with IDDDConfig bound to the framework's
 * own Config (prefix 'tangible_ddd'). Exposes di() so the base Command can find
 * the CommandBus. Required once at plugins_loaded pri 20 by the self-consume
 * hook in tangible-ddd.php — NOT autoloaded.
 */

namespace TangibleDDD\WordPress\SelfConsumer;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

// Self-consumer's own inflector (required by path; the framework's di/ copy is
// stale against the installed tactician layout).
require_once __DIR__ . '/HandlerClassNameInflector.php';

function di(?ContainerBuilder $container_instance = null): ContainerBuilder {
    static $container;
    if (defined('DOING_TANGIBLE_TESTS') && $container_instance) {
        $container = $container_instance;
    }
    return $container ?: ($container = $container_instance);
}

// Build once. index.php is require_once'd a single time per request by the
// self-consume hook, so we build unconditionally (never call di() unset).
$builder = new ContainerBuilder();
$loader  = new YamlFileLoader($builder, new FileLocator(__DIR__));
$loader->load('tactician.yaml');
$loader->load('services.yaml');
$builder->compile();
di($builder);
