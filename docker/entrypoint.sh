#!/bin/sh
set -e

# ── Env Validation ────────────────────────────────────
# Refuse to start if critical secrets are missing.
echo "[entrypoint] Validating environment..."
missing=""
[ -z "$APP_KEY" ]              && missing="$missing APP_KEY"
[ -z "$DB_PASSWORD" ]          && missing="$missing DB_PASSWORD"
[ -z "$DB_DATABASE" ]          && missing="$missing DB_DATABASE"
[ -z "$DB_USERNAME" ]          && missing="$missing DB_USERNAME"
[ -z "$PROXY_SHARED_SECRET" ]  && missing="$missing PROXY_SHARED_SECRET"

if [ -n "$missing" ]; then
    echo "[entrypoint] FATAL: Missing required env vars:$missing"
    echo "[entrypoint] Set these in Coolify → Environment Variables, then redeploy."
    exit 1
fi

# ── Storage Directories ──────────────────────────────
# These must exist before any artisan command runs.
echo "[entrypoint] Ensuring storage directories..."
mkdir -p /app/storage/framework/sessions
mkdir -p /app/storage/framework/views
mkdir -p /app/storage/framework/cache/data
mkdir -p /app/storage/logs
mkdir -p /app/bootstrap/cache
chown -R www-data:www-data /app/storage /app/bootstrap/cache
chmod -R 775 /app/storage /app/bootstrap/cache

# ── Wait for MySQL ───────────────────────────────────
# PDO connection check — no artisan dependency, works on bare PHP.
echo "[entrypoint] Waiting for MySQL..."
for i in $(seq 1 60); do
    if php -r "try { new PDO('mysql:host=${DB_HOST:-mysql};port=3306;dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}'); echo 'ok'; exit(0); } catch(Exception \$e) { exit(1); }" 2>/dev/null; then
        echo "[entrypoint] MySQL is ready."
        break
    fi
    if [ "$i" -eq 60 ]; then
        echo "[entrypoint] FATAL: MySQL not ready after 60s. Check mysql container logs."
        exit 1
    fi
    sleep 1
done

# ── Pre-Boot Safety Checks (Layer 1) ─────────────────
echo "[entrypoint] Running PHP syntax check to prevent boot-time crashes..."
# Exclude vendor and focus on user-edited code
find /app/app -name "*.php" -print0 | xargs -0 -n1 php -l > /dev/null || { echo "[entrypoint] FATAL: Syntax error detected! Aborting deploy to protect proxy uptime."; exit 1; }

echo "[entrypoint] Dry-running migrations to catch structural errors..."
timeout 60 php artisan migrate --pretend --force || { echo "[entrypoint] FATAL: Migration dry-run failed or timed out! Aborting deploy to protect database state."; exit 1; }

# ── Migrations ───────────────────────────────────────
echo "[entrypoint] Running migrations (with 5m timeout)..."
timeout 300 php artisan migrate --force --no-interaction || { echo "[entrypoint] FATAL: Migrations failed or timed out. Possible DB lock."; exit 1; }

# ── Storage Link ─────────────────────────────────────
# Idempotent — skips if link already exists.
echo "[entrypoint] Creating storage link..."
php artisan storage:link --force --no-interaction 2>/dev/null || true

# ── Cache Warming ────────────────────────────────────
echo "[entrypoint] Caching config, routes, and views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# ── Livewire Assets ──────────────────────────────────
# Publish compiled assets to prevent 404s after deploys.
echo "[entrypoint] Publishing Livewire assets..."
php artisan livewire:publish --assets --no-interaction 2>/dev/null || echo "[entrypoint] livewire:publish skipped (not installed)"

# ── Proxy Cache Invalidation ─────────────────────────
# Clear Laravel's cached proxy configs so the Node proxy gets fresh data
# on next request. We do NOT bump config_version here — that should only
# happen when actual config changes occur (via DomainObserver).
# The full proxy:flush-cache command is available for manual use when
# environment variables change and versions need bumping.
echo "[entrypoint] Clearing proxy config cache (no version bump)..."
php artisan cache:clear 2>/dev/null || echo "[entrypoint] cache:clear skipped"

# ── Install Laravel Scheduler Crontab ────────────────
# crond runs via supervisord but needs the crontab loaded.
if [ -f /app/docker/scheduler-crontab ]; then
    crontab /app/docker/scheduler-crontab
    echo "[entrypoint] ✅ Laravel scheduler crontab installed."
else
    echo "[entrypoint] ⚠️  scheduler-crontab not found — scheduler will not run."
fi

# ── Docker Socket Permission (Server Cleanup feature) ────────
# Match the Docker socket's GID so www-data can run docker commands.
if [ -S /var/run/docker.sock ]; then
    DOCKER_GID=$(stat -c '%g' /var/run/docker.sock 2>/dev/null || echo "")
    if [ -n "$DOCKER_GID" ] && [ "$DOCKER_GID" != "0" ]; then
        # Create/update a docker group with the correct GID and add www-data
        delgroup docker 2>/dev/null || true
        addgroup -g "$DOCKER_GID" docker 2>/dev/null || true
        addgroup www-data docker 2>/dev/null || true
        echo "[entrypoint] ✅ www-data added to docker group (GID $DOCKER_GID)"
    else
        echo "[entrypoint] ⚠️  Docker socket GID is root — www-data may not have access"
    fi
else
    echo "[entrypoint] ℹ️  No Docker socket mounted — server cleanup commands will use SSH/API fallback"
fi

echo "[entrypoint] ✅ All startup tasks complete."

# If arguments are passed (e.g. from docker-compose command), run those
# instead of supervisord. This allows the queue-worker service to share
# the same bootstrap sequence but run a different final process.
if [ "$#" -gt 0 ]; then
    echo "[entrypoint] Running custom command: $@"
    exec "$@"
else
    echo "[entrypoint] Starting supervisord..."
    exec supervisord -c /etc/supervisord.conf
fi
