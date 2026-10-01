<?php
/**
 * Builds a load.compiled-containers fixture (register 7.2, 7.1
 * L-0.6.5-compiled; report D F5) from a shipped consumer's Symfony
 * CompiledContainer.php. The shipped zips are never committed or needed at
 * test time; this script is how the committed copies were made.
 *
 *   php tests/Loader/bin/extract-compiled-container.php \
 *       <CompiledContainer.php> <label> <short> <source description> <out dir>
 *
 *   e.g. ... x/tangible-lms/var/container/CompiledContainer.php lms-0_12_0 Lms \
 *            tangible-lms-0.12.0.zip tests/Loader/fixtures/compiled/lms-0_12_0
 *
 * It keeps, verbatim, every public service factory that constructs a
 * TangibleDDD class (the frozen fixed-arity `new \TangibleDDD\...(...)`
 * calls) and the factories those call, plus the TangibleDDD aliases. The
 * consumer's own classes in those factories are renamed into
 * FxCompiled\<short>\Consumer\ and replaced by stubs with the same
 * constructor arguments (Consumer.php): the consumer Config becomes a
 * fixture IDDDConfig, a consumer outbox repository extends the library's
 * wpdb OutboxRepository (setter calls become no-ops), a consumer middleware
 * passes through. Anything else is refused, so the fixture never silently
 * drops a ddd constructor call.
 *
 * Writes <out dir>/CompiledContainer.php, Consumer.php and manifest.json.
 */

declare(strict_types=1);

[, $source, $label, $short, $origin, $out] = $argv + array_fill(0, 6, null);
if ($out === null) {
    fwrite(STDERR, "usage: extract-compiled-container.php <CompiledContainer.php> <label> <Short> <source> <out dir>\n");
    exit(64);
}
$php = (string) file_get_contents($source);
$fail = static function (string $msg): never {
    fwrite(STDERR, "extract-compiled-container: {$msg}\n");
    exit(1);
};

preg_match('/^namespace\s+([\w\\\\]+);/m', $php, $m) || $fail('no namespace');
$segments = explode('\\', $m[1]);
$consumer_root = $segments[0] . '\\' . $segments[1];             // Tangible\LMS
$fx_ns = 'FxCompiled\\' . $short;                               // FxCompiled\Lms
$fx_consumer = $fx_ns . '\\Consumer';                           // FxCompiled\Lms\Consumer

// methodMap and aliases.
preg_match('/\$this->methodMap = \[(.*?)\n        \];/s', $php, $mm) || $fail('no methodMap');
preg_match_all("/^\s*'((?:[^'\\\\]|\\\\.)+)' => '(\w+)',$/m", $mm[1], $pairs, PREG_SET_ORDER);
$method_map = [];
foreach ($pairs as [, $id, $method]) {
    $method_map[stripslashes($id)] = $method;
}
$aliases = [];
if (preg_match('/\$this->aliases = \[(.*?)\n        \];/s', $php, $am)) {
    preg_match_all("/^\s*'((?:[^'\\\\]|\\\\.)+)' => '((?:[^'\\\\]|\\\\.)+)',$/m", $am[1], $ap, PREG_SET_ORDER);
    foreach ($ap as [, $from, $to]) {
        $aliases[stripslashes($from)] = stripslashes($to);
    }
}

// Factory methods with their @return types.
preg_match_all(
    '/    \/\*\*\n((?:     \*[^\n]*\n)*?)     \*\/\n    protected static function (\w+)\(\$container(?:, \$lazyLoad = true)?\)\n    \{\n(.*?)\n    \}\n/s',
    $php,
    $methods,
    PREG_SET_ORDER
);
$bodies = [];
$returns = [];
foreach ($methods as [, $doc, $name, $body]) {
    $bodies[$name] = $body;
    if (preg_match('/@return (\S+)/', $doc, $r)) {
        $returns[$name] = ltrim($r[1], '\\');
    }
}

// Seeds: public services outside the consumer namespace whose factory
// constructs a TangibleDDD class; then their factory closure.
$selected = [];
foreach ($method_map as $id => $method) {
    if (str_starts_with($id, $consumer_root . '\\')) {
        continue;
    }
    if (isset($bodies[$method]) && (str_starts_with($id, 'TangibleDDD\\') || preg_match('/\\\\TangibleDDD\\\\/', $bodies[$method]))) {
        $selected[$id] = $method;
    }
}
$selected !== [] || $fail('no service constructs a TangibleDDD class');
$needed = array_values($selected);
for ($i = 0; $i < count($needed); $i++) {
    isset($bodies[$needed[$i]]) || $fail("factory {$needed[$i]} not found");
    preg_match_all('/self::(\w+)\(/', $bodies[$needed[$i]], $calls);
    foreach ($calls[1] as $call) {
        if (!in_array($call, $needed, true)) {
            $needed[] = $call;
        }
    }
}
foreach ($needed as $method) {
    if (preg_match('/getParameter|\$container->parameters|->load\(|getEnv|lazyLoad/', $bodies[$method])) {
        $fail("factory {$method} uses parameters, files or lazy loading; the fixture does not support it");
    }
}

$rename = static function (string $code) use ($consumer_root, $fx_consumer): string {
    $code = str_replace('\\' . $consumer_root . '\\', '\\' . $fx_consumer . '\\', $code);
    $code = str_replace("'" . str_replace('\\', '\\\\', $consumer_root) . '\\\\', "'" . str_replace('\\', '\\\\', $fx_consumer) . '\\\\', $code);

    return $code;
};
$rename_fqcn = static fn(string $fqcn): string => str_starts_with($fqcn, $consumer_root . '\\')
    ? $fx_consumer . substr($fqcn, strlen($consumer_root))
    : $fqcn;

// Consumer classes the kept factories construct, and their stub role.
$config_class = isset($aliases['TangibleDDD\\Infra\\IDDDConfig']) ? $rename_fqcn($aliases['TangibleDDD\\Infra\\IDDDConfig']) : null;
$stubs = [];
foreach ($needed as $method) {
    $body = $rename($bodies[$method]);
    preg_match_all('/new \\\\(' . preg_quote($fx_consumer, '/') . '\\\\[\w\\\\]+)\(/', $body, $news);
    foreach (array_unique($news[1]) as $class) {
        $role = null;
        if ($class === $config_class) {
            $role = ['config'];
        } elseif (preg_match('/^\s*\$instance = new \\\\' . preg_quote($class, '/') . '\(/m', $body)
            && ($returns[$method] ?? null) !== null
            && array_search($method, $method_map, true) === 'TangibleDDD\\Infra\\IOutboxRepository') {
            preg_match_all('/\$instance->(\w+)\(/', $body, $setters);
            $role = ['outbox-repository', array_values(array_unique($setters[1]))];
        } elseif (preg_match('/new \\\\League\\\\Tactician\\\\CommandBus\(/', $body)
            && preg_match('/\?\?= new \\\\' . preg_quote($class, '/') . '\(\)\)/', $body)) {
            $role = ['middleware'];
        }
        $role !== null || $fail("consumer class {$class} in {$method} has no known stub role");
        $stubs[$class] = $role;
    }
}
ksort($stubs);

// CompiledContainer.php
$src_sha = hash('sha256', $php);
$map_lines = '';
$expected = [];
foreach ($selected as $id => $method) {
    $map_lines .= '            ' . var_export($rename_fqcn($id), true) . ' => ' . var_export($method, true) . ",\n";
    $expected[$rename_fqcn($id)] = $rename_fqcn($returns[$method] ?? $fail("no @return for {$method}"));
}
$alias_lines = '';
foreach ($aliases as $from => $to) {
    if (str_starts_with($from, 'TangibleDDD\\')) {
        $alias_lines .= '            ' . var_export($from, true) . ' => ' . var_export($rename_fqcn($to), true) . ",\n";
    }
}
$factories = '';
foreach ($needed as $method) {
    $return = isset($returns[$method]) ? '\\' . $rename_fqcn($returns[$method]) : 'object';
    $factories .= "\n    /**\n     * @return {$return}\n     */\n    protected static function {$method}(\$container)\n    {\n"
        . $rename($bodies[$method]) . "\n    }\n";
}
$container = <<<PHP
<?php
// Generated by tests/Loader/bin/extract-compiled-container.php from
// {$origin}: var/container/CompiledContainer.php (sha256 {$src_sha}).
// The factories below are verbatim but for the consumer namespace
// ({$consumer_root}\\ -> {$fx_consumer}\\). Do not edit by hand.

namespace {$fx_ns};

use Symfony\\Component\\DependencyInjection\\Container;

class CompiledContainer extends Container
{
    public function __construct()
    {
        \$this->parameters = [];
        \$this->services = \$this->privates = [];
        \$this->methodMap = [
{$map_lines}        ];
        \$this->aliases = [
{$alias_lines}        ];
    }

    public function isCompiled(): bool
    {
        return true;
    }
{$factories}}

PHP;

// Consumer.php
$consumer = "<?php\n// Stubs of the {$origin} classes the kept factories construct (generated;\n// see tests/Loader/bin/extract-compiled-container.php).\n";
foreach ($stubs as $class => $role) {
    $pos = strrpos($class, '\\');
    $ns = substr($class, 0, $pos);
    $name = substr($class, $pos + 1);
    $consumer .= "\nnamespace {$ns} {\n";
    switch ($role[0]) {
        case 'config':
            $prefix = 'fxcc_' . strtolower($short);
            $consumer .= "    class {$name} extends \\FxCompiled\\Support\\FixtureConfig\n    {\n        public const PREFIX = '{$prefix}';\n    }\n";
            break;
        case 'outbox-repository':
            $consumer .= "    class {$name} extends \\TangibleDDD\\Infra\\Persistence\\OutboxRepository\n    {\n";
            foreach ($role[1] as $setter) {
                $consumer .= "        public function {$setter}(mixed ...\$args): void\n        {\n        }\n";
            }
            $consumer .= "    }\n";
            break;
        case 'middleware':
            $consumer .= "    class {$name} implements \\League\\Tactician\\Middleware\n    {\n        public function execute(\$command, callable \$next)\n        {\n            return \$next(\$command);\n        }\n    }\n";
            break;
    }
    $consumer .= "}\n";
}

// manifest.json
preg_match_all('/new \\\\(TangibleDDD\\\\[\w\\\\]+)\(/', $container, $ddd_new);
preg_match_all('/\\\\(TangibleDDD\\\\[\w\\\\]+)::(\w+)\(/', $container, $ddd_static);
$ddd_fqcns = array_values(array_unique(array_merge($ddd_new[1], $ddd_static[1], array_keys(array_filter($aliases, static fn(string $k): bool => str_starts_with($k, 'TangibleDDD\\'), ARRAY_FILTER_USE_KEY)))));
sort($ddd_fqcns);
$manifest = [
    'label' => $label,
    'source' => $origin . ':var/container/CompiledContainer.php',
    'source_sha256' => $src_sha,
    'container_class' => $fx_ns . '\\CompiledContainer',
    'consumer_namespace' => [$consumer_root, $fx_consumer],
    'services' => $expected,
    'ddd_fqcns' => $ddd_fqcns,
    'stubs' => array_map(static fn(array $r): string => $r[0], $stubs),
];

is_dir($out) || mkdir($out, 0777, true);
file_put_contents($out . '/CompiledContainer.php', $container);
file_put_contents($out . '/Consumer.php', $consumer);
file_put_contents($out . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
printf("%s: %d services, %d factories, %d ddd FQCNs, %d stubs -> %s\n", $label, count($expected), count($needed), count($ddd_fqcns), count($stubs), $out);
