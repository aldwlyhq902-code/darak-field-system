#!/usr/bin/env bash
#
# Darak — deploy a release tag on the VPS prepared by provision-ubuntu.sh.
#
#   sudo -u darak /var/www/darak/deploy/deploy.sh v0.3.0
#
# Order matters:
#   1. maintenance mode + verified pre-deploy backup
#   2. checkout the tag, composer install --no-dev, npm ci && npm run build
#   3. darak:preflight with the NEW code — on FAIL the previous release is put
#      back and the site comes up again; nothing has been migrated yet
#   4. migrations as the owner role, then the app-role grants
#   5. config/route/view caches, PHP-FPM reload, site up
#   6. once only: darak:revoke-backoffice-tokens (first deploy that ships it)
#
# A failure after migrations leaves the site in maintenance mode on purpose:
# old code against a new schema is the one state worse than downtime.
set -Eeuo pipefail

TAG=${1:?usage: deploy.sh <release-tag>}
APP_ROOT=${DARAK_APP_ROOT:-/var/www/darak}
BACKEND="$APP_ROOT/backend"
PHP_BIN=${DARAK_PHP:-php}
PHP_FPM_SERVICE=${DARAK_PHP_FPM_SERVICE:-php8.3-fpm}
MIGRATE_ENV=${DARAK_MIGRATE_ENV:-/etc/darak/migrate.env}
DB_APP_ROLE=${DARAK_DB_APP_ROLE:-darak_app}
STATE_DIR=${DARAK_STATE_DIR:-/var/lib/darak}
SKIP_BACKUP=${DARAK_SKIP_PREDEPLOY_BACKUP:-0}
# darak:revoke-backoffice-tokens must revoke without prompting under these args.
read -r -a REVOKE_ARGS <<< "${DARAK_REVOKE_TOKENS_ARGS:---force --no-interaction}"

STARTED=$(date +%s)
MIGRATED=0
WENT_DOWN=0
FIRST_DEPLOY=0
PREVIOUS=""

log() { printf '[%s] %s\n' "$(date '+%F %T')" "$*"; }
artisan() { (cd "$BACKEND" && "$PHP_BIN" artisan "$@"); }

build_release() {
    (cd "$BACKEND" && composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --no-progress)
    (cd "$BACKEND" && npm ci --no-audit --no-fund && npm run build)
}

restore_previous() {
    if [[ $FIRST_DEPLOY -eq 1 ]]; then
        log "First deploy: there is no previous release to restore."
        return
    fi
    set +e
    log "Restoring previous release ${PREVIOUS:0:12} (no migrations were run)."
    if [[ $(git -C "$APP_ROOT" rev-parse HEAD) == "$PREVIOUS" ]]; then
        artisan config:cache && artisan up
    else
        git -C "$APP_ROOT" checkout --quiet --detach "$PREVIOUS" \
            && build_release \
            && artisan config:cache && artisan route:cache && artisan view:cache \
            && sudo /usr/bin/systemctl reload "$PHP_FPM_SERVICE" \
            && artisan up
    fi
    local status=$?
    set -e
    if [[ $status -ne 0 ]]; then
        log "Could not restore the previous release automatically; the site stays in maintenance mode."
    fi
}

on_error() {
    local line=$1
    trap - ERR
    log "Deploy of ${TAG} FAILED at line ${line}."
    if [[ $MIGRATED -eq 0 && -n $PREVIOUS ]]; then
        restore_previous
    elif [[ $MIGRATED -eq 1 ]]; then
        log "Migrations already ran. The site stays in maintenance mode."
        log "Fix forward and re-run this script, or restore the pre-deploy backup (deploy/restore-drill.md)."
        log "Do NOT run old code against the new schema, and do not migrate:rollback without a verified backup."
    fi
    exit 1
}
trap 'on_error $LINENO' ERR

[[ $(id -u) -ne 0 ]] || { echo "Run as the application user (sudo -u darak ...), not root." >&2; exit 1; }
[[ -f $BACKEND/.env ]] || { echo "Missing $BACKEND/.env — see DEPLOYMENT.md §2." >&2; exit 1; }
[[ -r $MIGRATE_ENV ]] || { echo "Missing $MIGRATE_ENV (owner-role credentials from provision-ubuntu.sh)." >&2; exit 1; }

cd "$APP_ROOT"
if [[ -n $(git status --porcelain --untracked-files=no) ]]; then
    echo "Tracked files were modified on the server; refusing to deploy over them." >&2
    git status --short --untracked-files=no >&2
    exit 1
fi

log "Fetching ${TAG}"
git fetch --quiet --tags --force origin
git rev-parse --quiet --verify "refs/tags/${TAG}^{commit}" >/dev/null \
    || { echo "Tag ${TAG} not found." >&2; exit 1; }
PREVIOUS=$(git rev-parse HEAD)
[[ -f $BACKEND/vendor/autoload.php ]] || FIRST_DEPLOY=1

if [[ $FIRST_DEPLOY -eq 0 ]]; then
    log "Maintenance mode on"
    artisan down --retry=60
    WENT_DOWN=1

    if [[ $SKIP_BACKUP != 1 ]]; then
        # Local and verified: the immediate way back. A remote outage must not
        # block a deploy; the nightly run handles the off-site copy.
        log "Pre-deploy backup (verified)"
        artisan darak:backup --label="predeploy-${TAG//[^A-Za-z0-9._-]/_}" --no-offsite --no-rotate
    fi
fi

log "Checking out ${TAG}"
git checkout --quiet --detach "$TAG"
build_release

log "Production preflight (new code, before any migration)"
artisan config:clear >/dev/null
if ! artisan darak:preflight; then
    log "Preflight FAILED — nothing migrated."
    trap - ERR
    restore_previous
    exit 1
fi

# From here on a failure must NOT put the old code back: the schema may have moved.
MIGRATED=1

log "Migrations as the owner role"
(
    set -a
    # shellcheck disable=SC1090
    . "$MIGRATE_ENV"
    set +a
    cd "$BACKEND"
    "$PHP_BIN" artisan migrate --force --no-interaction
)

log "Application-role grants"
read -r DB_HOST DB_PORT DB_DATABASE DB_SSLMODE < <(cd "$BACKEND" && "$PHP_BIN" -r '
    require "vendor/autoload.php";
    $env = Dotenv\Dotenv::createArrayBacked(".")->load();
    echo implode(" ", [
        $env["DB_HOST"] ?? "127.0.0.1", $env["DB_PORT"] ?? "5432",
        $env["DB_DATABASE"] ?? "darak", $env["DB_SSLMODE"] ?? "require",
    ]), PHP_EOL;')
(
    set -a
    # shellcheck disable=SC1090
    . "$MIGRATE_ENV"
    set +a
    PGPASSWORD="$DB_PASSWORD" PGSSLMODE="$DB_SSLMODE" psql -X -q -v ON_ERROR_STOP=1 \
        --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" --dbname="$DB_DATABASE" \
        -v app_role="$DB_APP_ROLE" -f "$APP_ROOT/deploy/postgres/app-grants.sql"
)

log "Caches"
[[ -L $BACKEND/public/storage ]] || artisan storage:link
artisan config:cache
artisan route:cache
artisan view:cache

log "Reloading PHP-FPM"
sudo /usr/bin/systemctl reload "$PHP_FPM_SERVICE"

REVOKE_STATUS=0
REVOKE_MARKER="$STATE_DIR/revoke-backoffice-tokens.done"
if [[ ! -f $REVOKE_MARKER ]]; then
    # No grep -q: with pipefail an early exit would SIGPIPE artisan and read as "absent".
    if artisan list --raw | grep -E '^darak:revoke-backoffice-tokens( |$)' >/dev/null; then
        log "First deploy with darak:revoke-backoffice-tokens — revoking back-office tokens once"
        if artisan darak:revoke-backoffice-tokens "${REVOKE_ARGS[@]}"; then
            date -u '+%FT%TZ' > "$REVOKE_MARKER"
        else
            REVOKE_STATUS=1
            log "WARNING: token revocation failed; it will be retried on the next deploy."
        fi
    fi
fi

if [[ $WENT_DOWN -eq 1 ]]; then
    artisan up
fi
trap - ERR

artisan darak:health --json || log "darak:health is not green yet (normal right after the first deploy, before cron runs)."
log "Deployed ${TAG} in $(( $(date +%s) - STARTED ))s (previous: ${PREVIOUS:0:12})."
exit "$REVOKE_STATUS"
