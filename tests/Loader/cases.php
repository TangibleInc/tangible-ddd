<?php
/**
 * The register 7.2 load-order cases run by `tests/harness/run.sh loader`
 * (all of them from wave 4). Prints one tab-separated line per case:
 *
 *   <case id> TAB <active_plugins json> TAB <WP_DEBUG 0|1> TAB <late 0|1> TAB <expectations json>
 *
 * Expectations are judged by tests/Loader/assert-case.php.
 *
 * Environment (set by the harness):
 *   DDD_N_VERSION      the new copy's version (the fixtures `new` and `new2`, and P)
 *   DDD_LEGACY         space-separated label=version of the in-window legacy fixtures
 *   DDD_NEGATIVE       label=version of the pre-window fixture (0.2.5)
 *   DDD_PRELOAD        label=version of the legacy fixture that touches a class at include time
 *   DDD_N_NEXT_VERSION version of `next`, the new copy re-versioned (fixtures `next`, `jp-next`)
 *   DDD_JETPACK_LEGACY label=version of a Jetpack-autoloaded legacy fixture (LMS 0.12.0's shape)
 *   DDD_COMPILED       cc-<fixture>=jetpack|composer: the compiled-container fixture plugins
 *   DDD_COMPILED_VERSION  the tangible/ddd version those plugins bundle (default 0.6.5)
 */

declare(strict_types=1);

$n = (string) getenv('DDD_N_VERSION');
$pairs = static function (string $env): array {
    $out = [];
    foreach (preg_split('/\s+/', trim((string) getenv($env))) ?: [] as $pair) {
        if ($pair !== '' && str_contains($pair, '=')) {
            [$label, $version] = explode('=', $pair, 2);
            $out[$label] = $version;
        }
    }

    return $out;
};
$legacy = $pairs('DDD_LEGACY');
$negative = $pairs('DDD_NEGATIVE');
$preload = $pairs('DDD_PRELOAD');

$fx = static fn(string $label): string => "fx-{$label}/fx-{$label}.php";

// The legacy copy paired with P and with the late load: 0.6.5, the copy the
// shipped compiled containers bundle (register 7.1), when it is in the set.
$anchor = array_search('0.6.5', $legacy, true) ?: array_key_last($legacy);
const P = 'tangible-ddd/tangible-ddd.php';

$cases = [];
$add = static function (string $id, array $plugins, array $spec, bool $debug = false, bool $late = false) use (&$cases): void {
    $cases[] = [$id, $plugins, $debug, $late, $spec];
};
$n_wins = static fn(array $extra = []): array => ['winner_version' => $n, 'winner_copy' => 'new'] + $extra;

// N only.
$add('load.new-alone', [$fx('new')], $n_wins(['registered' => [$n => 'new']]));

// Each in-window legacy copy with N, both activation orders.
foreach ($legacy as $label => $version) {
    $spec = $n_wins(['registered' => [$version => $label, $n => 'new'], 'losers' => [$label]]);
    $add("load.legacy-first[{$label}]", [$fx($label), $fx('new')], $spec);
    $add("load.new-first[{$label}]", [$fx('new'), $fx($label)], $spec);
}

// A legacy plugin touches a TangibleDDD\ class at include time, then N.
foreach ($preload as $label => $version) {
    $add("load.preloaded-class[{$label}]", [$fx($label), $fx('new')], $n_wins([
        'registered' => [$version => $label, $n => 'new'],
        'origin_exceptions' => ['TangibleDDD\\Application\\Outbox\\OutboxConfig'],
        'findings' => ['mixed-load'],
        'finding_contains' => ['TangibleDDD\\Application\\Outbox\\OutboxConfig was loaded from copy:' . $label],
    ]));
}

// P (tangible-ddd activated as a plugin) with a legacy copy and with N.
if ($legacy !== []) {
    $label = $anchor;
    foreach (['plugin-first' => [P, $fx($label)], 'plugin-last' => [$fx($label), P]] as $order => $plugins) {
        $add("load.plugin-active[{$order},{$label}]", $plugins, [
            'winner_version' => $n,
            'winner_copy' => 'P',
            'registered' => [$legacy[$label] => $label, $n => 'P'],
            'losers' => [$label],
        ]);
    }
}
$add('load.plugin-active[P,new]', [P, $fx('new')], [
    'winner_version' => $n,
    'winner_copy' => 'P',
    'registered' => [$n => 'P'],
    'losers' => ['new'],
]);

// N + N, the same version in two plugins: one registration, one listener call.
$add('load.new-twice', [$fx('new'), $fx('new2')], $n_wins([
    'registered' => [$n => 'new'],
    'losers' => ['new2'],
]));

// N loaded after plugins_loaded (at init). With nothing initialized it
// registers and initializes on the spot; the self-consume hook (pri 20) has
// passed, as with every 0.x copy. With a legacy winner already initialized
// it is ignored for the request (tangible-ddd.php late-load branch).
$add('load.late[alone]', [], $n_wins([
    'registered' => [$n => 'new'],
    'self_consumer' => 'absent',
    'wp_ddd' => null,
]), false, true);
if ($legacy !== []) {
    $label = $anchor;
    // The late vendor's Composer loader is prepended ahead of the legacy
    // winner's autoloader (Composer registers with prepend), so classes first
    // touched after `init` resolve from it. That is the legacy winner's
    // behaviour, which N cannot change from a copy that never initialised;
    // origins are reported, not judged.
    $add("load.late[after-{$label}]", [$fx($label)], [
        'winner_version' => $legacy[$label],
        'winner_copy' => $label,
        'registered' => [$legacy[$label] => $label],
        'not_initialized' => ['new'],
        'origin_info' => 'late Composer loader prepended ahead of the legacy winner (0.x behaviour, B13)',
    ], false, true);
}

// A consumer requires a version above the winner: reported, never fatal.
$add('load.min-unmet', [$fx('new'), 'fx-needs/fx-needs.php'], $n_wins([
    'registered' => [$n => 'new'],
    'unmet' => ['fx-needs-99' => '99.0.0'],
]));

// 0.2.x + N: unsupported by decision, detected actively (rulings X1).
foreach ($negative as $label => $version) {
    $registered_as = $version === '0.2.5' ? '0.2.4' : $version; // 0.2.5-0.5.2 register as 0.2.4 (B2)
    foreach (['legacy-first' => [$fx($label), $fx('new')], 'new-first' => [$fx('new'), $fx($label)]] as $order => $plugins) {
        $base = [
            'registered' => [$registered_as => $label, $n => 'new'],
            'losers' => [$label],
            'findings' => ['unsupported-version'],
            'finding_contains' => ['TANGIBLE_DDD_UNSUPPORTED_VERSION', 'CorrelationContext'],
        ];
        $add("load.v0-2-negative[{$order},debug]", $plugins, $n_wins($base + [
            'raised' => ['TANGIBLE_DDD_UNSUPPORTED_VERSION'],
            'log_contains' => ['TANGIBLE_DDD_UNSUPPORTED_VERSION'],
        ]), true);
        $add("load.v0-2-negative[{$order},no-debug]", $plugins, $n_wins($base + [
            'raised' => [],
            'log_contains' => ['TANGIBLE_DDD_UNSUPPORTED_VERSION'],
        ]));
    }
}

// LMS (Jetpack Autoloader) + cred (plain Composer) with different builds
// of the new distribution (report D F13): N and `next`, N re-versioned to
// DDD_N_NEXT_VERSION by the driver. Whichever side carries the newer
// build, every TangibleDDD\ class comes from that one copy. Then the
// shipped shape: a Jetpack plugin bundling 0.6.5 (LMS 0.12.0) beside N.
$next = (string) getenv('DDD_N_NEXT_VERSION');
if ($next !== '') {
    $pairs_jp = [
        'jetpack-older' => ['jp-new', $n, 'next', $next, 'next'],
        'jetpack-newer' => ['jp-next', $next, 'new', $n, 'jp-next'],
    ];
    foreach ($pairs_jp as $variant => [$jp, $jp_version, $plain, $plain_version, $winner]) {
        foreach (['jetpack-first' => [$fx($jp), $fx($plain)], 'plain-first' => [$fx($plain), $fx($jp)]] as $order => $plugins) {
            $add("load.jetpack-mixed[{$variant},{$order}]", $plugins, [
                'winner_version' => $next,
                'winner_copy' => $winner,
                'registered' => [$jp_version => $jp, $plain_version => $plain],
                'losers' => [$winner === $jp ? $plain : $jp],
                'single_origin' => true,
                'jetpack' => true,
            ]);
        }
    }
}
foreach ($pairs('DDD_JETPACK_LEGACY') as $label => $version) {
    foreach (['jetpack-first' => [$fx($label), $fx('new')], 'plain-first' => [$fx('new'), $fx($label)]] as $order => $plugins) {
        $add("load.jetpack-mixed[{$label},{$order}]", $plugins, $n_wins([
            'registered' => [$version => $label, $n => 'new'],
            'losers' => [$label],
            'single_origin' => true,
            'jetpack' => true,
        ]));
    }
}

// The compiled containers of the three shipped 0.6.5 consumers (register
// 7.1 L-0.6.5-compiled, report D F5), each as a fixture plugin bundling
// 0.6.5 the way its zip does (Jetpack Autoloader or plain Composer), with N
// winning: every service each container declares resolves. Several copies
// of 0.6.5 are vendored, so only the versions registered are judged.
$compiled = $pairs('DDD_COMPILED');
if ($compiled !== []) {
    $labels = [];
    foreach ($compiled as $plugin => $kind) {
        if (!str_starts_with($plugin, 'cc-') || !in_array($kind, ['jetpack', 'composer'], true)) {
            fwrite(STDERR, "cases.php: DDD_COMPILED entries are cc-<fixture label>=jetpack|composer (cc- prefix), got {$plugin}={$kind}\n");
            exit(1);
        }
        $labels[] = substr($plugin, 3);
    }
    $cc = array_map($fx, array_keys($compiled));
    foreach (['legacy-first' => [...$cc, $fx('new')], 'new-first' => [$fx('new'), ...$cc]] as $order => $plugins) {
        $add("load.compiled-containers[{$order}]", $plugins, $n_wins([
            'registered_versions' => [(string) (getenv('DDD_COMPILED_VERSION') ?: '0.6.5'), $n],
            'losers' => array_keys($compiled),
            'compiled' => $labels,
            'single_origin' => true,
            'jetpack' => in_array('jetpack', $compiled, true),
        ]));
    }
}

foreach ($cases as [$id, $plugins, $debug, $late, $spec]) {
    echo implode("\t", [
        $id,
        json_encode(array_values($plugins), JSON_UNESCAPED_SLASHES),
        $debug ? '1' : '0',
        $late ? '1' : '0',
        json_encode($spec, JSON_UNESCAPED_SLASHES),
    ]), "\n";
}
