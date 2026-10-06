#!/usr/bin/env bash
#
# Darak — one-time (and safely re-runnable) provisioning of an Ubuntu 24.04 VPS.
#
#   sudo DARAK_DOMAIN=panel.example.sa DARAK_ADMIN_EMAIL=ops@example.sa \
#        DARAK_REPO=git@github.com:owner/darak.git ./deploy/provision-ubuntu.sh
#
# Installs and configures: PHP 8.3-FPM (+ pgsql, gd, intl, zip, bcmath, mbstring,
# curl, xml, redis, opcache), Composer, Node.js 22, Nginx, PostgreSQL 16, Redis,
# certbot, rclone, ufw (22/80/443), fail2ban, unattended-upgrades, swap and the
# Asia/Riyadh time zone. Creates two database roles:
#   - DARAK_DB_OWNER_ROLE (darak_owner): owns the schema; used ONLY by deploy.sh
#     for migrations. Credentials in /etc/darak/migrate.env (root:darak 0640).
#   - DARAK_DB_APP_ROLE (darak_app): what the application connects as. Not a
#     superuser, not the owner (cannot disable the audit-log triggers or drop
#     tables), BYPASSRLS so the Supabase-defence RLS does not hide rows from it.
#     Its password is in /etc/darak/db-app.pass for the .env file.
#
# Every step checks before it changes anything, so running the script again
# after a partial failure (or to pick up a new template) is safe.
set -Eeuo pipefail

log() { printf '\n==> %s\n' "$*"; }
warn() { printf '\n!!  %s\n' "$*" >&2; }
trap 'warn "Provisioning failed at line ${LINENO}. Fix the cause and re-run; completed steps are skipped."' ERR

[[ ${EUID} -eq 0 ]] || { echo "Run as root (sudo)." >&2; exit 1; }

DOMAIN=${DARAK_DOMAIN:?Set DARAK_DOMAIN, e.g. panel.example.sa}
ADMIN_EMAIL=${DARAK_ADMIN_EMAIL:-}
REPO=${DARAK_REPO:-}
APP_USER=${DARAK_APP_USER:-darak}
APP_ROOT=${DARAK_APP_ROOT:-/var/www/darak}
BACKUP_DIR=${DARAK_BACKUP_DIR:-/var/backups/darak}
DB_NAME=${DARAK_DB_NAME:-darak}
DB_APP_ROLE=${DARAK_DB_APP_ROLE:-darak_app}
DB_OWNER_ROLE=${DARAK_DB_OWNER_ROLE:-darak_owner}
SWAP_SIZE=${DARAK_SWAP_SIZE:-2G}
PHP_VERSION=8.3
PG_VERSION=16
SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)

# shellcheck disable=SC1091
. /etc/os-release
if [[ ${ID:-} != ubuntu || ${VERSION_ID:-} != "24.04" ]]; then
    warn "Written for Ubuntu 24.04; this is ${PRETTY_NAME:-unknown}. Continuing, but check every step."
fi

render() { # render <template> <destination> <mode>
    local tmp
    tmp=$(mktemp)
    sed -e "s|__DOMAIN__|${DOMAIN}|g" \
        -e "s|__APP_ROOT__|${APP_ROOT}|g" \
        -e "s|__APP_USER__|${APP_USER}|g" \
        -e "s|__BACKUP_DIR__|${BACKUP_DIR}|g" \
        -e "s|__PHP_VERSION__|${PHP_VERSION}|g" \
        "$1" > "$tmp"
    install -m "$3" "$tmp" "$2"
    rm -f "$tmp"
}

secret() { # secret <file>: create once, never rotate silently
    if [[ ! -s $1 ]]; then
        (umask 077 && openssl rand -hex 32 > "$1")
    fi
    cat "$1"
}

log "Time zone Asia/Riyadh"
timedatectl set-timezone Asia/Riyadh

log "Swap (${SWAP_SIZE})"
if ! swapon --show=NAME --noheadings | grep -q .; then
    if [[ ! -f /swapfile ]]; then
        fallocate -l "$SWAP_SIZE" /swapfile
        chmod 600 /swapfile
        mkswap /swapfile
    fi
    swapon /swapfile
fi
grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
echo 'vm.swappiness=10' > /etc/sysctl.d/90-darak-swap.conf
sysctl -q -p /etc/sysctl.d/90-darak-swap.conf

log "Base packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -y -q ca-certificates curl gnupg git unzip acl openssl logrotate cron

log "Node.js 22 (NodeSource; package.json requires 22.x)"
install -d -m 0755 /etc/apt/keyrings
if [[ ! -f /etc/apt/keyrings/nodesource.gpg ]]; then
    curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg
fi
echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_22.x nodistro main" \
    > /etc/apt/sources.list.d/nodesource.list
apt-get update -q

log "PHP ${PHP_VERSION}, PostgreSQL ${PG_VERSION}, Redis, Nginx, certbot, rclone, security tooling"
apt-get install -y -q \
    "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-pgsql" "php${PHP_VERSION}-gd" \
    "php${PHP_VERSION}-intl" "php${PHP_VERSION}-zip" "php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-mbstring" \
    "php${PHP_VERSION}-curl" "php${PHP_VERSION}-xml" "php${PHP_VERSION}-redis" "php${PHP_VERSION}-opcache" \
    composer nodejs \
    "postgresql-${PG_VERSION}" "postgresql-client-${PG_VERSION}" \
    redis-server nginx certbot rclone ufw fail2ban unattended-upgrades

log "Application user and directories"
if ! id -u "$APP_USER" >/dev/null 2>&1; then
    useradd --create-home --shell /bin/bash "$APP_USER"
fi
install -d -o "$APP_USER" -g "$APP_USER" -m 0755 "$APP_ROOT"
install -d -o "$APP_USER" -g "$APP_USER" -m 0700 "$BACKUP_DIR"
install -d -o "$APP_USER" -g "$APP_USER" -m 0750 /var/lib/darak
install -d -o root -g "$APP_USER" -m 0750 /etc/darak
install -d -m 0755 /var/www/letsencrypt

if [[ -n $REPO && ! -d $APP_ROOT/.git ]]; then
    log "Cloning ${REPO}"
    sudo -u "$APP_USER" git clone "$REPO" "$APP_ROOT"
fi

log "Unattended security upgrades"
cat > /etc/apt/apt.conf.d/20auto-upgrades <<'CONF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::AutocleanInterval "7";
CONF

log "Firewall: SSH, HTTP, HTTPS only"
ufw default deny incoming >/dev/null
ufw default allow outgoing >/dev/null
ufw allow 22/tcp >/dev/null
ufw allow 80/tcp >/dev/null
ufw allow 443/tcp >/dev/null
ufw --force enable >/dev/null

log "fail2ban (sshd, nginx-botsearch)"
cat > /etc/fail2ban/jail.d/darak.conf <<'CONF'
[sshd]
enabled = true
maxretry = 5
bantime = 1h

[nginx-botsearch]
enabled = true
CONF
systemctl enable --now fail2ban >/dev/null
systemctl restart fail2ban

log "Redis: localhost only, password required"
REDIS_PASSWORD=$(secret /etc/darak/redis.pass)
chgrp "$APP_USER" /etc/darak/redis.pass && chmod 0640 /etc/darak/redis.pass
if grep -qE '^bind ' /etc/redis/redis.conf; then
    sed -i -E 's/^bind .*/bind 127.0.0.1 -::1/' /etc/redis/redis.conf
else
    echo 'bind 127.0.0.1 -::1' >> /etc/redis/redis.conf
fi
sed -i -E 's/^protected-mode .*/protected-mode yes/' /etc/redis/redis.conf
if grep -qE '^requirepass ' /etc/redis/redis.conf; then
    sed -i -E "s/^requirepass .*/requirepass ${REDIS_PASSWORD}/" /etc/redis/redis.conf
else
    echo "requirepass ${REDIS_PASSWORD}" >> /etc/redis/redis.conf
fi
systemctl enable --now redis-server >/dev/null
systemctl restart redis-server

log "PostgreSQL ${PG_VERSION}: least-privilege roles and database"
systemctl enable --now postgresql >/dev/null
OWNER_PASSWORD=$(secret /etc/darak/db-owner.pass)
APP_PASSWORD=$(secret /etc/darak/db-app.pass)
chgrp "$APP_USER" /etc/darak/db-app.pass /etc/darak/db-owner.pass
chmod 0640 /etc/darak/db-app.pass /etc/darak/db-owner.pass

psql_admin() { sudo -u postgres psql -X -q -v ON_ERROR_STOP=1 "$@"; }

psql_admin -d postgres \
    -v owner_role="$DB_OWNER_ROLE" -v app_role="$DB_APP_ROLE" \
    -v owner_password="$OWNER_PASSWORD" -v app_password="$APP_PASSWORD" <<'SQL'
SELECT format('CREATE ROLE %I LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION', :'owner_role')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'owner_role') \gexec
SELECT format('CREATE ROLE %I LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION BYPASSRLS', :'app_role')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'app_role') \gexec
SELECT format('ALTER ROLE %I WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD %L', :'owner_role', :'owner_password') \gexec
SELECT format('ALTER ROLE %I WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION BYPASSRLS PASSWORD %L', :'app_role', :'app_password') \gexec
SQL

if [[ -z $(psql_admin -d postgres -tA -v db="$DB_NAME" <<<"SELECT 1 FROM pg_database WHERE datname = :'db'") ]]; then
    sudo -u postgres createdb --owner="$DB_OWNER_ROLE" --encoding=UTF8 --template=template0 "$DB_NAME"
fi

psql_admin -d "$DB_NAME" -v db="$DB_NAME" -v owner_role="$DB_OWNER_ROLE" -v app_role="$DB_APP_ROLE" <<'SQL'
SELECT format('ALTER DATABASE %I OWNER TO %I', :'db', :'owner_role') \gexec
SELECT format('REVOKE ALL ON DATABASE %I FROM PUBLIC', :'db') \gexec
SELECT format('GRANT CONNECT, TEMPORARY ON DATABASE %I TO %I', :'db', :'app_role') \gexec
SELECT format('ALTER SCHEMA public OWNER TO %I', :'owner_role') \gexec
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
SELECT format('GRANT USAGE ON SCHEMA public TO %I', :'app_role') \gexec
-- Tables created later by migrations (as the owner) are usable by the app role.
SELECT format('ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO %I', :'owner_role', :'app_role') \gexec
SELECT format('ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO %I', :'owner_role', :'app_role') \gexec
SELECT format('ALTER DEFAULT PRIVILEGES FOR ROLE %I IN SCHEMA public GRANT EXECUTE ON FUNCTIONS TO %I', :'owner_role', :'app_role') \gexec
SQL

if [[ ! -f /etc/darak/migrate.env ]]; then
    (umask 077 && printf 'DB_USERNAME=%s\nDB_PASSWORD=%s\n' "$DB_OWNER_ROLE" "$OWNER_PASSWORD" > /etc/darak/migrate.env)
fi
chown root:"$APP_USER" /etc/darak/migrate.env
chmod 0640 /etc/darak/migrate.env

log "PHP-FPM pool and php.ini overrides"
render "$SCRIPT_DIR/php-fpm/darak.conf" "/etc/php/${PHP_VERSION}/fpm/pool.d/darak.conf" 0644
cat > "/etc/php/${PHP_VERSION}/fpm/conf.d/99-darak.ini" <<'INI'
expose_php = Off
display_errors = Off
log_errors = On
opcache.enable = 1
opcache.memory_consumption = 192
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 1
opcache.revalidate_freq = 2
INI
touch "/var/log/php${PHP_VERSION}-fpm-darak-slow.log"
"php-fpm${PHP_VERSION}" -t
systemctl enable --now "php${PHP_VERSION}-fpm" >/dev/null
systemctl reload-or-restart "php${PHP_VERSION}-fpm"

log "deploy.sh may reload PHP-FPM without a password (and nothing else)"
cat > /etc/sudoers.d/darak <<SUDO
${APP_USER} ALL=(root) NOPASSWD: /usr/bin/systemctl reload php${PHP_VERSION}-fpm
SUDO
chmod 0440 /etc/sudoers.d/darak
visudo -cq -f /etc/sudoers.d/darak

log "Nginx and TLS for ${DOMAIN}"
rm -f /etc/nginx/sites-enabled/default
if [[ ! -f /etc/letsencrypt/live/${DOMAIN}/fullchain.pem ]]; then
    # HTTP-only bootstrap so certbot can answer the ACME challenge.
    cat > /etc/nginx/sites-available/darak-acme.conf <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};
    location ^~ /.well-known/acme-challenge/ { root /var/www/letsencrypt; default_type text/plain; }
    location / { return 404; }
}
NGINX
    ln -sf /etc/nginx/sites-available/darak-acme.conf /etc/nginx/sites-enabled/darak-acme.conf
    nginx -t && systemctl reload-or-restart nginx
    if [[ -n $ADMIN_EMAIL ]]; then
        certbot certonly --webroot -w /var/www/letsencrypt -d "$DOMAIN" \
            --email "$ADMIN_EMAIL" --agree-tos --non-interactive \
            --deploy-hook 'systemctl reload nginx' \
            || warn "certbot failed: point ${DOMAIN} at this server and re-run."
    else
        warn "DARAK_ADMIN_EMAIL not set: no certificate requested. Re-run with it once DNS points here."
    fi
fi
if [[ -f /etc/letsencrypt/live/${DOMAIN}/fullchain.pem ]]; then
    render "$SCRIPT_DIR/nginx/darak.conf" /etc/nginx/sites-available/darak.conf 0644
    ln -sf /etc/nginx/sites-available/darak.conf /etc/nginx/sites-enabled/darak.conf
    rm -f /etc/nginx/sites-enabled/darak-acme.conf
fi
nginx -t
systemctl enable --now nginx >/dev/null
systemctl reload-or-restart nginx

log "Scheduler cron and log rotation"
render "$SCRIPT_DIR/cron/darak" /etc/cron.d/darak 0644
render "$SCRIPT_DIR/logrotate/darak" /etc/logrotate.d/darak 0644
logrotate --debug /etc/logrotate.d/darak >/dev/null 2>&1 || warn "logrotate config check failed"

log "Done"
cat <<DONE

Provisioned ${DOMAIN}. Next steps (DEPLOYMENT.md §2):
  1. As ${APP_USER}: create ${APP_ROOT}/backend/.env from .env.example with
       DB_USERNAME=${DB_APP_ROLE}  DB_PASSWORD=\$(cat /etc/darak/db-app.pass)  DB_SSLMODE=require
       REDIS_PASSWORD=\$(cat /etc/darak/redis.pass)  DARAK_BACKUP_PATH=${BACKUP_DIR}
     and generate APP_KEY, DARAK_BACKUP_PASSWORD and DARAK_AUDIT_HMAC_KEY (openssl rand -hex 32).
     Copy those three secrets to the owner's secrets manager NOW.
  2. As ${APP_USER}: rclone config  (off-site remote), then set DARAK_BACKUP_RCLONE_REMOTE.
  3. sudo -u ${APP_USER} ${APP_ROOT}/deploy/deploy.sh <release-tag>
DONE
