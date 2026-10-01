<?php
/**
 * CR-PK-5 expiry gate (wave-2 packaging change requests; wave2-notes: "the
 * wave-4 gate fails on any that remain").
 *
 *   php tests/Compat/check-allowances.php [repo root]
 *
 * The three transitional allowances CR-PK-5 granted while the split-deferred
 * classes (Command, InfrastructureEvent, ProcessRunner) still lived in
 * packages/ddd-wp/src:
 *
 *   1. deptrac skip_violations         core files skipped for depending on them
 *   2. phpstan-core scanDirectories    core analysis allowed to see packages/ddd-wp/src
 *   3. core-clean-install PENDING/SKIP the clean install reporting, not failing,
 *                                      core classes whose parent was still in
 *                                      ddd-wp and a missing plain-php example,
 *                                      unless DDD_GATE=1
 *
 * Each is judged on the files that carried it. Exit 0 when all three have
 * expired, 1 otherwise (each remaining one named). Plain PHP, no Composer.
 */

declare(strict_types=1);

$root = rtrim($argv[1] ?? dirname(__DIR__, 2), '/');
$failures = 0;
$report = static function (bool $ok, string $name, string $detail) use (&$failures): void {
    if ($ok) {
        printf("ok   CR-PK-5 %s: expired\n", $name);
    } else {
        $failures++;
        printf("FAIL CR-PK-5 %s: %s\n", $name, $detail);
    }
};
$read = static function (string $rel) use ($root, &$failures): ?string {
    $path = $root . '/' . $rel;
    if (!is_file($path)) {
        $failures++;
        printf("FAIL %s is missing (cannot judge CR-PK-5)\n", $rel);

        return null;
    }

    return (string) file_get_contents($path);
};

// 1. deptrac skip_violations: any entry at all. Read without a YAML
// parser: the key sits under `deptrac:`; its dependers are the next-deeper
// mapping keys.
$deptrac = $read('deptrac.yaml');
if ($deptrac !== null) {
    $dependers = [];
    $in = null;
    foreach (preg_split('/\R/', $deptrac) ?: [] as $line) {
        if (preg_match('/^(\s*)skip_violations:\s*(\S.*)?$/', $line, $m)) {
            $in = strlen($m[1]);
            $inline = trim($m[2] ?? '');
            if ($inline !== '' && $inline !== '~' && $inline !== '{}' && $inline !== 'null' && !str_starts_with($inline, '#')) {
                $dependers[] = $inline;
            }
            continue;
        }
        if ($in === null || trim($line) === '' || preg_match('/^\s*#/', $line)) {
            continue;
        }
        preg_match('/^(\s*)/', $line, $ind);
        $depth = strlen($ind[1]);
        if ($depth <= $in) {
            $in = null;
            continue;
        }
        if (preg_match('/^\s*([^\s#:-][^:]*):\s*$/', $line, $k)) {
            $dependers[] = trim($k[1], " '\"");
        }
    }
    $report(
        $dependers === [],
        'deptrac skip_violations',
        sprintf('%d depender(s) still skipped (%s)', count($dependers), implode(', ', $dependers))
    );
}

// 2. phpstan-core.neon scanning ddd-wp sources.
$neon = $read('phpstan-core.neon');
if ($neon !== null) {
    // The list items under scanDirectories / scanFiles (deeper-indented
    // lines up to the next key at the same depth or shallower).
    $scans = false;
    $in = null;
    foreach (preg_split('/\R/', (string) preg_replace('/#.*$/m', '', $neon)) ?: [] as $line) {
        if (trim($line) === '') {
            continue;
        }
        preg_match('/^(\s*)/', $line, $ind);
        $depth = strlen($ind[1]);
        if (preg_match('/^\s*scan(Directories|Files):\s*(.*)$/', $line, $m)) {
            $in = $depth;
            $scans = $scans || str_contains($m[2], 'packages/ddd-wp');
            continue;
        }
        if ($in !== null && $depth > $in) {
            $scans = $scans || str_contains($line, 'packages/ddd-wp');
        } else {
            $in = null;
        }
    }
    $report(!$scans, 'phpstan-core scanDirectories', 'core analysis still scans packages/ddd-wp/src');
}

// 3. core-clean-install: the DDD_GATE knob and the PENDING / SKIP outcomes.
$sh = $read('tests/Compat/core-clean-install.sh');
$php = $read('tests/Compat/core-clean-install.php');
if ($sh !== null && $php !== null) {
    $hits = [];
    foreach (['core-clean-install.sh' => $sh, 'core-clean-install.php' => $php] as $file => $source) {
        $live = implode("\n", array_filter(
            preg_split('/\R/', $source) ?: [],
            static fn(string $l): bool => !preg_match('/^\s*(#|\/\/|\*|\/\*)/', $l)
        ));
        foreach (['DDD_GATE', 'PENDING', 'SKIP'] as $needle) {
            if (preg_match('/\b' . $needle . '\b/', $live)) {
                $hits[] = "{$file} still has {$needle}";
            }
        }
    }
    $report($hits === [], 'core-clean-install PENDING/SKIP', implode('; ', $hits));
}

printf("%s: %s\n", 'CR-PK-5 transitional allowances', $failures === 0 ? 'none remain' : "{$failures} problem(s)");
exit($failures === 0 ? 0 : 1);
