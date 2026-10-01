<?php
/**
 * Pass conditions of one register 7.2 load-order case, judged on the JSON
 * the probe (tests/Loader/fixtures/probe.php) printed for it. Host-side,
 * plain PHP, no WordPress.
 *
 *   php assert-case.php <case-id> <probe.json> '<expectations json>'
 *
 * Expectations (all optional except winner_version / winner_copy):
 *   winner_version, winner_copy   the registry winner
 *   registered                    exact {version: copy} map of registrations
 *   losers                        copies that must contribute no file beyond their loader entry
 *   not_initialized               copies whose initializer must not have run (late copies)
 *   origin_exceptions             TangibleDDD classes allowed to come from another copy
 *   origin_info                   reason string: report class-origin mismatches as INFO, not failure
 *   self_consumer                 "built" (default) or "absent"
 *   wp_ddd                        whether `wp ddd` must be registered (default true; null: not judged)
 *   round_trip                    whether the command -> event -> listener round trip must pass (default true)
 *   findings                      exact sorted list of diagnostic codes expected (default [])
 *   finding_contains              substrings that some finding message must contain
 *   raised                        substrings an E_USER_WARNING must contain (WP_DEBUG cases)
 *   log_contains                  substrings some [tangible-ddd] error-log line must contain
 *   unmet                         exact unmet_minimums map (default {})
 *   registered_versions           exact set of registered versions, copies not judged
 *                                 (several vendored copies of one version: which one
 *                                 registers depends on the autoloader, not on N)
 *   single_origin                 every declared TangibleDDD\ class is from the winner
 *   jetpack                       a Jetpack Autoloader is registered (Jetpack fixtures)
 *   compiled                      compiled-container fixture labels whose every
 *                                 service must resolve (tests/Loader/fixtures/compiled)
 *
 * Prints PASS/FAIL with reasons, plus INFO lines for known, owned gaps.
 * Exit 0 on pass, 1 on fail.
 */

declare(strict_types=1);

[, $case, $file, $spec_json] = $argv + [null, null, null, '{}'];
$raw = (string) file_get_contents((string) $file);
$start = strpos($raw, '{');
$probe = $start === false ? null : json_decode(substr($raw, $start), true);
$spec = json_decode((string) $spec_json, true, 512, JSON_THROW_ON_ERROR);

$fail = [];
$info = [];
$want = static fn(string $k, mixed $default = null): mixed => array_key_exists($k, $spec) ? $spec[$k] : $default;

if (!is_array($probe)) {
    printf("FAIL %s: the probe printed no JSON (fatal during boot?)\n%s\n", $case, substr($raw, 0, 4000));
    exit(1);
}

// Winner and registrations.
$winner = $probe['winner'] ?? null;
if (($winner['version'] ?? null) !== $spec['winner_version'] || ($winner['copy'] ?? null) !== $spec['winner_copy']) {
    $fail[] = sprintf('winner %s from %s, expected %s from %s', $winner['version'] ?? 'none', $winner['copy'] ?? '-', $spec['winner_version'], $spec['winner_copy']);
}
if (($probe['initialized'] ?? false) !== true) {
    $fail[] = 'registry not initialized';
}
if (($registered = $want('registered')) !== null && $probe['registered'] != $registered) {
    $fail[] = 'registered ' . json_encode($probe['registered']) . ', expected ' . json_encode($registered);
}
if (($versions = $want('registered_versions')) !== null) {
    $got = array_map('strval', array_keys($probe['registered'] ?? []));
    sort($got);
    sort($versions);
    if ($got !== $versions) {
        $fail[] = 'registered versions ' . json_encode($got) . ', expected ' . json_encode($versions);
    }
}

// No fatal through plugins_loaded:30: the recorder saw priority 30 and the last priority.
$priorities = array_column(array_filter($probe['trace'] ?? [], static fn(array $t): bool => ($t['event'] ?? '') === 'plugins_loaded'), 'priority');
foreach ([0, 1, 20, 30, 'last'] as $p) {
    if (!in_array($p, $priorities, true)) {
        $fail[] = "plugins_loaded:{$p} never ran";
    }
}

// Exactly one initializer: a losing copy runs nothing but its loader entry.
foreach ($want('losers', []) as $loser) {
    $extra = $probe['copy_non_loader_files'][$loser] ?? [];
    if ($extra !== []) {
        $fail[] = sprintf('losing copy %s ran %d files of its own (first: %s)', $loser, count($extra), $extra[0]);
    }
}

// A copy that registered but must not have initialized: none of its
// procedural files, winner autoloader or diagnostics ran.
foreach ($want('not_initialized', []) as $copy) {
    $ran = array_filter(
        $probe['copy_non_loader_files'][$copy] ?? [],
        static fn(string $rel): bool => (bool) preg_match('#^(packages/ddd-wp/wordpress/[^/]+\.php|ddd-wordpress/[^/]+\.php|loader/(winner-autoloader|load-diagnostics)\.php)$#', $rel)
    );
    if ($ran !== []) {
        $fail[] = sprintf('copy %s initialized (ran %s)', $copy, implode(', ', $ran));
    }
}

// Class and function origin: everything of TangibleDDD\ from the winner.
$exceptions = $want('origin_exceptions', []);
$origin_note = $want('origin_info');
foreach ($probe['class_origin'] ?? [] as $class => $origin) {
    if ($class === 'Tangible_DDD_Versions' || in_array($class, $exceptions, true)) {
        continue; // the shared registry is first-copy-wins by design (B3)
    }
    if ($origin !== $spec['winner_copy']) {
        $line = "{$class} loaded from {$origin}, not the winner {$spec['winner_copy']}";
        if ($origin_note !== null) {
            $info[] = "INFO {$case}: {$line} ({$origin_note})";
        } else {
            $fail[] = $line;
        }
    }
}
// Every TangibleDDD\ class, interface, trait and enum declared at the end
// of the request comes from the winner (register 7.2 load.jetpack-mixed,
// report D F13): a census over get_declared_classes(), not a sample.
if ($want('single_origin', false)) {
    if (!isset($probe['ddd_class_copies']) || !is_array($probe['ddd_class_copies'])) {
        $fail[] = 'no TangibleDDD class census in the probe output';
    } else {
        foreach ($probe['ddd_class_copies'] as $copy => $count) {
            if ($copy === $spec['winner_copy']) {
                continue;
            }
            $samples = array_values(array_diff($probe['ddd_class_samples'][$copy] ?? [], $exceptions));
            if ($samples === [] && ($probe['ddd_class_samples'][$copy] ?? []) !== []) {
                continue; // only allowed exceptions
            }
            $fail[] = sprintf('%d TangibleDDD classes from %s, not the winner %s (%s)', $count, $copy, $spec['winner_copy'], implode(', ', array_slice($samples, 0, 5)));
        }
    }
}

// A Jetpack case only proves something while the Jetpack Autoloader is
// really registered (a fixture that fell back to plain Composer would pass
// vacuously).
if ($want('jetpack', false)) {
    $jetpack = array_filter($probe['autoloaders'] ?? [], static fn(string $a): bool => str_starts_with($a, 'Automattic\\Jetpack\\Autoloader\\'));
    if ($jetpack === []) {
        $fail[] = 'no Jetpack autoloader is registered (autoloaders: ' . json_encode($probe['autoloaders'] ?? null) . ')';
    }
}

// Compiled containers of the shipped 0.6.5 consumers (register 7.2
// load.compiled-containers): every service of each fixture resolves, to
// the class the container declares, with its ddd classes from the winner.
foreach ($want('compiled', []) as $label) {
    $c = $probe['compiled'][$label] ?? null;
    if (!is_array($c)) {
        $fail[] = "compiled container {$label} was not registered by its fixture plugin";
        continue;
    }
    foreach ($c['errors'] ?? [] as $id => $error) {
        $fail[] = "{$label}: {$id}: {$error}";
    }
    foreach ($c['mismatches'] ?? [] as $line) {
        $fail[] = "{$label}: {$line}";
    }
    $resolved = $c['resolved'] ?? [];
    if ((int) ($c['expected'] ?? 0) === 0 || count($resolved) !== (int) $c['expected']) {
        $fail[] = sprintf('%s: resolved %d of %d services', $label, count($resolved), (int) ($c['expected'] ?? 0));
    }
    foreach ($resolved as $id => $r) {
        if (str_starts_with((string) ($r['class'] ?? ''), 'TangibleDDD\\') && ($r['origin'] ?? null) !== $spec['winner_copy']) {
            $fail[] = sprintf('%s: %s resolved from %s, not the winner %s', $label, $r['class'], $r['origin'] ?? '?', $spec['winner_copy']);
        }
    }
}

foreach ($probe['function_origin'] ?? [] as $fn => $origin) {
    if ($fn === 'tangible_ddd_self_consume') {
        continue; // shared-named, first copy defines it (B4)
    }
    $expected_absent = $fn === 'TangibleDDD\\WordPress\\SelfConsumer\\di' && $want('self_consumer', 'built') === 'absent';
    if ($expected_absent ? $origin !== 'absent' : $origin !== $spec['winner_copy']) {
        $fail[] = "{$fn} defined by {$origin}, expected " . ($expected_absent ? 'absent' : $spec['winner_copy']);
    }
}

// Self-consumer through the shim, and wp ddd.
$self = (string) ($probe['self_consumer'] ?? 'absent');
if ($want('self_consumer', 'built') === 'built' ? !str_starts_with($self, 'built:') : $self !== 'absent') {
    $fail[] = "self-consumer {$self}";
}
if ($want('wp_ddd', true) !== null && ($probe['wp_ddd_command'] ?? false) !== $want('wp_ddd', true)) {
    $fail[] = '`wp ddd` registered: ' . json_encode($probe['wp_ddd_command'] ?? null);
}

// Round trip.
if ($want('round_trip', true)) {
    $rt = $probe['round_trip'] ?? [];
    if (($rt['error'] ?? null) !== null || ($rt['result'] ?? null) !== 'handled:alice' || ($rt['heard'] ?? null) !== ['alice']) {
        $fail[] = 'round trip ' . json_encode($rt);
    }
}

// Diagnostics.
$codes = array_values(array_unique(array_column($probe['diagnostics'] ?? [], 'code')));
sort($codes);
$expected_codes = $want('findings', []);
sort($expected_codes);
if ($codes !== $expected_codes) {
    $fail[] = 'diagnostics ' . json_encode($probe['diagnostics']) . ', expected codes ' . json_encode($expected_codes);
}
$messages = implode("\n", array_column($probe['diagnostics'] ?? [], 'message'));
foreach ($want('finding_contains', []) as $needle) {
    if (!str_contains($messages, $needle)) {
        $fail[] = "no diagnostic mentions {$needle}";
    }
}
$log = implode("\n", $probe['error_log'] ?? []);
foreach ($want('log_contains', []) as $needle) {
    if (!str_contains($log, $needle)) {
        $fail[] = "no [tangible-ddd] log line contains {$needle}";
    }
}

// PHP diagnostics: deprecations are tolerated (legacy code on PHP 8.2+);
// E_USER_WARNING only where a raise is expected; nothing else.
$raised = $want('raised', []);
foreach ($probe['php_diagnostics'] ?? [] as $e) {
    $level = (int) $e['level'];
    if (in_array($level, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
        continue;
    }
    if ($level === E_USER_WARNING && $raised !== []) {
        continue;
    }
    $fail[] = sprintf('PHP diagnostic level %d: %s (%s)', $level, $e['message'], $e['file']);
}
foreach ($raised as $needle) {
    $hit = array_filter($probe['php_diagnostics'] ?? [], static fn(array $e): bool => (int) $e['level'] === E_USER_WARNING && str_contains($e['message'], $needle));
    if ($hit === []) {
        $fail[] = "no E_USER_WARNING contains {$needle}";
    }
}
if ($raised === [] && array_key_exists('raised', $spec)) {
    foreach ($probe['php_diagnostics'] ?? [] as $e) {
        if ((int) $e['level'] === E_USER_WARNING) {
            $fail[] = 'unexpected E_USER_WARNING: ' . $e['message'];
        }
    }
}

// Consumer minimums.
if (($probe['unmet_minimums'] ?? []) != $want('unmet', [])) {
    $fail[] = 'unmet_minimums ' . json_encode($probe['unmet_minimums']) . ', expected ' . json_encode($want('unmet', []));
}

// Known gap, owned by wp (B8): the version the dashboard and the audit read
// is TANGIBLE_DDD_VERSION, first-copy-wins, not winner().
if (($probe['framework_version'] ?? null) !== ($winner['version'] ?? null)) {
    $info[] = sprintf('INFO %s: Infra\\Config::version() / dashboard reads %s, winner is %s (B8, wp-owned; see the packaging change requests)', $case, json_encode($probe['framework_version'] ?? null), $winner['version'] ?? 'none');
}

foreach ($info as $line) {
    echo $line, "\n";
}
if ($fail === []) {
    printf("PASS %s\n", $case);
    exit(0);
}
printf("FAIL %s:\n  - %s\n", $case, implode("\n  - ", $fail));
exit(1);
