#!/usr/bin/env bash
# Hermetic test harness for tangible-ddd (register section 8, report F sections 5-6).
#
#   tests/harness/run.sh wp-integration   WP integration suite on MySQL 8.0 from an empty database
#   tests/harness/run.sh loader           loader fixtures of register 7.2, all kinds (lib/loader.sh)
#   tests/harness/run.sh core-pdo         Defaults/Pdo suite + two-process drain  (wave 3)
#   tests/harness/run.sh compat           the wave-4 compatibility gate: code style, CR-PK-5, artifact, 7.2, 7.3
#   tests/harness/run.sh conformance-wp   conformance scenarios on WordPress      (wave 2)
#
# Environment knobs are documented in tests/harness/lib/common.sh. Pinned
# inputs (WordPress, images, datastream SHA) are in tests/harness/refs.lock.
# Exit codes: 0 green, 1 failure, 64 usage.

set -euo pipefail

usage() {
  cat >&2 <<'EOF'
usage: tests/harness/run.sh <subcommand>
  wp-integration   WordPress integration suite on MySQL 8.0, fresh database
  loader           loader fixtures of register 7.2 on WordPress + MySQL 8.0 (every kind, no skips)
  core-pdo         ddd-core Defaults/Pdo: adapter suite, pdo conformance (both prepare modes, gated per id), two-process example
  compat           wave-4 compatibility gate: no code-style drift, CR-PK-5 allowances expired, release artifact, every 7.2 case, 7.3 rollback fixtures
  conformance-wp   conformance scenarios on WordPress + MySQL 8.0, fresh database (wp ids due by wave 3 gated)
EOF
  exit 64
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
# DDD_CONFORMANCE_WAVE (default 4) must have PASSED: not skipped, not absent.
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
  h_run "$plugin" php tests/Integration/Conformance/bin/check-due.php /out/conformance-wp.xml "${DDD_CONFORMANCE_WAVE:-4}" || gate_rc=$?
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
#      by DDD_CONFORMANCE_WAVE (default 4: 44 ids) must have PASSED in both modes;
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
  (cd "$H_EXPORT" && php packages/ddd-core/tests/Pdo/Conformance/bin/check-due.php "$junit" "${DDD_CONFORMANCE_WAVE:-4}") || gate_rc=$?

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

# compat: the wave-4 compatibility gate (register section 8 wave 4; 7.2,
# 7.3; CR-PK-5 in wave2-notes). Sections, all by default, in this order:
#   cs          php-cs-fixer check of the ref's new code against its
#               .php-cs-fixer.dist.php (the 0.6 style): any drift fails
#               (`composer cs` runs the same check on the working tree)
#   allowances  tests/Compat/check-allowances.php: no CR-PK-5 transitional
#               allowance remains (deptrac skips, phpstan-core scanning
#               ddd-wp, the clean install's PENDING/SKIP)
#   artifact    tests/Compat/release-artifact.sh: git archive of the ref
#               ships no tests/docs/tools/ddd-symfony/ddd-conformance
#   7.2         `run.sh loader` unnarrowed: every 7.2 case, 0 skipped
#   7.3         the rollback fixtures (wp, wave 4): the WordPress suite at
#               tests/Integration/Rollback/phpunit.xml of the ref (fixtures in
#               tests/Compat/rollback), on a fresh database, with installed
#               legacy winners; absent is a failure, not a skip
# DDD_COMPAT_SECTIONS picks a subset (for a partial local run; the gate runs
# all). DDD_LOADER_CASES is refused: compat never narrows 7.2. With
# DDD_DB_NAME set, the WordPress sections use <name>_l72 and <name>_r73.
COMPAT_SECTIONS="cs allowances artifact 7.2 7.3"

compat() {
  local sections="${DDD_COMPAT_SECTIONS:-$COMPAT_SECTIONS}" s
  if [ -n "${DDD_LOADER_CASES:-}" ]; then
    printf 'run.sh compat: DDD_LOADER_CASES narrows the 7.2 cases; compat runs all of them (unset it)\n' >&2
    exit 64
  fi
  for s in $sections; do
    case " $COMPAT_SECTIONS " in
      *" $s "*) ;;
      *) printf 'run.sh compat: unknown compat section %s (known: %s)\n' "$s" "$COMPAT_SECTIONS" >&2; exit 64 ;;
    esac
  done

  local root ref sha
  root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
  ref="${DDD_HARNESS_REF:-HEAD}"
  if [ "$ref" = WORKTREE ]; then
    printf '[compat] DDD_HARNESS_REF=WORKTREE: allowances judge the working tree, the artifact judges HEAD\n' >&2
    sha="$(git -C "$root" rev-parse HEAD)"
  else
    sha="$(git -C "$root" rev-parse --verify "$ref^{commit}")"
  fi

  local -a results=()
  local failed=0 rc tree
  for s in $sections; do
    rc=0
    case "$s" in
      cs)
        # The config's finder is relative to the config file, so checking an
        # export of the ref judges exactly the ref's files.
        [ -x "$root/vendor/bin/php-cs-fixer" ] || { echo "FAIL cs: vendor/bin/php-cs-fixer missing (composer install)"; rc=1; }
        if [ "$rc" -eq 0 ] && [ "$ref" = WORKTREE ]; then
          php "$root/vendor/bin/php-cs-fixer" check --config="$root/.php-cs-fixer.dist.php" \
            --using-cache=no --diff --show-progress=none || rc=$?
        elif [ "$rc" -eq 0 ]; then
          tree="$(mktemp -d "${TMPDIR:-/tmp}/ddd-compat.XXXXXX")"
          GIT_INDEX_FILE="$tree/.index" git -C "$root" read-tree "$sha"
          GIT_INDEX_FILE="$tree/.index" git -C "$root" checkout-index -a --prefix="$tree/src/"
          if [ -f "$tree/src/.php-cs-fixer.dist.php" ]; then
            php "$root/vendor/bin/php-cs-fixer" check --config="$tree/src/.php-cs-fixer.dist.php" \
              --using-cache=no --diff --show-progress=none || rc=$?
          else
            echo "FAIL cs: .php-cs-fixer.dist.php is absent from $sha"
            rc=1
          fi
          rm -rf "$tree"
        fi
        if [ "$rc" -eq 0 ]; then echo "code style: no drift from .php-cs-fixer.dist.php"; fi
        ;;
      allowances)
        if [ "$ref" = WORKTREE ]; then
          php "$root/tests/Compat/check-allowances.php" "$root" || rc=$?
        else
          tree="$(mktemp -d "${TMPDIR:-/tmp}/ddd-compat.XXXXXX")"
          GIT_INDEX_FILE="$tree/.index" git -C "$root" read-tree "$sha"
          GIT_INDEX_FILE="$tree/.index" git -C "$root" checkout-index -a --prefix="$tree/src/"
          php "$root/tests/Compat/check-allowances.php" "$tree/src" || rc=$?
          rm -rf "$tree"
        fi
        ;;
      artifact)
        (cd "$root" && bash tests/Compat/release-artifact.sh "$sha") || rc=$?
        ;;
      7.2)
        local log
        log="$(mktemp "${TMPDIR:-/tmp}/ddd-compat-72.XXXXXX")"
        DDD_DB_NAME="${DDD_DB_NAME:+${DDD_DB_NAME}_l72}" bash "${BASH_SOURCE[0]}" loader 2>&1 | tee "$log" || rc=$?
        if [ "$rc" -eq 0 ] && ! grep -q -E 'loader: [0-9]+ passed, 0 failed, 0 skipped' "$log"; then
          echo "FAIL 7.2: the loader run did not report every case passed with 0 skipped"
          rc=1
        fi
        rm -f "$log"
        ;;
      7.3)
        DDD_DB_NAME="${DDD_DB_NAME:+${DDD_DB_NAME}_r73}" bash "${BASH_SOURCE[0]}" _compat-rollback || rc=$?
        ;;
    esac
    if [ "$rc" -eq 0 ]; then results+=("$s ok"); else results+=("$s FAILED"); failed=1; fi
  done

  local summary
  summary="$(printf '%s, ' "${results[@]}")"
  printf 'compat: %s\n' "${summary%, }"
  [ "$failed" -eq 0 ] || exit 1
}

# 7.3 of compat: the wp-owned rollback fixtures (tests/Compat/rollback) inside
# the WP integration bootstrap, on a fresh database, like conformance-wp.
# The legacy winners are real installs: each ref of DDD_ROLLBACK_REFS
# (default "v0.6.6 v0.6.5 v0.6.2"; register 7.3 names L-0.6.6 and L-0.6.2 as
# rollback winners and 0.6.5 as the serializer) is exported from this clone,
# `composer install --no-dev`ed on the host, mounted read-only at /legacy and
# passed to the suite as DDD_ROLLBACK_LEGACY="<version>=<dir> ...". Each
# legacy run is a php child that loads only that copy (bin/legacy.php).
compat_rollback() {
  # shellcheck source=lib/common.sh
  . "$(dirname "${BASH_SOURCE[0]}")/lib/common.sh"
  h_init

  H_EXPORT="$H_WORK/tangible-ddd"
  h_export "$H_EXPORT"
  local suite=tests/Integration/Rollback/phpunit.xml
  if [ ! -f "$H_EXPORT/$suite" ]; then
    echo "FAIL 7.3: $suite is absent from the ref under test (the wp-owned rollback fixtures of register 7.3, wave 4)"
    exit 1
  fi
  h_datastream "$H_EXPORT/.reference/tangible-datastream"
  log "composer install in the export"
  composer install -d "$H_EXPORT" --no-scripts --no-interaction --no-progress --quiet

  local legacy_root="$H_WORK/legacy" legacy_env="" ref label version
  mkdir -p "$legacy_root"
  for ref in ${DDD_ROLLBACK_REFS:-v0.6.6 v0.6.5 v0.6.2}; do
    label="$(printf '%s' "$ref" | tr -c 'A-Za-z0-9_\n' '_')"
    DDD_HARNESS_REF="$ref" h_export "$legacy_root/$label"
    version="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$legacy_root/$label/tangible-ddd.php" | head -1)"
    [ -n "$version" ] || die "7.3: no Version: header in $ref"
    log "composer install --no-dev in legacy $ref ($version)"
    composer install -d "$legacy_root/$label" --no-dev --no-scripts --no-interaction --no-progress --quiet
    legacy_env="${legacy_env:+$legacy_env }$version=/legacy/$label"
  done

  h_mysql_up
  h_db_create
  h_wordpress

  local plugin=/var/www/html/wp-content/plugins/tangible-ddd
  h_run "$plugin" php -d memory_limit=1G /harness/wp/install-tables.php
  H_EXTRA_MOUNTS=(-v "$legacy_root:/legacy:ro" -e "DDD_ROLLBACK_LEGACY=$legacy_env")
  log "phpunit -c $suite (legacy winners: $legacy_env)"
  h_run "$plugin" php -d memory_limit=1G vendor/bin/phpunit -c "$suite" \
    --cache-directory /tmp/phpunit-cache --do-not-cache-result --fail-on-skipped --fail-on-incomplete
  log "7.3 rollback fixtures green on $DB_NAME"
}

case "${1:-}" in
  wp-integration) wp_integration ;;
  loader) loader ;;
  conformance-wp) conformance_wp ;;
  core-pdo) core_pdo ;;
  compat) compat ;;
  _compat-rollback) compat_rollback ;;
  *) usage ;;
esac
