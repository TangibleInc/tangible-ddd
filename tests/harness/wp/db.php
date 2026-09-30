<?php
/**
 * Database administration for tests/harness/run.sh, run inside the runner
 * container with mysqli (no mysql client dependency, no WordPress).
 *
 *   php db.php server               wait for the server; print it; refuse anything but MySQL DDD_EXPECT_MYSQL
 *   php db.php create               DROP + CREATE WP_TESTS_DB_NAME, then assert it holds zero tables
 *   php db.php drop                 DROP WP_TESTS_DB_NAME
 *   php db.php assert-tables FILE   every table named in FILE (one per line, {prefix} expanded) exists
 *
 * Connection: WP_TESTS_DB_HOST (host[:port]), WP_TESTS_DB_USER, WP_TESTS_DB_PASSWORD.
 */

declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function fail(string $msg): never
{
    fwrite(STDERR, "db.php: {$msg}\n");
    exit(1);
}

function connect(int $wait_seconds): mysqli
{
    [$host, $port] = array_pad(explode(':', (string) getenv('WP_TESTS_DB_HOST'), 2), 2, '3306');
    $deadline = time() + $wait_seconds;
    while (true) {
        try {
            return new mysqli($host, (string) getenv('WP_TESTS_DB_USER'), (string) getenv('WP_TESTS_DB_PASSWORD'), '', (int) $port);
        } catch (mysqli_sql_exception $e) {
            if (time() >= $deadline) {
                fail("cannot connect to {$host}:{$port}: " . $e->getMessage());
            }
            usleep(500_000);
        }
    }
}

function db_name(): string
{
    $name = (string) getenv('WP_TESTS_DB_NAME');
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
        fail("refusing database name '{$name}'");
    }

    return $name;
}

function table_count(mysqli $db, string $schema): int
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?');
    $stmt->bind_param('s', $schema);
    $stmt->execute();

    return (int) $stmt->get_result()->fetch_row()[0];
}

$cmd = $argv[1] ?? '';

switch ($cmd) {
    case 'server':
        $db = connect((int) (getenv('DDD_DB_WAIT') ?: 120));
        $version = (string) $db->query('SELECT VERSION()')->fetch_row()[0];
        echo "server: MySQL {$version}\n";
        if (stripos($version, 'mariadb') !== false) {
            fail("{$version} is MariaDB, which is not a claimed target (register section 2)");
        }
        $expect = (string) (getenv('DDD_EXPECT_MYSQL') ?: '8.0');
        if (!str_starts_with($version, $expect . '.')) {
            fail("server is {$version}, expected MySQL {$expect}.x");
        }
        break;

    case 'create':
        $db = connect(10);
        $name = db_name();
        $db->query("DROP DATABASE IF EXISTS `{$name}`");
        $db->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $n = table_count($db, $name);
        if ($n !== 0) {
            fail("fresh database {$name} holds {$n} tables");
        }
        echo "database: {$name} created empty\n";
        break;

    case 'drop':
        $db = connect(10);
        $name = db_name();
        $db->query("DROP DATABASE IF EXISTS `{$name}`");
        echo "database: {$name} dropped\n";
        break;

    case 'assert-tables':
        $file = $argv[2] ?? fail('assert-tables needs a file');
        $prefix = (string) (getenv('DDD_TABLE_PREFIX') ?: 'wptests_');
        $db = connect(10);
        $name = db_name();
        $stmt = $db->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = ?');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $present = array_map(static fn(array $r): string => (string) $r[0], $stmt->get_result()->fetch_all());

        $missing = [];
        $expected = 0;
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim(preg_replace('/#.*/', '', $line));
            if ($line === '') {
                continue;
            }
            $expected++;
            $table = str_replace('{prefix}', $prefix, $line);
            if (!in_array($table, $present, true)) {
                $missing[] = $table;
            }
        }
        if ($expected === 0) {
            fail("{$file} names no tables");
        }
        if ($missing !== []) {
            fail('missing tables in ' . $name . ': ' . implode(', ', $missing));
        }
        echo "tables: all {$expected} expected tables present in {$name}\n";
        break;

    default:
        fail("unknown command '{$cmd}'");
}
