#!/usr/bin/env bash
# The tangible-ddd library gate: everything a change must pass before it lands.
#
#   tools/gate.sh quick   no databases: cs, phpstan (both configs), deptrac, root unit
#                         suite, ddd-core unit suite, conformance on the in-memory host,
#                         the plain-PHP example. About 2 minutes.
#   tools/gate.sh full    quick + every database-backed suite: ddd-symfony on Postgres,
#                         and the harnesses wp-integration, conformance-wp, core-pdo
#                         (PDO adapters + PDO conformance in both prepare modes + the
#                         two-process example) and compat (loader 7.2 + rollback 7.3).
#                         About 15-20 minutes (11.5 on an M-series Mac with warm caches).
#
# Also as `composer gate` / `composer gate:quick`.
#
# Requirements for `full` (see "Verifying a change" in README.md):
#   - Docker (the harnesses run WordPress in a pinned PHP 8.2 image), git, composer.
#   - MySQL 8.0 reachable at DDD_MYSQL_HOST:DDD_MYSQL_PORT (default 127.0.0.1:33306,
#     root / DDD_MYSQL_PASSWORD=ddd). Every run creates and drops its own databases.
#   - Postgres 16 reachable through DDD_SF_PG_URL (default
#     pgsql://postgres:ddd@127.0.0.1:55432/<unique per run>).
#   - Read access to TangibleInc/tangible-datastream (pinned in tests/harness/refs.lock)
#     unless .reference/tangible-datastream already has that commit.
#
# The harnesses test the COMMITTED tree (git HEAD). Commit first, or set
# DDD_HARNESS_REF=WORKTREE to test uncommitted changes.
#
# Every step runs even if an earlier one fails; the summary lists each step and the
# exit code is 1 if any failed. Logs go to $DDD_GATE_LOGS (default a temp dir).

set -uo pipefail

MODE="${1:-quick}"
case "$MODE" in quick|full) ;; *) echo "usage: tools/gate.sh [quick|full]" >&2; exit 64 ;; esac

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

RUN_ID="gate_$$"
LOGS="${DDD_GATE_LOGS:-$(mktemp -d "${TMPDIR:-/tmp}/ddd-gate.XXXXXX")}"
mkdir -p "$LOGS"

export DDD_MYSQL_HOST="${DDD_MYSQL_HOST:-127.0.0.1}"
export DDD_MYSQL_PORT="${DDD_MYSQL_PORT:-33306}"
export DDD_MYSQL_USER="${DDD_MYSQL_USER:-root}"
export DDD_MYSQL_PASSWORD="${DDD_MYSQL_PASSWORD:-ddd}"
export DDD_SF_PG_URL="${DDD_SF_PG_URL:-pgsql://postgres:ddd@127.0.0.1:55432/${RUN_ID}_sf}"

declare -a NAMES=() RESULTS=()

step() {
  local name="$1"; shift
  local log="$LOGS/${name}.log"
  printf '%-22s ' "$name"
  local start=$SECONDS
  if ( "$@" ) >"$log" 2>&1; then
    RESULTS+=("PASS"); printf 'PASS  (%ss)\n' $((SECONDS - start))
  else
    RESULTS+=("FAIL"); printf 'FAIL  (%ss)  log: %s\n' $((SECONDS - start)) "$log"
    tail -n 15 "$log" | sed 's/^/    /'
  fi
  NAMES+=("$name")
}

# A stale defects-first result cache reorders the root suite and can surface
# order-dependent failures that are not real; start clean every time.
root_suite()  { rm -rf .phpunit.cache && vendor/bin/phpunit; }
core_suite()  { vendor/bin/phpunit -c packages/ddd-core/phpunit.xml; }
# ddd-conformance and ddd-symfony vendor a COPY of ddd-core (path repos): refresh it,
# or they test the previous core.
conformance() { (cd packages/ddd-conformance && rm -rf vendor/tangible && composer install -q --no-interaction && vendor/bin/phpunit --group mem); }
# ddd-symfony: a compiled test container in var/cache keeps the database it was built
# with; clear it, or a run against a different DDD_SF_PG_URL gives false failures.
symfony()     { (cd packages/ddd-symfony && rm -rf vendor/tangible var/cache && composer install -q --no-interaction && vendor/bin/phpunit); }
# Database names must be [A-Za-z0-9_]: wp-integration -> gate_<pid>_wp_integration.
harness()     { DDD_DB_NAME="${RUN_ID}_${1//-/_}" bash tests/harness/run.sh "$1"; }

composer install -q --no-interaction >"$LOGS/composer-install.log" 2>&1 || { echo "composer install failed: $LOGS/composer-install.log"; exit 1; }

echo "tangible-ddd gate ($MODE) at $(git rev-parse --short HEAD) — logs in $LOGS"
step cs             composer cs
step phpstan        vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=1G --no-progress
step phpstan-core   vendor/bin/phpstan analyse -c phpstan-core.neon --memory-limit=1G --no-progress
step deptrac        vendor/bin/deptrac analyse --no-progress
step root-unit      root_suite
step core-unit      core_suite
step conformance    conformance
step example-plain  php examples/plain-php/run.php

if [ "$MODE" = full ]; then
  step symfony        symfony
  step wp-integration harness wp-integration
  step conformance-wp harness conformance-wp
  step core-pdo       harness core-pdo
  step compat         harness compat
fi

failed=0
for r in "${RESULTS[@]}"; do [ "$r" = FAIL ] && failed=$((failed + 1)); done
echo
if [ "$failed" -eq 0 ]; then
  echo "gate $MODE: all ${#NAMES[@]} steps green"
else
  echo "gate $MODE: $failed of ${#NAMES[@]} steps failed (logs in $LOGS)"
  exit 1
fi
