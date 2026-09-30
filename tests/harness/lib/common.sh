# shellcheck shell=bash
# Shared plumbing for tests/harness/run.sh and tests/Loader/*.sh.
# Sourced, never executed. Requires: bash, git, docker, composer (host).
#
# Environment (all optional):
#   DDD_MYSQL_HOST, DDD_MYSQL_PORT   use an existing MySQL 8.0 server instead of starting one.
#                                    127.0.0.1/localhost are reached from containers via host-gateway.
#   DDD_MYSQL_USER, DDD_MYSQL_PASSWORD   credentials for that server (default root / ddd).
#   DDD_DB_NAME                      database to create (default: unique per run). Always dropped first.
#   DDD_KEEP_DB=1                    keep the database after the run.
#   WP_TESTS_ABSPATH                 host directory WordPress is downloaded into (default: a cache dir).
#   DDD_HARNESS_REF                  git ref to export and test (default HEAD); WORKTREE = tracked +
#                                    untracked-unignored files of the working tree.
#   DDD_DATASTREAM_SRC               clone source for the pinned datastream ref (path or URL);
#                                    default: the local .reference clone if it has the ref, else DATASTREAM_URL.
#   DDD_KEEP_WORK=1                  keep the scratch directory.

set -euo pipefail

HARNESS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_ROOT="$(cd "$HARNESS_DIR/../.." && pwd)"

# shellcheck source=../refs.lock
. "$HARNESS_DIR/refs.lock"

RUN_ID="$(date +%Y%m%d%H%M%S)_$$"
H_MYSQL_CONTAINER=""
H_NETWORK=""
H_WORK=""
H_DB_CREATED=0
H_NET_ARGS=()

log() { printf '[harness] %s\n' "$*" >&2; }
die() { printf '[harness] ERROR: %s\n' "$*" >&2; exit 1; }

need() {
  local tool
  for tool in "$@"; do
    command -v "$tool" >/dev/null 2>&1 || die "'$tool' is required on the host"
  done
}

h_cleanup() {
  local status=$?
  set +e
  if [ "$H_DB_CREATED" = 1 ] && [ "${DDD_KEEP_DB:-0}" != 1 ] && [ -z "$H_MYSQL_CONTAINER" ]; then
    h_php /harness/wp/db.php drop >/dev/null 2>&1
  fi
  if [ -n "$H_MYSQL_CONTAINER" ]; then
    docker rm -f "$H_MYSQL_CONTAINER" >/dev/null 2>&1
  fi
  if [ -n "$H_NETWORK" ]; then
    docker network rm "$H_NETWORK" >/dev/null 2>&1
  fi
  if [ -n "$H_WORK" ] && [ "${DDD_KEEP_WORK:-0}" != 1 ]; then
    rm -rf "$H_WORK"
  elif [ -n "$H_WORK" ]; then
    log "kept scratch dir $H_WORK"
  fi
  exit "$status"
}

h_init() {
  need git docker composer
  H_WORK="$(mktemp -d "${TMPDIR:-/tmp}/ddd-harness.XXXXXX")"
  H_WORK="$(cd "$H_WORK" && pwd -P)"
  trap h_cleanup EXIT
  trap 'exit 130' INT TERM
  DB_NAME="${DDD_DB_NAME:-ddd_harness_${RUN_ID}}"
  export WP_TESTS_ABSPATH_HOST="${WP_TESTS_ABSPATH:-${DDD_HARNESS_CACHE:-$HOME/.cache/tangible-ddd-harness}/wordpress-$WP_VERSION}"
}

# Start (or attach to) MySQL 8.0 and set DB_HOST_IN_CONTAINER / DB_USER / DB_PASSWORD.
h_mysql_up() {
  if [ -n "${DDD_MYSQL_HOST:-}" ]; then
    local host="$DDD_MYSQL_HOST" port="${DDD_MYSQL_PORT:-3306}"
    case "$host" in
      127.0.0.1|localhost|::1) host=host.docker.internal ;;
    esac
    H_NET_ARGS=(--add-host=host.docker.internal:host-gateway)
    DB_HOST_IN_CONTAINER="$host:$port"
    DB_USER="${DDD_MYSQL_USER:-root}"
    DB_PASSWORD="${DDD_MYSQL_PASSWORD:-ddd}"
    log "using existing MySQL at ${DDD_MYSQL_HOST}:${port} (as $DB_HOST_IN_CONTAINER from containers)"
  else
    H_NETWORK="ddd-harness-net-$RUN_ID"
    H_MYSQL_CONTAINER="ddd-harness-mysql-$RUN_ID"
    DB_USER=root
    DB_PASSWORD="harness-$RUN_ID"
    docker network create "$H_NETWORK" >/dev/null
    log "starting $MYSQL_IMAGE as $H_MYSQL_CONTAINER"
    docker run -d --name "$H_MYSQL_CONTAINER" --network "$H_NETWORK" \
      -e MYSQL_ROOT_PASSWORD="$DB_PASSWORD" "$MYSQL_IMAGE" >/dev/null
    H_NET_ARGS=(--network "$H_NETWORK")
    DB_HOST_IN_CONTAINER="$H_MYSQL_CONTAINER:3306"
  fi
  export DB_HOST_IN_CONTAINER DB_USER DB_PASSWORD DB_NAME
  h_php /harness/wp/db.php server
}

h_db_create() {
  h_php /harness/wp/db.php create
  H_DB_CREATED=1
}

# Run a command in the runner image with WordPress at /var/www/html, the
# export at .../plugins/tangible-ddd (read-only) and the harness at /harness.
h_run() {
  local workdir="$1"; shift
  local mounts=(-v "$HARNESS_DIR:/harness:ro")
  if [ -d "$WP_TESTS_ABSPATH_HOST" ]; then
    mounts+=(-v "$WP_TESTS_ABSPATH_HOST:/var/www/html")
  fi
  if [ -n "${H_EXPORT:-}" ]; then
    mounts+=(-v "$H_EXPORT:/var/www/html/wp-content/plugins/tangible-ddd:ro")
  fi
  if [ -n "${H_EXTRA_MOUNTS+x}" ]; then
    mounts+=("${H_EXTRA_MOUNTS[@]}")
  fi
  docker run --rm "${H_NET_ARGS[@]}" \
    -u "$(id -u):$(id -g)" -e HOME=/tmp \
    -e WP_TESTS_DB_NAME="$DB_NAME" -e WP_TESTS_DB_USER="$DB_USER" \
    -e WP_TESTS_DB_PASSWORD="$DB_PASSWORD" -e WP_TESTS_DB_HOST="$DB_HOST_IN_CONTAINER" \
    -e WP_TESTS_ABSPATH=/var/www/html/ \
    -e DDD_EXPECT_MYSQL="${DDD_EXPECT_MYSQL:-8.0}" -e DDD_WP_DEBUG="${DDD_WP_DEBUG:-}" \
    "${mounts[@]}" -w "$workdir" "$WP_CLI_IMAGE" "$@"
}

h_php() { h_run /tmp php -d memory_limit=1G "$@"; }

# Export a clean copy of the source under test (never the live working
# directory, report F-16). A temporary index keeps the repo untouched, and
# unlike `git archive` it ignores export-ignore, which drops tests/.
h_export() {
  local dest="$1" ref="${DDD_HARNESS_REF:-HEAD}"
  mkdir -p "$dest"
  if [ "$ref" = WORKTREE ]; then
    log "exporting working tree (tracked + untracked, unignored)"
    (cd "$REPO_ROOT" && git ls-files -z -co --exclude-standard | while IFS= read -r -d '' f; do
      [ -e "$f" ] || continue
      mkdir -p "$dest/$(dirname "$f")"; cp -p "$f" "$dest/$f"
    done)
  else
    local sha
    sha="$(git -C "$REPO_ROOT" rev-parse --verify "$ref^{commit}")"
    log "exporting $ref ($sha)"
    GIT_INDEX_FILE="$H_WORK/export.index" git -C "$REPO_ROOT" read-tree "$sha"
    GIT_INDEX_FILE="$H_WORK/export.index" git -C "$REPO_ROOT" checkout-index -a --prefix="$dest/"
    rm -f "$H_WORK/export.index"
  fi
}

# Clone the integration host at the pinned ref into $1 and verify the SHA.
h_datastream() {
  local dest="$1" src="${DDD_DATASTREAM_SRC:-}"
  if [ -z "$src" ]; then
    local local_clone="$REPO_ROOT/.reference/tangible-datastream"
    if git -C "$local_clone" cat-file -e "${DATASTREAM_REF}^{commit}" 2>/dev/null; then
      src="$local_clone"
    else
      src="$DATASTREAM_URL"
    fi
  fi
  log "datastream @ ${DATASTREAM_REF:0:12} from $(printf '%s' "$src" | sed -E 's#://[^@/]*@#://***@#')"
  git clone --quiet --no-checkout "$src" "$dest" || die "cannot clone datastream from the configured source"
  git -C "$dest" -c advice.detachedHead=false checkout --quiet --detach "$DATASTREAM_REF" \
    || die "datastream ref $DATASTREAM_REF not found"
  [ "$(git -C "$dest" rev-parse HEAD)" = "$DATASTREAM_REF" ] || die "datastream is not at the pinned ref"
  rm -rf "$dest/.git"
}

# Download WordPress $WP_VERSION into WP_TESTS_ABSPATH_HOST unless present.
h_wp_download() {
  mkdir -p "$WP_TESTS_ABSPATH_HOST"
  if ! grep -q "\$wp_version = '$WP_VERSION'" "$WP_TESTS_ABSPATH_HOST/wp-includes/version.php" 2>/dev/null; then
    log "downloading WordPress $WP_VERSION into $WP_TESTS_ABSPATH_HOST"
    h_run /var/www/html php -d memory_limit=1G /usr/local/bin/wp core download \
      --version="$WP_VERSION" --force --skip-content --quiet
  else
    log "WordPress $WP_VERSION present in $WP_TESTS_ABSPATH_HOST"
  fi
}

# Download WordPress (cached per version), write the guarded wp-config, install.
h_wordpress() {
  h_wp_download
  cp "$HARNESS_DIR/wp/wp-config.php" "$WP_TESTS_ABSPATH_HOST/wp-config.php"
  mkdir -p "$WP_TESTS_ABSPATH_HOST/wp-content/plugins"
  h_run /var/www/html wp core install --url=http://localhost --title=ddd-harness \
    --admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email --quiet
  log "WordPress installed into $DB_NAME (prefix wptests_)"
}
