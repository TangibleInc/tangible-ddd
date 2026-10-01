# shellcheck shell=bash
# `tests/harness/run.sh loader`: the register 7.2 load-order fixtures on a real
# WordPress + MySQL 8.0 (wave 2: every case except load.jetpack-mixed).
# Sourced by run.sh after lib/common.sh. Report F section 6.
#
# Every copy is a real Composer install: each fixture plugin under
# wp-content/plugins/fx-<label>/ requires tangible/ddd from a copying path
# repository over a `git` export of a tag or branch of this clone, exactly the
# way a consumer plugin vendors it. P is the new export placed at
# wp-content/plugins/tangible-ddd/ and activated as a plugin.
#
# Environment (plus everything in lib/common.sh):
#   DDD_HARNESS_REF        ref of the new copy N (default HEAD; WORKTREE for uncommitted work)
#   DDD_LOADER_LEGACY      in-window legacy refs (default "v0.6.2 v0.6.4 v0.6.5 v0.6.6 hotfix/0.6.7")
#   DDD_LOADER_NEGATIVE    pre-window ref (default v0.2.5)
#   DDD_LOADER_PRELOAD     legacy ref whose plugin touches a class at include time (default v0.6.5)
#   DDD_LOADER_CASES       only run case ids starting with one of these space-separated prefixes
#   DDD_COMPILED_ZIPS      directory holding the shipped tangible-lms-0.12.0 / quiz-0.7.0 /
#                          certificates-0.3.1 zips for load.compiled-containers (wave 4 owns
#                          the full resolution check; without it the case is reported SKIP)

loader_label() {
  local ref="$1" label
  case "$ref" in
    v*) label="legacy-${ref#v}" ;;
    *) label="${ref//\//-}" ;;
  esac
  label="${label//./_}"
  printf '%s' "${label//[^A-Za-z0-9_-]/_}"
}

loader_header_version() {
  sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$1/tangible-ddd.php" | head -1
}

# loader_copy <label> <ref> -> exports <ref> into copies/<label>, prints its version
loader_copy() {
  local label="$1" ref="$2" copy="$H_WORK/copies/$1" version
  if [ ! -d "$copy" ]; then
    DDD_HARNESS_REF="$ref" h_export "$copy"
  fi
  version="$(loader_header_version "$copy")"
  [ -n "$version" ] || die "no Version: header in $ref"
  printf '%s' "$version"
}

# loader_plugin <plugin label> <copy label> <version> [include-time php]
loader_plugin() {
  local label="$1" copy="$H_WORK/copies/$2" version="$3" include_time="${4:-}" plugin="$H_WORK/plugins/fx-$1"
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
  log "composer install fx-$label (tangible/ddd $version)"
  composer install -d "$plugin" --no-dev --no-interaction --no-progress --quiet
  php -r '
    [, $tpl, $out, $label, $version, $include] = $argv;
    file_put_contents($out, str_replace(
      ["__LABEL__", "__VERSION__", "// __INCLUDE_TIME__"],
      [$label, $version, $include === "" ? "// (no include-time code)" : $include],
      file_get_contents($tpl)
    ));
  ' "$REPO_ROOT/tests/Loader/fixtures/fx-plugin.php.tpl" "$plugin/fx-$label.php" "$label" "$version" "$include_time"
}

loader_selected() {
  local id="$1" prefix
  [ -z "${DDD_LOADER_CASES:-}" ] && return 0
  for prefix in $DDD_LOADER_CASES; do
    case "$id" in "$prefix"*) return 0 ;; esac
  done
  return 1
}

loader_main() {
  local loader_dir="$REPO_ROOT/tests/Loader"
  local n_ref="${DDD_HARNESS_REF:-HEAD}"
  local legacy_refs="${DDD_LOADER_LEGACY:-v0.6.2 v0.6.4 v0.6.5 v0.6.6 hotfix/0.6.7}"
  local negative_ref="${DDD_LOADER_NEGATIVE:-v0.2.5}"
  local preload_ref="${DDD_LOADER_PRELOAD:-v0.6.5}"
  need php

  # ── Copies and fixture plugins ───────────────────────────────────────────
  local n_version ref label version legacy_pairs="" negative_pairs="" preload_pairs=""
  n_version="$(loader_copy new "$n_ref")"
  log "new copy N: $n_version ($n_ref)"
  loader_plugin new new "$n_version"
  loader_plugin new2 new "$n_version"

  for ref in $legacy_refs; do
    label="$(loader_label "$ref")"
    version="$(loader_copy "$label" "$ref")"
    loader_plugin "$label" "$label" "$version"
    legacy_pairs="$legacy_pairs $label=$version"
  done
  if [ -n "$negative_ref" ]; then
    label="$(loader_label "$negative_ref")"
    version="$(loader_copy "$label" "$negative_ref")"
    loader_plugin "$label" "$label" "$version"
    negative_pairs="$label=$version"
  fi
  if [ -n "$preload_ref" ]; then
    local copy_label
    copy_label="$(loader_label "$preload_ref")"
    label="preload-${copy_label#legacy-}"
    version="$(loader_copy "$copy_label" "$preload_ref")"
    loader_plugin "$label" "$copy_label" "$version" \
      "class_exists('TangibleDDD\\\\Application\\\\Outbox\\\\OutboxConfig'); // a consumer touching a framework class at include time (B13)"
    preload_pairs="$label=$version"
  fi
  mkdir -p "$H_WORK/plugins/fx-needs"
  cp "$loader_dir/fixtures/fx-needs.php" "$H_WORK/plugins/fx-needs/fx-needs.php"

  # ── WordPress ────────────────────────────────────────────────────────────
  h_mysql_up
  h_db_create
  h_wp_download
  cp -R "$WP_TESTS_ABSPATH_HOST" "$H_WORK/wordpress"
  export WP_TESTS_ABSPATH_HOST="$H_WORK/wordpress"
  h_wordpress
  local wp_content="$WP_TESTS_ABSPATH_HOST/wp-content"
  mkdir -p "$wp_content/mu-plugins"
  cp "$loader_dir/fixtures/mu-recorder.php" "$wp_content/mu-plugins/fx-recorder.php"
  cp -R "$H_WORK/plugins/." "$wp_content/plugins/"
  # P: the new distribution activated as a plugin (no vendor of its own).
  # The cached tree may hold an empty plugins/tangible-ddd mount point left by
  # the wp-integration subcommand; replace it.
  rm -rf "$wp_content/plugins/tangible-ddd"
  mkdir -p "$wp_content/plugins/tangible-ddd"
  cp -R "$H_WORK/copies/new/." "$wp_content/plugins/tangible-ddd/"
  H_EXTRA_MOUNTS=(-v "$loader_dir/fixtures:/loader:ro")

  # ── Cases ────────────────────────────────────────────────────────────────
  mkdir -p "$H_WORK/cases"
  local pass=0 fail=0 skip=0 id plugins debug late spec out
  local -a failed=()
  while IFS=$'\t' read -r id plugins debug late spec; do
    [ -n "$id" ] || continue
    loader_selected "$id" || continue
    h_run /var/www/html wp option update active_plugins "$plugins" --format=json --skip-plugins --skip-themes --quiet
    if [ "$late" = 1 ]; then
      cp "$loader_dir/fixtures/mu-late.php" "$wp_content/mu-plugins/fx-late.php"
    else
      rm -f "$wp_content/mu-plugins/fx-late.php"
    fi
    out="$H_WORK/cases/$(printf '%s' "$id" | tr -c 'A-Za-z0-9_.-' '_')"
    DDD_WP_DEBUG="$([ "$debug" = 1 ] && echo 1 || echo '')" \
      h_run /var/www/html php -d memory_limit=1G -d display_errors=stderr -d log_errors=1 \
        -d error_log=/tmp/ddd-loader.log /usr/local/bin/wp eval-file /loader/probe.php \
      > "$out.json" 2> "$out.stderr" || true
    if php "$loader_dir/assert-case.php" "$id" "$out.json" "$spec"; then
      pass=$((pass + 1))
    else
      fail=$((fail + 1))
      failed+=("$id")
      sed 's/^/    stderr: /' "$out.stderr" | head -20 >&2
    fi
  done < <(DDD_N_VERSION="$n_version" DDD_LEGACY="$legacy_pairs" DDD_NEGATIVE="$negative_pairs" \
             DDD_PRELOAD="$preload_pairs" php "$loader_dir/cases.php")
  rm -f "$wp_content/mu-plugins/fx-late.php"

  if loader_selected load.compiled-containers; then
    # Register 7.2 load.compiled-containers needs the shipped consumer zips
    # (LMS 0.12.0, quiz 0.7.0, certificates 0.3.1); wave 4 (wp) owns the full
    # resolution check of every compiled service.
    if [ -n "${DDD_COMPILED_ZIPS:-}" ]; then
      die "load.compiled-containers with DDD_COMPILED_ZIPS is wave-4 work (wp); unset it for the wave-2 run"
    fi
    echo "SKIP load.compiled-containers: shipped zips not provided (DDD_COMPILED_ZIPS); full resolution is wave 4 (wp)"
    skip=$((skip + 1))
  fi
  echo "SKIP load.jetpack-mixed: wave 4 (wp), register section 8"
  skip=$((skip + 1))

  if [ "${DDD_KEEP_WORK:-0}" = 1 ]; then
    log "probe output per case kept in $H_WORK/cases"
  fi
  log "loader: $pass passed, $fail failed, $skip skipped"
  if [ "$fail" -gt 0 ]; then
    log "failed: ${failed[*]}"
    return 1
  fi
  [ "$pass" -gt 0 ] || die "no case ran (DDD_LOADER_CASES=${DDD_LOADER_CASES:-})"
}
