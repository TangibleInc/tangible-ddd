# shellcheck shell=bash
# `tests/harness/run.sh loader`: the register 7.2 load-order fixtures on a real
# WordPress + MySQL 8.0, every case (wave 4 adds load.jetpack-mixed and
# load.compiled-containers; nothing is skipped, and a kind that never ran is a
# failure). Sourced by run.sh after lib/common.sh. Report F section 6.
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
#   DDD_LOADER_COMPILED    legacy ref the compiled-container and Jetpack-legacy plugins bundle
#                          (default v0.6.5, the copy the three shipped zips carry)
#   DDD_LOADER_NEXT_VERSION  version of `next`, N re-versioned for load.jetpack-mixed
#                          (default N's version with the patch number + 1)
#   DDD_LOADER_CASES       only run case ids starting with one of these space-separated prefixes
#                          (a narrowed run does not check that every 7.2 kind ran)
#
# load.compiled-containers runs the committed fixture copies of the shipped
# containers (tests/Loader/fixtures/compiled, made by
# tests/Loader/bin/extract-compiled-container.php); the zips are not needed.
# The Jetpack fixtures install automattic/jetpack-autoloader from Packagist.

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
  # A branch that exists only as a remote-tracking ref (a CI clone) resolves
  # through origin/.
  if [ "$ref" != WORKTREE ] && ! git -C "$REPO_ROOT" rev-parse --verify -q "$ref^{commit}" >/dev/null \
    && git -C "$REPO_ROOT" rev-parse --verify -q "origin/$ref^{commit}" >/dev/null; then
    ref="origin/$ref"
  fi
  if [ ! -d "$copy" ]; then
    DDD_HARNESS_REF="$ref" h_export "$copy"
  fi
  version="$(loader_header_version "$copy")"
  [ -n "$version" ] || die "no Version: header in $ref"
  printf '%s' "$version"
}

# loader_bump_copy <source copy> <new copy> <new version>: a second build of a
# 0.7+ copy under another version (load.jetpack-mixed "different N builds"):
# the plugin header, the constant, the register literal, the version-unique
# loader entry (file and the root manifest's files entry) and the function
# slugs move to <new version>; the code is otherwise the same commit.
loader_bump_copy() {
  local src="$H_WORK/copies/$1" dst="$H_WORK/copies/$2" to="$3" from
  from="$(loader_header_version "$src")"
  local from_slug="${from//[.-]/_}" to_slug="${to//[.-]/_}"
  rm -rf "$dst"
  cp -R "$src" "$dst"
  [ -f "$dst/loader/tangible-ddd-$from_slug.php" ] || die "copy $1 has no loader/tangible-ddd-$from_slug.php to re-version"
  mv "$dst/loader/tangible-ddd-$from_slug.php" "$dst/loader/tangible-ddd-$to_slug.php"
  php -r '
    [, $dir, $from, $to, $from_slug, $to_slug] = $argv;
    foreach (["tangible-ddd.php", "composer.json", "loader/tangible-ddd-$to_slug.php"] as $f) {
      $s = (string) file_get_contents("$dir/$f");
      file_put_contents("$dir/$f", str_replace([$from, $from_slug], [$to, $to_slug], $s));
    }
  ' "$dst" "$from" "$to" "$from_slug" "$to_slug"
  [ "$(loader_header_version "$dst")" = "$to" ] || die "re-versioning $1 to $to failed"
  printf '%s' "$to"
}

# loader_plugin <plugin label> <copy label> <version> [include-time php] [composer|jetpack]
# A jetpack plugin requires automattic/jetpack-autoloader (the version LMS
# 0.12.0 and quiz 0.7.0 ship) and loads vendor/autoload_packages.php.
loader_plugin() {
  local label="$1" copy="$H_WORK/copies/$2" version="$3" include_time="${4:-}" kind="${5:-composer}" plugin="$H_WORK/plugins/fx-$1"
  local extra_require="" extra_config="" autoload=autoload.php
  if [ "$kind" = jetpack ]; then
    extra_require=',
    "automattic/jetpack-autoloader": "^5.0"'
    extra_config=', "allow-plugins": {"automattic/jetpack-autoloader": true}'
    autoload=autoload_packages.php
  fi
  mkdir -p "$plugin"
  cat > "$plugin/composer.json" <<JSON
{
  "name": "fx/$label",
  "require": {
    "tangible/ddd": "$version",
    "league/tactician": "^2.0-rc1",
    "symfony/yaml": "^7.4"$extra_require
  },
  "repositories": [
    {"type": "path", "url": "$copy", "options": {"symlink": false, "versions": {"tangible/ddd": "$version"}}}
  ],
  "config": {"platform": {"php": "8.2.0"}$extra_config}
}
JSON
  log "composer install fx-$label (tangible/ddd $version, $kind)"
  composer install -d "$plugin" --no-dev --no-interaction --no-progress --quiet
  [ -f "$plugin/vendor/$autoload" ] || die "fx-$label: composer produced no vendor/$autoload"
  php -r '
    [, $tpl, $out, $label, $version, $include, $autoload] = $argv;
    file_put_contents($out, str_replace(
      ["__LABEL__", "__VERSION__", "// __INCLUDE_TIME__", "__AUTOLOAD__"],
      [$label, $version, $include === "" ? "// (no include-time code)" : $include, $autoload],
      file_get_contents($tpl)
    ));
  ' "$REPO_ROOT/tests/Loader/fixtures/fx-plugin.php.tpl" "$plugin/fx-$label.php" "$label" "$version" "$include_time" "$autoload"
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
  local compiled_ref="${DDD_LOADER_COMPILED:-v0.6.5}"
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

  # load.jetpack-mixed: N and `next` (N re-versioned), each once behind the
  # Jetpack Autoloader and once behind plain Composer, plus a Jetpack plugin
  # bundling the compiled-container legacy copy (LMS 0.12.0's shape).
  local next_version jetpack_legacy_pairs="" compiled_pairs="" compiled_copy compiled_version
  next_version="${DDD_LOADER_NEXT_VERSION:-$(php -r '$v = explode(".", $argv[1]); $v[2] = (string) ((int) ($v[2] ?? 0) + 1); echo implode(".", array_slice($v, 0, 3));' "$n_version")}"
  loader_bump_copy new next "$next_version" >/dev/null
  log "next copy: $next_version (N re-versioned)"
  loader_plugin next next "$next_version"
  loader_plugin jp-new new "$n_version" "" jetpack
  loader_plugin jp-next next "$next_version" "" jetpack

  compiled_copy="$(loader_label "$compiled_ref")"
  compiled_version="$(loader_copy "$compiled_copy" "$compiled_ref")"
  loader_plugin "jp-$compiled_copy" "$compiled_copy" "$compiled_version" "" jetpack
  jetpack_legacy_pairs="jp-$compiled_copy=$compiled_version"

  # load.compiled-containers: the three shipped consumers' compiled
  # containers (tests/Loader/fixtures/compiled), each in a plugin bundling
  # the legacy copy the way its zip does.
  local fixture kind
  for fixture in lms-0_12_0=jetpack quiz-0_7_0=jetpack certificates-0_3_1=composer; do
    kind="${fixture#*=}" fixture="${fixture%%=*}"
    [ -f "$loader_dir/fixtures/compiled/$fixture/manifest.json" ] || die "no compiled-container fixture $fixture"
    loader_plugin "cc-$fixture" "$compiled_copy" "$compiled_version" \
      "\$fx_cc_label = '$fixture'; require __DIR__ . '/compiled/fixture.php'; // the shipped container, built lazily at boot" "$kind"
    cp -R "$loader_dir/fixtures/compiled" "$H_WORK/plugins/fx-cc-$fixture/compiled"
    compiled_pairs="$compiled_pairs cc-$fixture=$kind"
  done

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
  : > "$H_WORK/cases.log"
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
    if php "$loader_dir/assert-case.php" "$id" "$out.json" "$spec" | tee -a "$H_WORK/cases.log"; then
      pass=$((pass + 1))
    else
      fail=$((fail + 1))
      failed+=("$id")
      sed 's/^/    stderr: /' "$out.stderr" | head -20 >&2
    fi
  done < <(DDD_N_VERSION="$n_version" DDD_LEGACY="$legacy_pairs" DDD_NEGATIVE="$negative_pairs" \
             DDD_PRELOAD="$preload_pairs" DDD_N_NEXT_VERSION="$next_version" \
             DDD_JETPACK_LEGACY="$jetpack_legacy_pairs" DDD_COMPILED="$compiled_pairs" \
             DDD_COMPILED_VERSION="$compiled_version" php "$loader_dir/cases.php")
  rm -f "$wp_content/mu-plugins/fx-late.php"

  # Every kind of register 7.2 ran (unless the run was narrowed on purpose):
  # nothing is skipped from wave 4 on.
  if [ -z "${DDD_LOADER_CASES:-}" ]; then
    local kind_id
    for kind_id in $LOADER_KINDS_7_2; do
      if ! grep -q -E "^(PASS|FAIL) ${kind_id//./\\.}(\[|:| |$)" "$H_WORK/cases.log"; then
        echo "FAIL ${kind_id}: no case of this register 7.2 kind ran"
        fail=$((fail + 1))
        failed+=("$kind_id")
      fi
    done
  fi

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

# The case kinds of register 7.2; `run.sh loader` fails if one never ran.
LOADER_KINDS_7_2="load.new-alone load.legacy-first load.new-first load.compiled-containers load.preloaded-class
  load.plugin-active load.new-twice load.late load.min-unmet load.jetpack-mixed load.v0-2-negative"
