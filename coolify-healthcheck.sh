#!/usr/bin/env bash
set -euo pipefail

# ==============================================================================
# coolify-healthcheck.sh — YCookies Health Checker
# ==============================================================================
# Run AFTER coolify-installer.sh to verify both apps are healthy.
# Usage: COOLIFY_API_TOKEN='...' bash coolify-healthcheck.sh
# ==============================================================================

COOLIFY_HOST="${COOLIFY_HOST:-https://coolify.revyome.com}"
COOLIFY_API_TOKEN="${COOLIFY_API_TOKEN:?❌ Set COOLIFY_API_TOKEN env var first}"
ROUTE_DOMAIN="${1:-cookies.ypsilon.dev}"
PREFIX="${2:-ycookies}"
# Proxy may be on a different public host than the admin (e.g. customer domain).
# Default: same host as admin. Override in CI: PROXY_HEALTH_URL=https://example.com/health
PROXY_HEALTH_URL="${PROXY_HEALTH_URL:-https://${ROUTE_DOMAIN}/health}"

ADMIN_STACK_NAME="${PREFIX}-admin-${PREFIX}"
PROXY_STACK_NAME="${PREFIX}-proxy-${PREFIX}"

echo ""
echo "════════════════════════════════════════════════════════"
echo "🏥 YCookies Health Checker"
echo "════════════════════════════════════════════════════════"
echo ""

# Simple API GET helper
api_get() {
    local endpoint=$1
    local tmpfile=$(mktemp)
    curl -sS -o "$tmpfile" -w "" -X GET \
         --retry 3 --retry-delay 2 --retry-all-errors \
         -H "Authorization: Bearer $COOLIFY_API_TOKEN" \
         -H "Accept: application/json" \
         "$COOLIFY_HOST/api/v1/$endpoint" 2>/dev/null || true
    local response=$(cat "$tmpfile")
    response="${response//$'\r'/}"
    rm -f "$tmpfile"
    echo "$response"
}

ALL_APPS=$(api_get "applications")
ADMIN_UUID=$(echo "$ALL_APPS" | jq -r --arg name "$ADMIN_STACK_NAME" '.[] | select(.name == $name) | .uuid // empty' | tr -d '\r\n')
PROXY_UUID=$(echo "$ALL_APPS" | jq -r --arg name "$PROXY_STACK_NAME" '.[] | select(.name == $name) | .uuid // empty' | tr -d '\r\n')

if [[ -z "$ADMIN_UUID" ]]; then echo "❌ Admin app '$ADMIN_STACK_NAME' not found."; exit 1; fi
if [[ -z "$PROXY_UUID" ]]; then echo "❌ Proxy app '$PROXY_STACK_NAME' not found."; exit 1; fi

echo "   Admin: $ADMIN_STACK_NAME ($ADMIN_UUID)"
echo "   Proxy: $PROXY_STACK_NAME ($PROXY_UUID)"
echo ""

# Check Coolify app status
check_status() {
    local uuid=$1
    local data=$(api_get "applications/$uuid")
    echo "$data" | jq -r '.status // "unknown"'
}

# Check HTTP health endpoint
check_health() {
    local url=$1
    curl -s -o /dev/null -w "%{http_code}" --connect-timeout 5 --max-time 10 "$url" 2>/dev/null || echo "000"
}

# Admin checks
echo "▶ Admin App"
ADMIN_STATUS=$(check_status "$ADMIN_UUID")
echo "   Coolify status: $ADMIN_STATUS"
ADMIN_HEALTH=$(check_health "https://$ROUTE_DOMAIN/up")
if [[ "$ADMIN_HEALTH" == "200" ]]; then
    echo "   Health check:   ✅ HTTP $ADMIN_HEALTH"
else
    echo "   Health check:   ❌ HTTP $ADMIN_HEALTH"
fi
echo ""

# Proxy checks
echo "▶ Proxy App"
PROXY_STATUS=$(check_status "$PROXY_UUID")
echo "   Coolify status: $PROXY_STATUS"
PROXY_HEALTH=$(check_health "$PROXY_HEALTH_URL")
if [[ "$PROXY_HEALTH" == "200" ]]; then
    echo "   Health check:   ✅ HTTP $PROXY_HEALTH"
else
    echo "   Health check:   ❌ HTTP $PROXY_HEALTH"
fi
echo ""

# Shared secret check
echo "▶ Shared Secret"
ADMIN_ENVS=$(api_get "applications/$ADMIN_UUID/envs")
SHARED_SECRET=$(echo "$ADMIN_ENVS" | jq -r '[.[] | select(.key == "SERVICE_BASE64_64_PROXY")] | first | .value // empty' | tr -d '\r')
if [[ -n "$SHARED_SECRET" && "$SHARED_SECRET" != "null" ]]; then
    echo "   ✅ Set (${SHARED_SECRET:0:8}...)"
else
    echo "   ❌ Not found"
fi
echo ""

# Summary
if [[ "$ADMIN_HEALTH" == "200" && "$PROXY_HEALTH" == "200" ]]; then
    echo "✅ All healthy!"
elif [[ "$ADMIN_HEALTH" == "200" ]]; then
    echo "⚠️  Admin healthy, proxy not ready yet. Run again in a few minutes."
else
    echo "❌ Admin not healthy. Check deployments at:"
    echo "   $COOLIFY_HOST → Projects → ycookies"
fi
echo ""
echo "════════════════════════════════════════════════════════"

# CI / automation: fail the process when checks are incomplete
if [[ "${COOLIFY_HEALTHCHECK_STRICT:-}" == "1" ]]; then
    if [[ "$ADMIN_HEALTH" != "200" ]]; then
        echo "❌ Strict mode: admin health must be HTTP 200 (got $ADMIN_HEALTH)"
        exit 1
    fi
    if [[ "$PROXY_HEALTH" != "200" ]]; then
        echo "❌ Strict mode: proxy health must be HTTP 200 (got $PROXY_HEALTH) at $PROXY_HEALTH_URL"
        exit 1
    fi
    if [[ -z "$SHARED_SECRET" || "$SHARED_SECRET" == "null" ]]; then
        echo "❌ Strict mode: SERVICE_BASE64_64_PROXY not found on admin app envs"
        exit 1
    fi
fi
