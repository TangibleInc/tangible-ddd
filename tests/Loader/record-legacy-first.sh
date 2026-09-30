#!/usr/bin/env bash
# Record the loader baseline for register 7.2 case `load.legacy-first` against
# today's code: each legacy copy (L) is bundled by one fixture plugin, today's
# copy (the ref under test, default HEAD) by another, and WordPress loads the
# legacy plugin first. The outcome is written to
# tests/Loader/baselines/load.legacy-first.json.
#
#   tests/Loader/record-legacy-first.sh           record (overwrite the baseline)
#   tests/Loader/record-legacy-first.sh --check   re-run and diff against the baseline
#
# Environment: everything in tests/harness/lib/common.sh, plus
#   DDD_LOADER_LEGACY   legacy tags to pair with today's copy (default "v0.6.2 v0.6.5":
#                       the oldest in-window copy and the copy the shipped compiled
#                       containers bundle, register 7.1)

set -euo pipefail

MODE="${1:-record}"
case "$MODE" in record|--check) ;; *) echo "usage: $0 [--check]" >&2; exit 64 ;; esac

# shellcheck source=../harness/lib/common.sh
. "$(dirname "${BASH_SOURCE[0]}")/../harness/lib/common.sh"
LOADER_DIR="$REPO_ROOT/tests/Loader"
BASELINE="$LOADER_DIR/baselines/load.legacy-first.json"
LEGACY_TAGS="${DDD_LOADER_LEGACY:-v0.6.2 v0.6.5}"
CURRENT_REF="${DDD_HARNESS_REF:-HEAD}"

h_init

header_version() { sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$1/tangible-ddd.php" | head -1; }

# Build fixture plugin fx-<label> bundling the copy exported from <ref>.
build_fixture() {
  local label="$1" ref="$2" copy="$H_WORK/copies/$1" plugin="$H_WORK/plugins/fx-$1" version
  DDD_HARNESS_REF="$ref" h_export "$copy"
  version="$(header_version "$copy")"
  [ -n "$version" ] || die "no Version: header in $ref"
  mkdir -p "$plugin"
  cat > "$plugin/composer.json" <<JSON
{
  "name": "fx/$label",
  "require": {
    "tangible/ddd": "$version",
    "league/tactician": "^2.0-rc1",
    "symfony/yaml": "^7.4"
  },
  "repositories": [
    {"type": "path", "url": "$copy", "options": {"symlink": false, "versions": {"tangible/ddd": "$version"}}}
  ],
  "config": {"platform": {"php": "8.2.0"}}
}
JSON
  composer install -d "$plugin" --no-dev --no-interaction --no-progress --quiet
  sed -e "s/__LABEL__/$label/g" -e "s/__VERSION__/$version/g" \
    "$LOADER_DIR/fixtures/fx-plugin.php.tpl" > "$plugin/fx-$label.php"
  printf '%s' "$version"
}

current_version="$(build_fixture current "$CURRENT_REF")"
current_sha="$(git -C "$REPO_ROOT" rev-parse --verify "$CURRENT_REF^{commit}")"
log "today's copy: $current_version ($current_sha)"

legacy_labels=()
for tag in $LEGACY_TAGS; do
  label="legacy-${tag#v}"
  label="${label//./_}"
  v="$(build_fixture "$label" "$tag")"
  log "legacy copy $tag: $v"
  legacy_labels+=("$label")
done

h_mysql_up
h_db_create

# Private WordPress tree for this run: the fixture plugins and the recorder
# live inside it, so the shared cache stays untouched.
h_wp_download
cp -R "$WP_TESTS_ABSPATH_HOST" "$H_WORK/wordpress"
export WP_TESTS_ABSPATH_HOST="$H_WORK/wordpress"
h_wordpress
mkdir -p "$WP_TESTS_ABSPATH_HOST/wp-content/mu-plugins"
cp "$LOADER_DIR/fixtures/mu-recorder.php" "$WP_TESTS_ABSPATH_HOST/wp-content/mu-plugins/fx-recorder.php"
cp -R "$H_WORK/plugins/." "$WP_TESTS_ABSPATH_HOST/wp-content/plugins/"
H_EXTRA_MOUNTS=(-v "$LOADER_DIR/fixtures:/loader:ro")

mkdir -p "$H_WORK/cases"
for label in "${legacy_labels[@]}"; do
  order="[\"fx-$label/fx-$label.php\",\"fx-current/fx-current.php\"]"
  h_run /var/www/html wp option update active_plugins "$order" --format=json --skip-plugins --quiet
  h_run /var/www/html php -d memory_limit=1G -d display_errors=stderr /usr/local/bin/wp eval-file /loader/probe.php \
    > "$H_WORK/cases/$label.json"
  log "recorded load.legacy-first with $label then current"
done

# Assemble: provenance is informative; --check compares "cases" only.
php -r '
  [, $dir, $current, $sha] = $argv;
  $cases = [];
  foreach (glob("$dir/*.json") as $f) {
    $raw = file_get_contents($f);
    $json = json_decode(substr($raw, strpos($raw, "{")), true, 512, JSON_THROW_ON_ERROR);
    $cases["L-" . str_replace("_", ".", substr(basename($f, ".json"), 7)) . " then current"] = $json;
  }
  ksort($cases);
  echo json_encode([
    "case" => "load.legacy-first",
    "register" => "section 7.2",
    "recorded_against" => ["current_version" => $current, "current_sha" => $sha, "note" => "baseline of today\x27s loader, not the pass condition"],
    "cases" => $cases,
  ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
' "$H_WORK/cases" "$current_version" "$current_sha" > "$H_WORK/load.legacy-first.json"

if [ "$MODE" = --check ]; then
  php -r '
    $a = json_decode(file_get_contents($argv[1]), true)["cases"];
    $b = json_decode(file_get_contents($argv[2]), true)["cases"];
    if ($a !== $b) { fwrite(STDERR, "load.legacy-first differs from the recorded baseline\n"); exit(1); }
    echo "load.legacy-first matches the recorded baseline\n";
  ' "$BASELINE" "$H_WORK/load.legacy-first.json" || { diff -u "$BASELINE" "$H_WORK/load.legacy-first.json" >&2 || true; exit 1; }
else
  mkdir -p "$(dirname "$BASELINE")"
  cp "$H_WORK/load.legacy-first.json" "$BASELINE"
  log "wrote ${BASELINE#"$REPO_ROOT"/}"
fi
