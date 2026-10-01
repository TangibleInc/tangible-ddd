#!/usr/bin/env bash
# Hermetic test harness for tangible-ddd (register section 8, report F sections 5-6).
#
#   tests/harness/run.sh wp-integration   WP integration suite on MySQL 8.0 from an empty database
#   tests/harness/run.sh loader           loader fixtures of register 7.2        (wave 2; lib/loader.sh)
#   tests/harness/run.sh core-pdo         Defaults/Pdo suite + two-process drain  (wave 3)
#   tests/harness/run.sh compat           compatibility fixtures 7.2 + 7.3        (wave 4)
#   tests/harness/run.sh conformance-wp   conformance scenarios on WordPress      (wave 2)
#
# Environment knobs are documented in tests/harness/lib/common.sh. Pinned
# inputs (WordPress, images, datastream SHA) are in tests/harness/refs.lock.
# Exit codes: 0 green, 1 failure, 2 not yet implemented, 64 usage.

set -euo pipefail

usage() {
  cat >&2 <<'EOF'
usage: tests/harness/run.sh <subcommand>
  wp-integration   WordPress integration suite on MySQL 8.0, fresh database
  loader           loader fixtures of register 7.2 on WordPress + MySQL 8.0 (all but jetpack-mixed)
  core-pdo         ddd-core Defaults/Pdo: adapter suite, pdo conformance (both prepare modes, gated per id), two-process example
  compat           compatibility fixtures (not yet implemented)
  conformance-wp   conformance scenarios on WordPress + MySQL 8.0, fresh database (wave-2 wp ids gated)
EOF
  exit 64
}

not_yet() {
  printf 'run.sh: %s: not yet implemented\n' "$1" >&2
  exit 2
}

wp_integration() {
  # shellcheck source=lib/common.sh
  . "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"
  h_init

  H_EXPORT="$H_WORK/tangible-ddd"
  h_export "$H_EXPORT"
  h_datastream "$H_EXPORT/.reference/tangible-datastream"
  log "composer install in the export"
  composer install -d "$H_EXPORT" --no-scripts --no-interaction --no-progress --quiet

  h_mysql_up
  h_db_create
  h_wordpress

  local plugin=/var/www/html/wp-content/plugins/tangible-ddd
  h_run "$plugin" php -d memory_limit=1G /harness/wp/install-tables.php
  h_run /tmp php /harness/wp/db.php assert-tables /harness/wp/expected-tables.txt

  log "phpunit -c phpunit.integration.xml"
  h_run "$plugin" php -d memory_limit=1G vendor/bin/phpunit -c phpunit.integration.xml \
    --cache-directory /tmp/phpunit-cache --do-not-cache-result
  log "wp-integration green on $DB_NAME"
}

loader() {
  # shellcheck source=lib/common.sh
  . "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"
  # shellcheck source=lib/loader.sh
  . "$(dirname "${BASH_SOURCE[0]}")/lib/loader.sh"
  h_init
  loader_main
}

# conformance-wp: the shared ddd-conformance scenarios on the wp host
# (tests/Integration/Conformance, WpHostFixture) inside the WP integration
# bootstrap, on a fresh database. Then every scenario id due on wp by
# DDD_CONFORMANCE_WAVE (default 3) must have PASSED: not skipped, not absent.
# The multi-process scenarios start fresh `php` children in the same
# container (tests/Integration/Conformance/bin/fresh.php).
conformance_wp() {
  # shellcheck source=lib/common.sh
  . "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"
  h_init

  H_EXPORT="$H_WORK/tangible-ddd"
  h_export "$H_EXPORT"
  h_datastream "$H_EXPORT/.reference/tangible-datastream"
  log "composer install in the export"
  composer install -d "$H_EXPORT" --no-scripts --no-interaction --no-progress --quiet

  h_mysql_up
  h_db_create
  h_wordpress

  local plugin=/var/www/html/wp-content/plugins/tangible-ddd
  h_run "$plugin" php -d memory_limit=1G /harness/wp/install-tables.php

  mkdir -p "$H_WORK/out"
  H_EXTRA_MOUNTS=(-v "$H_WORK/out:/out")
  # The gate runs even when phpunit is red, so the per-id verdicts are
  # always printed; the subcommand fails if either step failed.
  local phpunit_rc=0 gate_rc=0
  log "phpunit -c tests/Integration/Conformance/phpunit.xml"
  h_run "$plugin" php -d memory_limit=1G vendor/bin/phpunit -c tests/Integration/Conformance/phpunit.xml \
    --cache-directory /tmp/phpunit-cache --do-not-cache-result --log-junit /out/conformance-wp.xml || phpunit_rc=$?
  h_run "$plugin" php tests/Integration/Conformance/bin/check-due.php /out/conformance-wp.xml "${DDD_CONFORMANCE_WAVE:-3}" || gate_rc=$?
  if [ "$phpunit_rc" -ne 0 ] || [ "$gate_rc" -ne 0 ]; then
    log "conformance-wp red on $DB_NAME (phpunit exit $phpunit_rc, check-due exit $gate_rc)"
    exit 1
  fi
  log "conformance-wp green on $DB_NAME"
}

# core-pdo: ddd-core's Defaults/Pdo on MySQL 8.0, run by the HOST php
# (pdo_mysql and posix required; the WordPress runner image has no
# pdo_mysql), on an export of the ref under test:
#   1. the adapter suite (phpunit.pdo.xml, both prepare modes);
#   2. the shared conformance scenarios on the pdo host, both prepare modes,
#      a fresh database per test, then the per-id gate: every id due on pdo
#      by DDD_CONFORMANCE_WAVE (default 3) must have PASSED in both modes;
#   3. the two-process example: produce.php, then two drain.php runs with
#      the clock past the 60 s timeout (DDD_CLOCK_OFFSET=120); the scripts
#      assert the process completed and the stale timeout was a no-op.
# MySQL: DDD_MYSQL_HOST / DDD_MYSQL_PORT / DDD_MYSQL_USER / DDD_MYSQL_PASSWORD
# (an existing server, reached directly), else the pinned MYSQL_IMAGE on a
# loopback port. Every database the run creates is unique to it and dropped.
core_pdo() {
  # shellcheck source=lib/common.sh
  . "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"
  h_init
  need php
  php -r 'exit(extension_loaded("pdo_mysql") && extension_loaded("posix") ? 0 : 1);' \
    || die "core-pdo runs on the host php, which needs pdo_mysql and posix"

  H_EXPORT="$H_WORK/tangible-ddd"
  h_export "$H_EXPORT"
  log "composer install in the export"
  composer install -d "$H_EXPORT" --no-scripts --no-interaction --no-progress --quiet

  local host port user password
  if [ -n "${DDD_MYSQL_HOST:-}" ]; then
    host="$DDD_MYSQL_HOST" port="${DDD_MYSQL_PORT:-3306}"
    user="${DDD_MYSQL_USER:-root}" password="${DDD_MYSQL_PASSWORD:-ddd}"
    log "using existing MySQL at $host:$port"
  else
    H_MYSQL_CONTAINER="ddd-harness-mysql-$RUN_ID"
    user=root password="harness-$RUN_ID" host=127.0.0.1
    log "starting $MYSQL_IMAGE as $H_MYSQL_CONTAINER"
    docker run -d --name "$H_MYSQL_CONTAINER" -p 127.0.0.1::3306 \
      -e MYSQL_ROOT_PASSWORD="$password" "$MYSQL_IMAGE" >/dev/null
    port="$(docker port "$H_MYSQL_CONTAINER" 3306/tcp | head -n 1 | sed 's/.*://')"
  fi
  export DDD_PDO_HOST="$host" DDD_PDO_PORT="$port" DDD_PDO_USER="$user" DDD_PDO_PASSWORD="$password"
  export DDD_PDO_DATABASE="ddd_harness_pdo_${RUN_ID}"
  local example_db="ddd_harness_example_${RUN_ID}"

  # Wait for the server, and pin the version under test.
  local version="" tries=0
  until version="$(php -r '$p = new PDO("mysql:host=" . getenv("DDD_PDO_HOST") . ";port=" . getenv("DDD_PDO_PORT"), getenv("DDD_PDO_USER"), getenv("DDD_PDO_PASSWORD")); echo $p->query("SELECT VERSION()")->fetchColumn();' 2>/dev/null)"; do
    tries=$((tries + 1))
    [ "$tries" -lt 90 ] || die "MySQL at $host:$port did not answer"
    sleep 2
  done
  case "$version" in
    "${DDD_EXPECT_MYSQL:-8.0}"*) log "MySQL $version" ;;
    *) die "expected MySQL ${DDD_EXPECT_MYSQL:-8.0}.x, the server is $version" ;;
  esac

  local adapters_rc=0 conformance_rc=0 gate_rc=0 example_rc=0
  local junit="$H_WORK/core-pdo-conformance.xml"
  log "phpunit -c packages/ddd-core/phpunit.pdo.xml --testsuite pdo"
  (cd "$H_EXPORT" && php vendor/bin/phpunit -c packages/ddd-core/phpunit.pdo.xml --testsuite pdo --do-not-cache-result) || adapters_rc=$?

  log "phpunit -c packages/ddd-core/tests/Pdo/Conformance/phpunit.xml"
  (cd "$H_EXPORT" && php vendor/bin/phpunit -c packages/ddd-core/tests/Pdo/Conformance/phpunit.xml --do-not-cache-result --log-junit "$junit") || conformance_rc=$?
  (cd "$H_EXPORT" && php packages/ddd-core/tests/Pdo/Conformance/bin/check-due.php "$junit" "${DDD_CONFORMANCE_WAVE:-3}") || gate_rc=$?

  log "two-process example: produce.php, then two drain.php runs with DDD_CLOCK_OFFSET=120"
  (
    cd "$H_EXPORT"
    export DDD_EXAMPLE_DB_HOST="$host" DDD_EXAMPLE_DB_PORT="$port" DDD_EXAMPLE_DB_USER="$user" \
      DDD_EXAMPLE_DB_PASSWORD="$password" DDD_EXAMPLE_DB_NAME="$example_db" DDD_EXAMPLE_DRIVER=pdo
    unset DDD_CLOCK_OFFSET
    php examples/plain-php-durable/produce.php --reset \
      && DDD_CLOCK_OFFSET=120 php examples/plain-php-durable/drain.php \
      && DDD_CLOCK_OFFSET=120 php examples/plain-php-durable/drain.php
  ) || example_rc=$?

  if [ "${DDD_KEEP_DB:-0}" != 1 ]; then
    DDD_DROP="$DDD_PDO_DATABASE $example_db" php -r '
      $p = new PDO("mysql:host=" . getenv("DDD_PDO_HOST") . ";port=" . getenv("DDD_PDO_PORT"), getenv("DDD_PDO_USER"), getenv("DDD_PDO_PASSWORD"));
      foreach (explode(" ", getenv("DDD_DROP")) as $db) { $p->exec("DROP DATABASE IF EXISTS `$db`"); }' || true
  fi

  if [ "$adapters_rc" -ne 0 ] || [ "$conformance_rc" -ne 0 ] || [ "$gate_rc" -ne 0 ] || [ "$example_rc" -ne 0 ]; then
    log "core-pdo red on MySQL $version (adapters $adapters_rc, conformance $conformance_rc, check-due $gate_rc, example $example_rc)"
    exit 1
  fi
  log "core-pdo green on MySQL $version"
}

case "${1:-}" in
  wp-integration) wp_integration ;;
  loader) loader ;;
  conformance-wp) conformance_wp ;;
  core-pdo) core_pdo ;;
  compat) not_yet "$1" ;;
  *) usage ;;
esac
