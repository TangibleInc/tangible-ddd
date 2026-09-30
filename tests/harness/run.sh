#!/usr/bin/env bash
# Hermetic test harness for tangible-ddd (register section 8, report F sections 5-6).
#
#   tests/harness/run.sh wp-integration   WP integration suite on MySQL 8.0 from an empty database
#   tests/harness/run.sh loader           loader fixtures of register 7.2        (wave 2)
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
  loader           loader fixtures (not yet implemented)
  core-pdo         ddd-core Defaults/Pdo suite (not yet implemented)
  compat           compatibility fixtures (not yet implemented)
  conformance-wp   conformance scenarios on WordPress (not yet implemented)
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

case "${1:-}" in
  wp-integration) wp_integration ;;
  loader|core-pdo|compat|conformance-wp) not_yet "$1" ;;
  *) usage ;;
esac
