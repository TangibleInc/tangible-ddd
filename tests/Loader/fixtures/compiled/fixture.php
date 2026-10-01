<?php
/**
 * Include-time half of a load.compiled-containers fixture plugin (register
 * 7.2; report D F5). The plugin bundles tangible/ddd 0.6.5 like the shipped
 * zip, and before including this sets $fx_cc_label to one of the
 * directories next to this file (lms-0_12_0, quiz-0_7_0, certificates-0_3_1).
 *
 * Nothing TangibleDDD is touched here: the fixture's classes are declared
 * lazily through an autoloader for FxCompiled\, the way a shipped plugin
 * builds its compiled container only at boot. The probe
 * (tests/Loader/fixtures/probe.php) instantiates every registered container
 * after the full WordPress boot and resolves each service in its manifest.
 */

if (!isset($fx_cc_label) || !is_file(__DIR__ . "/{$fx_cc_label}/manifest.json")) {
    throw new RuntimeException('fixture.php: $fx_cc_label names no compiled-container fixture');
}
$fx_cc_manifest = json_decode((string) file_get_contents(__DIR__ . "/{$fx_cc_label}/manifest.json"), true, 512, JSON_THROW_ON_ERROR);
$fx_cc_ns = substr($fx_cc_manifest['container_class'], 0, -strlen('\\CompiledContainer'));

$GLOBALS['fx_compiled_containers'][$fx_cc_label] = $fx_cc_manifest;
$GLOBALS['fx_compiled_namespaces'][$fx_cc_ns] = __DIR__ . "/{$fx_cc_label}";

if (!function_exists('fx_compiled_autoload')) {
    function fx_compiled_autoload(string $class): void
    {
        if ($class === 'FxCompiled\\Support\\FixtureConfig') {
            require_once __DIR__ . '/support.php';

            return;
        }
        foreach ($GLOBALS['fx_compiled_namespaces'] ?? [] as $ns => $dir) {
            if ($class === $ns . '\\CompiledContainer') {
                require_once $dir . '/CompiledContainer.php';

                return;
            }
            if (str_starts_with($class, $ns . '\\Consumer\\')) {
                require_once $dir . '/Consumer.php';

                return;
            }
        }
    }
    spl_autoload_register('fx_compiled_autoload');
}
unset($fx_cc_label, $fx_cc_manifest, $fx_cc_ns);
