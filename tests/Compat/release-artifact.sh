#!/usr/bin/env bash
# Release artifact check of tangible/ddd (register 1.1, 1.5 and section 8
# wave 4, packaging):
#
#   tests/Compat/release-artifact.sh [ref]     (default HEAD)
#
# Lists `git archive <ref> | tar t` of the repository in the CURRENT directory
# and fails when the archive a consumer would vendor carries a development
# path (tests, docs, tools, examples, ddd-symfony, ddd-conformance, any
# package's tests), or lacks a runtime path: the root manifest and plugin
# file, the version-unique loader entry, the winner autoloader and load
# diagnostics, the compat alias map, the self-consume shim, and the matched
# ddd-core + ddd-wp pair (sources, manifests and ddd-core's MySQL schema).
#
# Needs git and tar; no network, no PHP.

set -euo pipefail

REF="${1:-HEAD}"
git rev-parse --verify -q "$REF^{commit}" >/dev/null || { echo "FAIL $REF is not a commit"; exit 1; }

LIST="$(git archive --format=tar "$REF" | tar -tf -)"
fail=0

# The version-unique loader entry is named after the plugin header's version.
version="$(git show "$REF:tangible-ddd.php" 2>/dev/null \
  | sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' | head -1)"
slug="${version//./_}"

leaks="$(printf '%s\n' "$LIST" | grep -E '^(tests|docs|tools|examples|\.github)/|^packages/(ddd-symfony|ddd-conformance)/|^packages/[^/]+/tests/' | grep -v '/$' || true)"
if [ -n "$leaks" ]; then
  while IFS= read -r path; do
    echo "FAIL shipped: $path"
  done <<< "$leaks"
  fail=1
else
  echo "ok   no tests/, docs/, tools/, examples/, packages/ddd-symfony/, packages/ddd-conformance/ or packages/*/tests/ in the archive"
fi

required=(
  tangible-ddd.php
  composer.json
  "loader/tangible-ddd-${slug:-MISSING_VERSION}.php"
  loader/winner-autoloader.php
  loader/load-diagnostics.php
  compat/aliases.php
  ddd-wordpress/self/index.php
  packages/ddd-core/composer.json
  packages/ddd-core/src/
  packages/ddd-core/schema/mysql8/
  packages/ddd-wp/composer.json
  packages/ddd-wp/src/
  packages/ddd-wp/wordpress/
)
for path in "${required[@]}"; do
  if [ "${path%/}" != "$path" ]; then
    # a directory: at least one file under it
    if printf '%s\n' "$LIST" | awk -v p="$path" 'index($0, p) == 1 && $0 !~ /\/$/ { found = 1 } END { exit !found }'; then
      echo "ok   $path"
    else
      echo "FAIL missing: $path"
      fail=1
    fi
  elif printf '%s\n' "$LIST" | grep -q -x -F "$path"; then
    echo "ok   $path"
  else
    echo "FAIL missing: $path"
    fail=1
  fi
done

if [ "$fail" -ne 0 ]; then
  echo "release artifact of $REF: FAIL"
  exit 1
fi
echo "release artifact of $REF: ok ($(printf '%s\n' "$LIST" | grep -c -v '/$') files)"
