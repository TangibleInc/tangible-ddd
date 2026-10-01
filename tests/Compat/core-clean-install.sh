#!/usr/bin/env bash
# Clean install of tangible/ddd-core on its own (register 1.5 and section 8,
# wave-2 acceptance; report F section 4).
#
# A fresh Composer project requires only tangible/ddd-core (plus the
# league/tactician RC flag every root must restate, O20) from a copying path
# repository over an export of packages/ddd-core, installs --no-dev, and
# proves:
#   1. the installed dependency closure is exactly
#      {tangible/ddd-core, league/tactician, psr/container, psr/log};
#   2. after `require vendor/autoload.php` no WordPress function, class,
#      constant or global exists, the only function defined is the core
#      assert helper, and Composer's is the only autoloader; every ddd-core
#      class then declares without WordPress (the DI bridge is skipped, its
#      symfony/dependency-injection is only suggested, X4);
#   3. examples/plain-php/run.php exits 0 against that install.
#
# Two things wait on the core author's wave-2 round-2 work and are reported,
# not failed, unless DDD_GATE=1 (the wave-2 gate run): a missing
# examples/plain-php/run.php (SKIP), and core classes whose parent is a
# split-deferred class still under packages/ddd-wp/src (PENDING).
#
# The example runs from a directory holding a copy of examples/plain-php/
# next to the clean vendor/, with DDD_AUTOLOAD pointing at
# vendor/autoload.php. If the example ships a composer.json, its own
# manifest is installed instead, with tangible/ddd-core redirected to the
# export.
#
# Environment:
#   DDD_HARNESS_REF   git ref to export (default WORKTREE: tracked + untracked,
#                     unignored files of the working tree; any other value is a
#                     commit-ish exported through a temporary index)
#   DDD_KEEP_WORK=1   keep the scratch directory
#   DDD_GATE=1        fail on SKIP and PENDING too
#
# Needs php and composer on the host; no Docker, no database.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
REF="${DDD_HARNESS_REF:-WORKTREE}"
VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$REPO_ROOT/tangible-ddd.php" | head -1)"
EXPECTED="league/tactician psr/container psr/log tangible/ddd-core"

log() { printf '[core-clean-install] %s\n' "$*" >&2; }
die() { printf '[core-clean-install] FAIL: %s\n' "$*" >&2; exit 1; }

command -v php >/dev/null || die "php is required"
command -v composer >/dev/null || die "composer is required"
[ -n "$VERSION" ] || die "no Version: header in tangible-ddd.php"

WORK="$(mktemp -d "${TMPDIR:-/tmp}/ddd-core-clean.XXXXXX")"
cleanup() {
  if [ "${DDD_KEEP_WORK:-0}" = 1 ]; then log "kept $WORK"; else rm -rf "$WORK"; fi
}
trap cleanup EXIT

# Export (never the live tree: a stray vendor/ under packages/ddd-core would
# be copied by the path repository and hide a missing dependency).
EXPORT="$WORK/export"
mkdir -p "$EXPORT"
if [ "$REF" = WORKTREE ]; then
  log "exporting the working tree (tracked + untracked, unignored)"
  (cd "$REPO_ROOT" && git ls-files -z -co --exclude-standard -- packages/ddd-core packages/ddd-wp/src examples/plain-php |
    while IFS= read -r -d '' f; do
      [ -e "$f" ] || continue
      mkdir -p "$EXPORT/$(dirname "$f")"; cp -p "$f" "$EXPORT/$f"
    done)
else
  sha="$(git -C "$REPO_ROOT" rev-parse --verify "$REF^{commit}")"
  log "exporting $REF ($sha)"
  GIT_INDEX_FILE="$WORK/export.index" git -C "$REPO_ROOT" read-tree "$sha"
  GIT_INDEX_FILE="$WORK/export.index" git -C "$REPO_ROOT" checkout-index -a --prefix="$EXPORT/"
fi
[ -f "$EXPORT/packages/ddd-core/composer.json" ] || die "packages/ddd-core/composer.json missing from the export"

PROJECT="$WORK/project"
mkdir -p "$PROJECT"
cat > "$PROJECT/composer.json" <<JSON
{
  "name": "fx/core-clean-install",
  "description": "A plain-PHP host that requires only tangible/ddd-core.",
  "require": {
    "tangible/ddd-core": "$VERSION",
    "league/tactician": "^2.0-rc1"
  },
  "repositories": [
    {"type": "path", "url": "$EXPORT/packages/ddd-core", "options": {"symlink": false, "versions": {"tangible/ddd-core": "$VERSION"}}}
  ],
  "minimum-stability": "stable",
  "config": {"platform": {"php": "8.2.0"}}
}
JSON

log "composer install --no-dev (tangible/ddd-core $VERSION)"
composer install -d "$PROJECT" --no-dev --no-interaction --no-progress --quiet

# 1. Dependency closure.
closure="$(php -r '
  $installed = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
  $names = array_map(static fn(array $p): string => $p["name"], $installed["packages"] ?? $installed);
  sort($names);
  echo implode(" ", $names);
' "$PROJECT/vendor/composer/installed.json")"
if [ "$closure" = "$EXPECTED" ]; then
  echo "ok   dependency closure is exactly {${EXPECTED// /, }}"
else
  echo "FAIL dependency closure -- expected {${EXPECTED// /, }}, got {${closure// /, }}"
  exit 1
fi

# 2. No WordPress after autoload; every core class declares.
DDD_WP_SRC="$EXPORT/packages/ddd-wp/src" DDD_GATE="${DDD_GATE:-0}" \
  php "$REPO_ROOT/tests/Compat/core-clean-install.php" "$PROJECT"

# 3. The plain-PHP example.
EXAMPLE="$EXPORT/examples/plain-php"
if [ ! -f "$EXAMPLE/run.php" ]; then
  if [ "${DDD_GATE:-0}" = 1 ]; then
    echo "FAIL examples/plain-php/run.php is absent (DDD_GATE=1)"
    exit 1
  fi
  echo "SKIP examples/plain-php/run.php is not in the export yet (core lands it in wave 2); re-check at the gate with DDD_GATE=1"
  exit 0
fi

RUN_DIR="$WORK/example"
mkdir -p "$RUN_DIR"
cp -R "$EXAMPLE/." "$RUN_DIR/"
if [ -f "$RUN_DIR/composer.json" ]; then
  log "the example ships a manifest; installing it with tangible/ddd-core from the export"
  composer config -d "$RUN_DIR" repositories.ddd-core \
    "{\"type\": \"path\", \"url\": \"$EXPORT/packages/ddd-core\", \"options\": {\"symlink\": false, \"versions\": {\"tangible/ddd-core\": \"$VERSION\"}}}"
  composer install -d "$RUN_DIR" --no-dev --no-interaction --no-progress --quiet
else
  cp -R "$PROJECT/vendor" "$RUN_DIR/vendor"
fi
set +e
(cd "$RUN_DIR" && DDD_AUTOLOAD="$RUN_DIR/vendor/autoload.php" php run.php)
code=$?
set -e
if [ "$code" = 0 ]; then
  echo "ok   examples/plain-php/run.php exits 0"
else
  echo "FAIL examples/plain-php/run.php exited $code"
  exit 1
fi
