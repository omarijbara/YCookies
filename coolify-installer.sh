#!/usr/bin/env bash
set -euo pipefail

INSTALL_START=$(date +%s)

# ── Logging helpers ──────────────────────────────────────────────────────────
log_section() {
    SECTION_START=$(date +%s)
    echo ""
    echo "▶ $1"
}

log_elapsed() {
    local now=$(date +%s)
    local elapsed=$((now - SECTION_START))
    echo "   ⏱  Section took ${elapsed}s"
}

mask() {
    local val="$1" show="${2:-4}"
    if [[ ${#val} -le $((show * 2)) ]]; then
        echo "***"
    else
        echo "${val:0:$show}...${val: -$show} (${#val} chars)"
    fi
}

# ==============================================================================
# YCookies Zero-Touch Coolify Installer (Split Architecture)
# ==============================================================================
# Deploys YCookies as TWO independent Coolify apps:
#   1. ycookies-admin  → Laravel, queue, MySQL, Redis, GlitchTip, Loki
#   2. ycookies-proxy  → Node-proxy only (independent lifecycle)
#
# Admin deploys never restart the proxy. Proxied domains stay live.
#
# Interactive:     ./coolify-installer.sh
# Non-interactive: ./coolify-installer.sh --domain cookies.ypsilon.dev --prefix ycookies
#
# Requirements: curl, jq, openssl
#
# Idempotency:
# - Reuses existing apps by name (no duplicates).
# - Preserves APP_KEY on reruns.
# - Coolify magic vars handle DB_PASSWORD + PROXY_SHARED_SECRET.
# ==============================================================================

# ── YCookies defaults (override with flags) ──────────────────────────────────
COOLIFY_HOST="${COOLIFY_HOST:-https://coolify.revyome.com}"
GIT_BRANCH="main"

# ── Dependency check ─────────────────────────────────────────────────────────
MISSING=""
for cmd in curl jq openssl; do
    if ! command -v "$cmd" &>/dev/null; then
        MISSING="$MISSING $cmd"
    fi
done
if [[ -n "$MISSING" ]]; then
    echo "❌ Missing required tools:$MISSING"
    echo ""
    echo "Install them:"
    echo "  Ubuntu/Debian:  sudo apt install -y curl jq openssl"
    echo "  macOS:          brew install curl jq openssl"
    echo "  Windows:        choco install curl jq openssl"
    echo "                  Or run this script in WSL instead of Git Bash"
    exit 1
fi

# ── Non-interactive detection ─────────────────────────────────────────────
# Auto-detect: if stdin is not a terminal, skip all prompts and use defaults.
if [[ -t 0 ]]; then
    IS_INTERACTIVE=true
else
    IS_INTERACTIVE=false
fi

is_interactive() { [[ "$IS_INTERACTIVE" == "true" ]]; }

# Parse flags (all optional — defaults below work for YCookies)
INSTALLER_MODE="install"  # install | verify | cleanup
ROUTE_DOMAIN="cookies.ypsilon.dev"
TRAEFIK_PREFIX="ycookies"
GLITCHTIP_DOMAIN="sentry.ypsilon.dev"
GLITCHTIP_API_TOKEN=""
GLITCHTIP_TOKEN_FROM_FLAG=true  # Skip token prompt when defaults are baked in
GLITCHTIP_ORG_SLUG="default"
PROJECT_UUID=""  # Always prompt — projects may be created/deleted
PROJECT_NAME="ycookies"  # Default project name for auto-create
SERVER_UUID="kcwsok0kk88kwc8cs8s48sow"
DESTINATION_UUID="c48ggccg8c8gcosk48wc0080"
GITHUB_APP_UUID="ry33yy1dsftaprkou4pio9dt"
GITHUB_APP_NAME=""
PRIVATE_KEY_UUID=""

while [[ "$#" -gt 0 ]]; do
    case $1 in
        --domain) ROUTE_DOMAIN="$2"; shift ;;
        --prefix) TRAEFIK_PREFIX="$2"; shift ;;
        --project) PROJECT_UUID="$2"; shift ;;
        --project-name) PROJECT_NAME="$2"; shift ;;
        --server) SERVER_UUID="$2"; shift ;;
        --destination) DESTINATION_UUID="$2"; shift ;;
        --github-app) GITHUB_APP_UUID="$2"; shift ;;
        --private-key) PRIVATE_KEY_UUID="$2"; shift ;;
        --glitchtip-domain) GLITCHTIP_DOMAIN="$2"; shift ;;
        --glitchtip-token) GLITCHTIP_API_TOKEN="$2"; GLITCHTIP_TOKEN_FROM_FLAG=true; shift ;;
        --glitchtip-org) GLITCHTIP_ORG_SLUG="$2"; shift ;;
        --host) COOLIFY_HOST="$2"; shift ;;
        --verify) INSTALLER_MODE="verify" ;;
        --cleanup) INSTALLER_MODE="cleanup" ;;
        --help|-h) 
            echo "Usage: ./coolify-installer.sh [MODE] [OPTIONS]"
            echo ""
            echo "Deploys YCookies as TWO independent Coolify apps (admin + proxy)."
            echo "Run with no flags for interactive mode (prompts for everything)."
            echo ""
            echo "Modes:"
            echo "  (default)    Install / deploy both apps"
            echo "  --verify     Check health, env vars, and connectivity (no deploy)"
            echo "  --cleanup    Delete both apps for a fresh start"
            echo ""
            echo "Options (all optional — prompted if missing):"
            echo "  --domain             Primary domain (e.g. cookies.ypsilon.dev)"
            echo "  --prefix             Unique Traefik prefix (e.g. ycookies)"
            echo "  --glitchtip-domain   GlitchTip subdomain (e.g. sentry.revyome.com)"
            echo "  --glitchtip-token    GlitchTip API token (skip prompt)"
            echo "  --glitchtip-org      GlitchTip org slug (default: 'default')"
            echo "  --host               Coolify instance URL"
            echo ""
            echo "Advanced overrides (auto-discovered if not provided):"
            echo "  --project       Coolify Project UUID"
            echo "  --server        Coolify Server UUID"
            echo "  --destination   Coolify Destination UUID"
            echo "  --github-app    Coolify GitHub App UUID"
            echo "  --private-key   Coolify Deploy Key UUID"
            echo ""
            echo "Environment:"
            echo "  COOLIFY_API_TOKEN    Required. Coolify API token."
            echo ""
            echo "Examples:"
            echo "  # Full deploy (non-interactive)"
            echo "  COOLIFY_API_TOKEN='...' ./coolify-installer.sh --domain cookies.ypsilon.dev --prefix ycookies"
            echo ""
            echo "  # Verify health of existing deployment"
            echo "  COOLIFY_API_TOKEN='...' ./coolify-installer.sh --verify --prefix ycookies"
            echo ""
            echo "  # Delete both apps and start fresh"
            echo "  COOLIFY_API_TOKEN='...' ./coolify-installer.sh --cleanup --prefix ycookies"
            exit 0
            ;;
        *) echo "Unknown flag: $1. Use --help for usage."; exit 1 ;;
    esac
    shift
done

# ── Interactive prompts for missing values ───────────────────────────────────

echo ""
echo "════════════════════════════════════════════════════════"
echo "🍪 YCookies Installer (Split Architecture)"
echo "════════════════════════════════════════════════════════"
echo ""
echo "   This installer creates TWO independent Coolify apps:"
echo "   • ycookies-admin  → Laravel, MySQL, Redis, GlitchTip"
echo "   • ycookies-proxy  → Node proxy (never touched by admin deploys)"
echo ""

# Coolify URL
if [[ -z "$COOLIFY_HOST" ]]; then
    if is_interactive; then
        read -rp "🌐 Coolify URL (e.g. https://coolify.example.com): " COOLIFY_HOST
    else
        echo "❌ COOLIFY_HOST not set. Pass --host or set env var."; exit 1
    fi
fi
# Strip trailing slash
COOLIFY_HOST="${COOLIFY_HOST%/}"

if [[ -z "$COOLIFY_HOST" ]]; then
    echo "❌ Coolify URL is required."
    exit 1
fi

# API Token
if [[ -z "${COOLIFY_API_TOKEN:-}" ]]; then
    if is_interactive; then
        echo ""
        echo "   Find your API token in Coolify → Settings → Keys & Tokens → API tokens"
        read -rsp "🔑 Coolify API Token: " COOLIFY_API_TOKEN
        echo ""
    else
        echo "❌ COOLIFY_API_TOKEN not set. Export it before running."; exit 1
    fi
fi

if [[ -z "$COOLIFY_API_TOKEN" ]]; then
    echo "❌ API token is required."
    exit 1
fi

# Domain
if [[ -z "$ROUTE_DOMAIN" ]]; then
    if is_interactive; then
        echo ""
        read -rp "🌍 Domain for YCookies admin (e.g. cookies.ypsilon.dev): " ROUTE_DOMAIN
    else
        echo "❌ Domain not set. Pass --domain."; exit 1
    fi
fi

if [[ -z "$ROUTE_DOMAIN" ]]; then
    echo "❌ Domain is required."
    exit 1
fi

# Prefix
if [[ -z "$TRAEFIK_PREFIX" ]]; then
    if is_interactive; then
        echo ""
        read -rp "📛 Traefik prefix (short unique name, e.g. ycookies): " TRAEFIK_PREFIX
    else
        echo "❌ Prefix not set. Pass --prefix."; exit 1
    fi
fi

if [[ -z "$TRAEFIK_PREFIX" ]]; then
    echo "❌ Prefix is required."
    exit 1
fi

# Sanitize prefix — strip anything not alphanumeric or hyphen
CLEAN_PREFIX=$(echo "$TRAEFIK_PREFIX" | sed 's/[^a-zA-Z0-9-]//g' | sed 's/^-//;s/-$//')
if [[ "$CLEAN_PREFIX" != "$TRAEFIK_PREFIX" ]]; then
    echo "   ⚠️  Prefix sanitized: '$TRAEFIK_PREFIX' → '$CLEAN_PREFIX'"
    TRAEFIK_PREFIX="$CLEAN_PREFIX"
fi

if [[ -z "$TRAEFIK_PREFIX" ]]; then
    echo "❌ Prefix is empty after sanitization."
    exit 1
fi

# GlitchTip domain (optional)
if [[ -z "$GLITCHTIP_DOMAIN" ]]; then
    if is_interactive; then
        echo ""
        read -rp "🐛 GlitchTip domain (e.g. sentry.revyome.com, or Enter to skip): " GLITCHTIP_DOMAIN
    fi
fi
if [[ -n "$GLITCHTIP_DOMAIN" ]]; then
    echo "   ✅ GlitchTip: $GLITCHTIP_DOMAIN"
    # Only prompt interactively if not already set via flags
    if [[ -z "$GLITCHTIP_API_TOKEN" && "$GLITCHTIP_TOKEN_FROM_FLAG" == "false" && -t 0 ]]; then
        echo ""
        echo "   GlitchTip API token (for admin Error Tracker integration)"
        echo "   Generate one at: https://$GLITCHTIP_DOMAIN → Settings → API Keys"
        echo "   Leave blank to configure later."
        read -rsp "   🔑 GlitchTip API Token (or Enter to skip): " GLITCHTIP_API_TOKEN
        echo ""
    fi
    if [[ -n "$GLITCHTIP_API_TOKEN" ]]; then
        echo "   ✅ GlitchTip API token set."
    else
        echo "   ⏭️  GlitchTip API token skipped (configure later in Coolify env vars)"
    fi
    if [[ -z "$GLITCHTIP_ORG_SLUG" ]]; then
        if [[ -t 0 ]]; then
            read -rp "   📁 GlitchTip org slug (default: 'default'): " GLITCHTIP_ORG_SLUG_INPUT
            GLITCHTIP_ORG_SLUG="${GLITCHTIP_ORG_SLUG_INPUT:-default}"
        else
            GLITCHTIP_ORG_SLUG="default"
        fi
    fi
    GLITCHTIP_ORG_SLUG="${GLITCHTIP_ORG_SLUG:-default}"
else
    echo "   ⏭️  GlitchTip skipped (can be configured later)"
    GLITCHTIP_API_TOKEN="${GLITCHTIP_API_TOKEN:-}"
    GLITCHTIP_ORG_SLUG="${GLITCHTIP_ORG_SLUG:-default}"
fi

echo ""

ADMIN_STACK_NAME="ycookies-admin-${TRAEFIK_PREFIX}"
PROXY_STACK_NAME="ycookies-proxy-${TRAEFIK_PREFIX}"

# ── Helpers ──────────────────────────────────────────────────────────────────

function api_request() {
    local method=$1
    local endpoint=$2
    local payload=${3:-}
    local tmpfile http_code response

    # Strip \r from endpoint — Windows curl/API responses can embed CRLF
    # which corrupts URLs when building from prior API response values
    endpoint="${endpoint//$'\r'/}"

    tmpfile=$(mktemp)

    local curl_exit=0
    if [[ -z "$payload" ]]; then
        http_code=$(curl -sS -o "$tmpfile" -w "%{http_code}" -X "$method" \
             --retry 3 --retry-delay 2 --retry-all-errors \
             -H "Authorization: Bearer $COOLIFY_API_TOKEN" \
             -H "Content-Type: application/json" \
             -H "Accept: application/json" \
             "$COOLIFY_HOST/api/v1/$endpoint") || curl_exit=$?
    else
        # Write payload to temp file to avoid Git Bash mangling multi-line JSON
        local payload_file
        payload_file=$(mktemp)
        printf '%s' "$payload" > "$payload_file"
        http_code=$(curl -sS -o "$tmpfile" -w "%{http_code}" -X "$method" \
             --retry 3 --retry-delay 2 --retry-all-errors \
             -H "Authorization: Bearer $COOLIFY_API_TOKEN" \
             -H "Content-Type: application/json" \
             -H "Accept: application/json" \
             --data-binary "@$payload_file" \
             "$COOLIFY_HOST/api/v1/$endpoint") || curl_exit=$?
        rm -f "$payload_file"
    fi

    response=$(cat "$tmpfile")
    rm -f "$tmpfile"

    # Strip \r from response — prevents CRLF from corrupting jq parsing
    # and downstream variable usage (UUIDs, env values, etc.)
    response="${response//$'\r'/}"

    if [[ "$curl_exit" -ne 0 ]]; then
        echo "❌ curl failed (exit $curl_exit): $method /api/v1/$endpoint" >&2
        echo "   Response: $response" >&2
        exit 1
    fi

    if [[ ! "$http_code" =~ ^2 ]]; then
        echo "❌ API failed: $method /api/v1/$endpoint (HTTP $http_code)" >&2
        echo "   $response" >&2
        exit 1
    fi

    echo "$response"
}

# Picks the first (or only) item from an API list. Fails if zero results.
# Status messages go to stderr. Only the UUID goes to stdout.
function auto_pick() {
    local label=$1
    local endpoint=$2
    local uuid_field=${3:-.uuid}
    local name_field=${4:-.name}

    local data
    data=$(api_request "GET" "$endpoint" "")

    local count
    count=$(echo "$data" | jq 'if type == "array" then length else 0 end')

    if [[ "$count" -eq 0 ]]; then
        echo "❌ No $label found in Coolify. Create one first." >&2
        exit 1
    fi

    local uuid name
    uuid=$(echo "$data" | jq -r ".[0] | $uuid_field // empty")
    name=$(echo "$data" | jq -r ".[0] | $name_field // \"unnamed\"")

    if [[ "$count" -gt 1 ]]; then
        echo "   ⚠️  Multiple ${label}s found ($count). Using first: \"$name\" ($uuid)" >&2
        echo "      Override with the corresponding flag if this is wrong." >&2
    else
        echo "   ✅ $label: \"$name\" ($uuid)" >&2
    fi

    echo "$uuid"
}

# Creates or reuses a Coolify docker-compose app.
# Usage: create_or_reuse_app <stack_name> <compose_file> <description>
# Returns: APP_UUID via stdout
function create_or_reuse_app() {
    local stack_name=$1
    local compose_file=$2
    local description=$3

    local existing_uuid
    existing_uuid=$(
        api_request "GET" "applications" "" \
        | jq -r --arg name "$stack_name" '.[] | select(.name == $name) | .uuid // empty' 2>/dev/null \
        || echo ""
    )

    if [[ -n "$existing_uuid" && "$existing_uuid" != "null" ]]; then
        echo "   ♻️  Found existing app '$stack_name' (UUID: $existing_uuid). Reusing." >&2
        echo "$existing_uuid"
        return 0
    fi

    echo "   Creating new app '$stack_name'..." >&2

    local payload
    payload=$(jq -n \
        --arg proj "$PROJECT_UUID" \
        --arg serv "$SERVER_UUID" \
        --arg dest "$DESTINATION_UUID" \
        --arg ghapp "$GITHUB_APP_UUID" \
        --arg branch "$GIT_BRANCH" \
        --arg sname "$stack_name" \
        --arg desc "$description" \
        '{
            project_uuid: $proj,
            server_uuid: $serv,
            environment_name: "production",
            destination_uuid: $dest,
            github_app_uuid: $ghapp,
            git_repository: "omarijbara/YCookies",
            git_branch: $branch,
            build_pack: "dockercompose",
            ports_exposes: "80",
            name: $sname,
            description: $desc,
            instant_deploy: false
        }'
    )

    # Try to create — handle repo-not-accessible gracefully
    local tmpfile http_code app_body
    tmpfile=$(mktemp)
    # Write payload to temp file to avoid Git Bash mangling multi-line JSON
    local payload_file
    payload_file=$(mktemp)
    printf '%s' "$payload" > "$payload_file"
    http_code=$(curl -sS -o "$tmpfile" -w "%{http_code}" -X POST \
         --retry 3 --retry-delay 2 --retry-all-errors \
         -H "Authorization: Bearer $COOLIFY_API_TOKEN" \
         -H "Content-Type: application/json" \
         -H "Accept: application/json" \
         --data-binary "@$payload_file" \
         "$COOLIFY_HOST/api/v1/applications/private-github-app")
    app_body=$(cat "$tmpfile")
    app_body="${app_body//$'\r'/}"  # Strip CRLF (Windows curl compat)
    rm -f "$tmpfile" "$payload_file"

    # Check for repo-not-accessible error
    if echo "$app_body" | grep -qi "not found or not accessible\|not accessible by the GitHub App"; then
        echo "" >&2
        echo "   ⚠️  The GitHub App \"$GITHUB_APP_NAME\" doesn't have access to the repo." >&2
        echo "" >&2
        echo "   Fix it now (takes 30 seconds):" >&2
        echo "      1. Go to: https://github.com/settings/installations" >&2
        echo "      2. Find \"$GITHUB_APP_NAME\" → click Configure" >&2
        echo "      3. Under 'Repository access', add omarijbara/YCookies" >&2
        echo "      4. Click Save" >&2
        echo "" >&2

        open_browser "https://github.com/settings/installations"
        read -rp "   Press Enter after granting access... "

        echo "   Retrying..." >&2
        app_body=$(api_request "POST" "applications/private-github-app" "$payload")
    elif [[ ! "$http_code" =~ ^2 ]]; then
        echo "❌ API failed: POST /api/v1/applications/private-github-app (HTTP $http_code)" >&2
        echo "   $app_body" >&2
        exit 1
    fi

    local app_uuid
    app_uuid=$(echo "$app_body" | jq -r '.uuid // empty')
    app_uuid="${app_uuid//$'\r'/}"  # Strip CRLF

    if [[ -z "$app_uuid" || "$app_uuid" == "null" ]]; then
        echo "❌ Failed to create app '$stack_name'. No UUID returned." >&2
        exit 1
    fi

    # Set docker_compose_location via PATCH (not accepted on POST)
    echo "   Setting compose file location: /$compose_file" >&2
    # MSYS_NO_PATHCONV=1 scoped to this jq call only — prevents Git Bash
    # from converting /coolify/admin/docker-compose.yaml to C:/Program Files/Git/...
    # Global MSYS_NO_PATHCONV breaks curl tmpfile reads, so we scope it here.
    patch_payload=$(MSYS_NO_PATHCONV=1 jq -n --arg loc "/$compose_file" '{docker_compose_location: $loc}')
    api_request "PATCH" "applications/$app_uuid" "$patch_payload" > /dev/null

    echo "   ✅ Created '$stack_name' (UUID: $app_uuid)" >&2
    echo "$app_uuid"
}

# Deploy an app and wait for compose to load
# Usage: deploy_and_wait <app_uuid> <stack_name>
# Returns: deployment UUID via LAST_DEPLOY_UUID global
LAST_DEPLOY_UUID=""
function deploy_and_wait() {
    local app_uuid=$1
    local stack_name=$2

    echo "   Triggering deploy for $stack_name..." >&2
    local deploy_resp
    deploy_resp=$(api_request "GET" "deploy?uuid=$app_uuid" "")
    echo "   📬 Deploy response: $(echo "$deploy_resp" | jq -c '.' 2>/dev/null || echo "$deploy_resp" | head -c 200)" >&2

    # Extract deployment UUID from response
    LAST_DEPLOY_UUID=$(echo "$deploy_resp" | jq -r '.deployments[0].deployment_uuid // empty' 2>/dev/null | tr -d '\r\n')
    if [[ -n "$LAST_DEPLOY_UUID" ]]; then
        echo "   📋 Deployment UUID: $LAST_DEPLOY_UUID" >&2
    fi

    # Wait for compose file to be parsed
    local compose_loaded=false
    for i in $(seq 1 20); do
        sleep 10
        local app_data raw_compose current_status
        app_data=$(api_request "GET" "applications/$app_uuid" "" 2>/dev/null) || app_data="{}"
        raw_compose=$(echo "$app_data" | jq -r '.docker_compose_raw // empty') || raw_compose=""
        current_status=$(echo "$app_data" | jq -r '.status // "unknown"')
        if [[ -n "$raw_compose" && "$raw_compose" != "null" ]]; then
            compose_loaded=true
            local compose_lines compose_bytes
            compose_lines=$(echo "$raw_compose" | wc -l)
            compose_bytes=$(echo "$raw_compose" | wc -c)
            echo "   ✅ Compose parsed for $stack_name (${compose_lines} lines)" >&2
            break
        fi
        echo "   ⏳ [$((i*10))s] Waiting for compose to parse ($stack_name)... Status: $current_status" >&2
    done

    if [ "$compose_loaded" = false ]; then
        echo "   ⚠️  Compose not detected after 200s for $stack_name. Continuing anyway..." >&2
    fi
}

# Wait for a deployment to reach terminal state (finished/failed/cancelled)
# Usage: wait_for_deployment <deployment_uuid> <stack_name> [max_minutes]
# Returns: 0 if finished, 1 if failed/timeout
function wait_for_deployment() {
    local deploy_uuid=$1
    local stack_name=$2
    local max_minutes=${3:-12}
    local interval=15
    local max_retries=$(( max_minutes * 60 / interval ))

    if [[ -z "$deploy_uuid" || "$deploy_uuid" == "null" ]]; then
        echo "   ⚠️  No deployment UUID to track. Skipping poll." >&2
        return 0
    fi

    echo "   ⏳ Waiting for deployment $deploy_uuid to finish (max ${max_minutes}m)..." >&2
    local retry=0
    local last_status=""

    while [ $retry -lt $max_retries ]; do
        sleep $interval
        retry=$((retry + 1))

        local dep_data dep_status
        dep_data=$(api_request "GET" "deployments/$deploy_uuid" "" 2>/dev/null) || dep_data="{}"
        dep_status=$(echo "$dep_data" | jq -r '.status // "unknown"' | tr -d '\r\n')

        local elapsed=$(( retry * interval ))
        local elapsed_min=$((elapsed / 60))
        local elapsed_sec=$((elapsed % 60))

        if [[ "$dep_status" != "$last_status" ]]; then
            echo "   ⏳ [${elapsed_min}m${elapsed_sec}s] $stack_name: $dep_status" >&2
            last_status="$dep_status"
        fi

        case "$dep_status" in
            finished)
                echo "   ✅ Deployment finished for $stack_name (${elapsed_min}m${elapsed_sec}s)" >&2
                return 0
                ;;
            failed|cancelled)
                echo "   ❌ Deployment $dep_status for $stack_name after ${elapsed_min}m${elapsed_sec}s" >&2
                return 1
                ;;
            # queued, in_progress — keep waiting
        esac
    done

    echo "   ❌ Deployment timed out for $stack_name after ${max_minutes}m" >&2
    return 1
}

# Poll until health check passes
# Usage: poll_health <app_uuid> <stack_name> <health_url>
function poll_health() {
    local app_uuid=$1
    local stack_name=$2
    local health_url=$3

    echo "" >&2
    echo "   Polling $stack_name → $health_url" >&2
    local max_retries=20  # 20 × 15s = 5 min max per app
    local retry=0
    local health_ok=false
    local health_code="N/A"
    local last_status=""

    while [ $retry -lt $max_retries ]; do
        sleep 15

        local status_resp app_status
        status_resp=$(api_request "GET" "applications/$app_uuid" "" 2>/dev/null) || status_resp="{}"
        app_status=$(echo "$status_resp" | jq -r '.status // "unknown"')

        local elapsed=$(( (retry + 1) * 15 ))
        local elapsed_min=$((elapsed / 60))
        local elapsed_sec=$((elapsed % 60))

        if [[ "$app_status" != "$last_status" ]]; then
            echo "   🔄 $stack_name: $last_status → $app_status" >&2
            last_status="$app_status"
        fi

        if [[ "$app_status" == *"running"* ]]; then
            health_code=$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout 5 --max-time 10 "$health_url" 2>/dev/null) || health_code="000"
            if [[ "$health_code" == "200" ]]; then
                health_ok=true
                echo "   ✅ [${elapsed_min}m${elapsed_sec}s] $stack_name health passed! (HTTP $health_code)" >&2
                break
            fi
            echo "   ⏳ [${elapsed_min}m${elapsed_sec}s] $stack_name: $app_status | Health: HTTP $health_code" >&2
        elif [[ "$app_status" == "exited" || "$app_status" == "stopped" ]]; then
            echo "   ❌ $stack_name containers exited unexpectedly!" >&2
            echo "   Check: $COOLIFY_HOST → app '$stack_name' → Deployments" >&2
            exit 1
        else
            echo "   ⏳ [${elapsed_min}m${elapsed_sec}s] $stack_name: $app_status ($((retry+1))/$max_retries)" >&2
        fi

        retry=$((retry+1))
    done

    if [ "$health_ok" = false ]; then
        echo "   ❌ $stack_name timed out waiting for health check" >&2
        echo "   Last status: $app_status | Last health code: $health_code" >&2
        echo "   Check: $COOLIFY_HOST → app '$stack_name' → Deployments" >&2
        return 1
    fi
    return 0
}

open_browser() {
    local url="$1"
    if command -v xdg-open &>/dev/null; then xdg-open "$url" 2>/dev/null &
    elif command -v open &>/dev/null; then open "$url" 2>/dev/null &
    elif command -v start &>/dev/null; then start "$url" 2>/dev/null &
    elif command -v cmd.exe &>/dev/null; then cmd.exe /c start "$url" 2>/dev/null &
    else echo "   📎 Open this URL: $url" >&2; fi
}

# ══════════════════════════════════════════════════════════════════════════════
# MODE: --verify — Check health, env vars, and connectivity
# ══════════════════════════════════════════════════════════════════════════════

if [[ "$INSTALLER_MODE" == "verify" ]]; then
    echo ""
    echo "════════════════════════════════════════════════════════"
    echo "🔍 YCookies Verify — $TRAEFIK_PREFIX"
    echo "════════════════════════════════════════════════════════"
    echo ""

    ADMIN_STACK_NAME="ycookies-admin-${TRAEFIK_PREFIX}"
    PROXY_STACK_NAME="ycookies-proxy-${TRAEFIK_PREFIX}"
    PASS=0
    FAIL=0
    WARN=0

    check() {
        local label="$1" ok="$2"
        if [[ "$ok" == "true" ]]; then
            echo "   ✅ $label"
            PASS=$((PASS+1))
        else
            echo "   ❌ $label"
            FAIL=$((FAIL+1))
        fi
    }

    warn() {
        echo "   ⚠️  $1"
        WARN=$((WARN+1))
    }

    # Find apps by name
    ALL_APPS=$(api_request "GET" "applications" "")

    ADMIN_UUID=$(echo "$ALL_APPS" | jq -r --arg name "$ADMIN_STACK_NAME" '.[] | select(.name == $name) | .uuid // empty' 2>/dev/null)
    PROXY_UUID=$(echo "$ALL_APPS" | jq -r --arg name "$PROXY_STACK_NAME" '.[] | select(.name == $name) | .uuid // empty' 2>/dev/null)

    check "Admin app '$ADMIN_STACK_NAME' exists" "$([[ -n "$ADMIN_UUID" ]] && echo true || echo false)"
    check "Proxy app '$PROXY_STACK_NAME' exists" "$([[ -n "$PROXY_UUID" ]] && echo true || echo false)"

    if [[ -z "$ADMIN_UUID" || -z "$PROXY_UUID" ]]; then
        echo ""
        echo "   Cannot verify — apps not found. Run the installer first."
        exit 1
    fi

    # Check Coolify status
    echo ""
    echo "   📊 Coolify Status:"
    ADMIN_DATA=$(api_request "GET" "applications/$ADMIN_UUID" "" 2>/dev/null) || ADMIN_DATA="{}"
    PROXY_DATA=$(api_request "GET" "applications/$PROXY_UUID" "" 2>/dev/null) || PROXY_DATA="{}"

    ADMIN_STATUS=$(echo "$ADMIN_DATA" | jq -r '.status // "unknown"')
    PROXY_STATUS=$(echo "$PROXY_DATA" | jq -r '.status // "unknown"')

    check "Admin status: $ADMIN_STATUS" "$([[ "$ADMIN_STATUS" == *"running"* ]] && echo true || echo false)"
    check "Proxy status: $PROXY_STATUS" "$([[ "$PROXY_STATUS" == *"running"* ]] && echo true || echo false)"

    # Check health endpoints
    echo ""
    echo "   🏥 Health Checks:"
    if [[ -n "$ROUTE_DOMAIN" ]]; then
        ADMIN_HEALTH=$(curl -sS -o /dev/null -w "%{http_code}" --connect-timeout 5 --max-time 10 "https://$ROUTE_DOMAIN/up" 2>/dev/null) || ADMIN_HEALTH="000"
        check "Admin health (https://$ROUTE_DOMAIN/up): HTTP $ADMIN_HEALTH" "$([[ "$ADMIN_HEALTH" == "200" ]] && echo true || echo false)"
    else
        warn "No --domain provided, skipping HTTP health checks"
    fi

    # Check env vars
    echo ""
    echo "   🔑 Environment Variables:"
    ADMIN_ENVS=$(api_request "GET" "applications/$ADMIN_UUID/envs" "" 2>/dev/null) || ADMIN_ENVS="[]"
    PROXY_ENVS=$(api_request "GET" "applications/$PROXY_UUID/envs" "" 2>/dev/null) || PROXY_ENVS="[]"

    for v in APP_KEY COOLIFY_API_TOKEN COOLIFY_PROXY_APP_UUID TRAEFIK_PREFIX ADMIN_HOST; do
        val=$(echo "$ADMIN_ENVS" | jq -r --arg k "$v" '.[] | select(.key == $k) | .value // empty')
        check "Admin: $v set" "$([[ -n "$val" ]] && echo true || echo false)"
    done

    for v in ADMIN_HOST TRAEFIK_PREFIX SERVICE_BASE64_64_PROXY; do
        val=$(echo "$PROXY_ENVS" | jq -r --arg k "$v" '.[] | select(.key == $k) | .value // empty')
        check "Proxy: $v set" "$([[ -n "$val" ]] && echo true || echo false)"
    done

    # Check shared secret match
    echo ""
    echo "   🔗 Shared Secret Sync:"
    ADMIN_SECRET=$(echo "$ADMIN_ENVS" | jq -r '.[] | select(.key == "SERVICE_BASE64_64_PROXY") | .value // empty')
    PROXY_SECRET=$(echo "$PROXY_ENVS" | jq -r '.[] | select(.key == "SERVICE_BASE64_64_PROXY") | .value // empty')

    if [[ -n "$ADMIN_SECRET" && -n "$PROXY_SECRET" ]]; then
        check "Shared secrets match" "$([[ "$ADMIN_SECRET" == "$PROXY_SECRET" ]] && echo true || echo false)"
    else
        check "Both apps have SERVICE_BASE64_64_PROXY" "false"
    fi

    # Summary
    echo ""
    echo "════════════════════════════════════════════════════════"
    echo "   Results: ✅ $PASS passed | ❌ $FAIL failed | ⚠️  $WARN warnings"
    echo ""
    echo "   Admin UUID: $ADMIN_UUID"
    echo "   Proxy UUID: $PROXY_UUID"
    echo "════════════════════════════════════════════════════════"

    [[ $FAIL -gt 0 ]] && exit 1 || exit 0
fi

# ══════════════════════════════════════════════════════════════════════════════
# MODE: --cleanup — Delete both apps for a fresh start
# ══════════════════════════════════════════════════════════════════════════════

if [[ "$INSTALLER_MODE" == "cleanup" ]]; then
    echo ""
    echo "════════════════════════════════════════════════════════"
    echo "🧹 YCookies Cleanup — $TRAEFIK_PREFIX"
    echo "════════════════════════════════════════════════════════"
    echo ""

    ADMIN_STACK_NAME="ycookies-admin-${TRAEFIK_PREFIX}"
    PROXY_STACK_NAME="ycookies-proxy-${TRAEFIK_PREFIX}"

    ALL_APPS=$(api_request "GET" "applications" "")

    ADMIN_UUID=$(echo "$ALL_APPS" | jq -r --arg name "$ADMIN_STACK_NAME" '.[] | select(.name == $name) | .uuid // empty' 2>/dev/null)
    PROXY_UUID=$(echo "$ALL_APPS" | jq -r --arg name "$PROXY_STACK_NAME" '.[] | select(.name == $name) | .uuid // empty' 2>/dev/null)

    echo "   Apps to delete:"
    if [[ -n "$ADMIN_UUID" ]]; then
        echo "   ├── $ADMIN_STACK_NAME ($ADMIN_UUID)"
    else
        echo "   ├── $ADMIN_STACK_NAME (not found)"
    fi
    if [[ -n "$PROXY_UUID" ]]; then
        echo "   └── $PROXY_STACK_NAME ($PROXY_UUID)"
    else
        echo "   └── $PROXY_STACK_NAME (not found)"
    fi

    if [[ -z "$ADMIN_UUID" && -z "$PROXY_UUID" ]]; then
        echo ""
        echo "   Nothing to clean up."
        exit 0
    fi

    echo ""
    echo "   ⚠️  This will DELETE the apps and all their data (MySQL, Redis, etc)."
    echo "   ⚠️  This action cannot be undone."
    echo ""
    read -rp "   Type 'DELETE' to confirm: " CONFIRM

    if [[ "$CONFIRM" != "DELETE" ]]; then
        echo "   Cancelled."
        exit 0
    fi

    if [[ -n "$ADMIN_UUID" ]]; then
        echo "   Deleting $ADMIN_STACK_NAME..."
        api_request "DELETE" "applications/$ADMIN_UUID" "" > /dev/null 2>&1 || true
        echo "   ✅ Admin app deleted."
    fi

    if [[ -n "$PROXY_UUID" ]]; then
        echo "   Deleting $PROXY_STACK_NAME..."
        api_request "DELETE" "applications/$PROXY_UUID" "" > /dev/null 2>&1 || true
        echo "   ✅ Proxy app deleted."
    fi

    echo ""
    echo "   🧹 Cleanup complete. Run the installer to redeploy."
    exit 0
fi

# ══════════════════════════════════════════════════════════════════════════════
# MODE: install (default) — Full deployment
# ══════════════════════════════════════════════════════════════════════════════

echo "════════════════════════════════════════════════════════"
echo "🚀 YCookies Installer — $ROUTE_DOMAIN"
echo "════════════════════════════════════════════════════════"

# ══════════════════════════════════════════════════════════════════════════════
# PHASE 1: Discover Coolify Infrastructure
# ══════════════════════════════════════════════════════════════════════════════

log_section "Discovering Coolify infrastructure..."
echo "   🌐 Coolify: $COOLIFY_HOST"
echo "   🔑 Token: $(mask "$COOLIFY_API_TOKEN")"

# Project — create new or use existing
if [[ -z "$PROJECT_UUID" ]]; then
    # If a project name is set (via default or --project-name), auto-create/find it
    if [[ -n "$PROJECT_NAME" ]]; then
        PROJECTS_DATA=$(api_request "GET" "projects" "")
        # Try to find existing project by name
        PROJECT_UUID=$(echo "$PROJECTS_DATA" | jq -r --arg name "$PROJECT_NAME" \
            '[.[] | select(.name == $name)] | first | .uuid // empty' 2>/dev/null | tr -d '\r\n')

        if [[ -n "$PROJECT_UUID" && "$PROJECT_UUID" != "null" ]]; then
            # Validate the project has a usable environment (orphan projects may not)
            ENV_CHECK=$(api_request "GET" "projects/$PROJECT_UUID" "" 2>/dev/null || echo "{}")
            HAS_ENVS=$(echo "$ENV_CHECK" | jq '[.environments // [] | length] | first // 0' 2>/dev/null | tr -d '\r\n')
            if [[ "$HAS_ENVS" -gt 0 ]]; then
                echo "   ✅ Found existing project \"$PROJECT_NAME\" ($PROJECT_UUID)" >&2
            else
                echo "   ⚠️  Project \"$PROJECT_NAME\" exists but has no environments. Recreating..." >&2
                api_request "DELETE" "projects/$PROJECT_UUID" "" > /dev/null 2>&1 || true
                PROJECT_UUID=""
            fi
        fi

        # Create new project if needed
        if [[ -z "$PROJECT_UUID" || "$PROJECT_UUID" == "null" ]]; then
            echo "   Creating project \"$PROJECT_NAME\"..." >&2
            PROJECT_RESPONSE=$(api_request "POST" "projects" "{\"name\": \"$PROJECT_NAME\", \"description\": \"Created by YCookies installer\"}")
            PROJECT_UUID=$(echo "$PROJECT_RESPONSE" | jq -r '.uuid // empty' | tr -d '\r\n')
            if [[ -z "$PROJECT_UUID" || "$PROJECT_UUID" == "null" ]]; then
                echo "   ❌ Failed to create project." >&2
                exit 1
            fi
            echo "   ✅ Created project \"$PROJECT_NAME\" ($PROJECT_UUID)" >&2
        fi
    elif is_interactive; then
        echo "" >&2
        echo "   Would you like to create a new Coolify project or use an existing one?" >&2

        # List existing projects
        PROJECTS_DATA=$(api_request "GET" "projects" "")
        PROJECTS_COUNT=$(echo "$PROJECTS_DATA" | jq 'if type == "array" then length else 0 end')

        if [[ "$PROJECTS_COUNT" -gt 0 ]]; then
            echo "   Existing projects:" >&2
            echo "$PROJECTS_DATA" | jq -r '.[] | "     - \(.name) (\(.uuid))"' >&2
        fi

        echo "" >&2
        read -rp "   📁 Project name (enter name for NEW project, or UUID for existing): " PROJECT_INPUT

        if [[ -z "$PROJECT_INPUT" ]]; then
            echo "   ❌ Project is required." >&2
            exit 1
        fi

        # Check if input looks like a UUID
        if [[ "$PROJECT_INPUT" =~ ^[a-z0-9]{20,}$ ]]; then
            PROJECT_UUID="$PROJECT_INPUT"
            echo "   ✅ Using existing project: $PROJECT_UUID" >&2
        else
            echo "   Creating project \"$PROJECT_INPUT\"..." >&2
            PROJECT_RESPONSE=$(api_request "POST" "projects" "{\"name\": \"$PROJECT_INPUT\", \"description\": \"Created by YCookies installer\"}")
            PROJECT_UUID=$(echo "$PROJECT_RESPONSE" | jq -r '.uuid // empty')
            if [[ -z "$PROJECT_UUID" || "$PROJECT_UUID" == "null" ]]; then
                echo "   ❌ Failed to create project." >&2
                exit 1
            fi
            echo "   ✅ Created project \"$PROJECT_INPUT\" ($PROJECT_UUID)" >&2
        fi
    else
        echo "   ❌ PROJECT_UUID not set. Pass --project or --project-name." >&2
        exit 1
    fi
fi

# Server
if [[ -z "$SERVER_UUID" ]]; then
    SERVER_UUID=$(auto_pick "Server" "servers" ".uuid" ".name")
fi

# Destination
if [[ -z "$DESTINATION_UUID" ]]; then
    echo "   Discovering destination..." >&2
    APPS_DATA=$(api_request "GET" "applications" "")

    DESTINATION_UUID=$(echo "$APPS_DATA" | jq -r '
        [.[] | select(.destination.uuid != null)] | .[0].destination.uuid // empty
    ' 2>/dev/null || echo "")

    if [[ -z "$DESTINATION_UUID" || "$DESTINATION_UUID" == "null" ]]; then
        echo "" >&2
        echo "   ⚠️  No existing apps to extract destination from." >&2
        echo "   Find it in Coolify → Servers → your server → Destinations tab" >&2
        read -rp "   📦 Destination UUID: " DESTINATION_UUID
    fi

    if [[ -z "$DESTINATION_UUID" || "$DESTINATION_UUID" == "null" ]]; then
        echo "   ❌ Destination UUID is required." >&2
        exit 1
    fi

    echo "   ✅ Destination: $DESTINATION_UUID" >&2
fi

echo ""
echo "   Infrastructure resolved:"
echo "   ├── Project:     $PROJECT_UUID"
echo "   ├── Server:      $SERVER_UUID"
echo "   └── Destination: $DESTINATION_UUID"
echo ""

# ── GitHub App ───────────────────────────────────────────────────────────────

if [[ -z "$GITHUB_APP_UUID" && -z "$PRIVATE_KEY_UUID" ]]; then
    GITHUB_APPS_DATA=$(api_request "GET" "github-apps" "")

    ALL_COUNT=$(echo "$GITHUB_APPS_DATA" | jq 'if type == "array" then length else 0 end')

    echo "" >&2
    echo "   GitHub Apps on your Coolify instance:" >&2
    if [[ "$ALL_COUNT" -gt 0 ]]; then
        echo "$GITHUB_APPS_DATA" | jq -r '.[] | "     - \(.name) (\(.uuid))\(if .is_public then " [public]" else "" end)"' >&2
    else
        echo "     (none)" >&2
    fi
    echo "" >&2
    echo "   Enter the UUID of an already added GitHub App," >&2
    echo "   or type 'new' to add a new one via browser." >&2

    echo "" >&2
    read -rp "   🔗 GitHub App UUID (or 'new'): " GH_INPUT

    if [[ "$GH_INPUT" == "new" || -z "$GH_INPUT" ]]; then
        echo "" >&2
        echo "   Opening browser to add a new GitHub App source..." >&2
        echo "" >&2
        echo "   Steps:" >&2
        echo "      1. Click 'Add' and name it, then click 'Continue'" >&2
        echo "      2. Use the webhook with your domain (e.g. $COOLIFY_HOST)" >&2
        echo "      3. Click 'Register', then 'Create GitHub App'" >&2
        echo "      4. Follow the GitHub OAuth flow" >&2
        echo "      5. Click 'Install Repositories on GitHub'" >&2
        echo "      6. Select 'Only select repositories' → pick omarijbara/YCookies → click 'Install'" >&2
        echo "      7. Come back here and press Enter" >&2
        echo "" >&2

        open_browser "$COOLIFY_HOST/source/new"

        read -rp "   Press Enter when done... "

        GITHUB_APPS_DATA=$(api_request "GET" "github-apps" "")
        GITHUB_APP_UUID=$(echo "$GITHUB_APPS_DATA" | jq -r '
            [.[] | select(.is_public == false)] | last | .uuid // empty
        ' 2>/dev/null || echo "")
    else
        GITHUB_APP_UUID="$GH_INPUT"
    fi

    if [[ -z "$GITHUB_APP_UUID" || "$GITHUB_APP_UUID" == "null" ]]; then
        echo "   ❌ No GitHub App selected." >&2
        exit 1
    fi

    GITHUB_APP_NAME=$(echo "$GITHUB_APPS_DATA" | jq -r --arg uuid "$GITHUB_APP_UUID" 'first(.[] | select(.uuid == $uuid) | .name) // "unknown"')
    echo "   ✅ GitHub App: \"$GITHUB_APP_NAME\" ($GITHUB_APP_UUID)" >&2
fi

# Resolve name if --github-app was passed directly
if [[ -n "$GITHUB_APP_UUID" && -z "$GITHUB_APP_NAME" ]]; then
    GITHUB_APPS_DATA=$(api_request "GET" "github-apps" "")
    GITHUB_APP_NAME=$(echo "$GITHUB_APPS_DATA" | jq -r --arg uuid "$GITHUB_APP_UUID" '
        first(.[] | select(.uuid == $uuid) | .name) // "unknown"
    ')
    echo "   ✅ GitHub App: \"$GITHUB_APP_NAME\" ($GITHUB_APP_UUID)" >&2
fi

# ══════════════════════════════════════════════════════════════════════════════
# PHASE 2: Create or Reuse Both Apps
# ══════════════════════════════════════════════════════════════════════════════

log_section "Creating/reusing Coolify apps..."

ADMIN_UUID=$(create_or_reuse_app "$ADMIN_STACK_NAME" "coolify/admin/docker-compose.yaml" "YCookies Admin — Laravel, MySQL, Redis, GlitchTip" | tr -d '\r')
PROXY_UUID=$(create_or_reuse_app "$PROXY_STACK_NAME" "coolify/proxy/docker-compose.yaml" "YCookies Proxy — Node-proxy (independent lifecycle)" | tr -d '\r')

echo ""
echo "   Apps:"
echo "   ├── Admin: $ADMIN_STACK_NAME ($ADMIN_UUID)"
echo "   └── Proxy: $PROXY_STACK_NAME ($PROXY_UUID)"

# ══════════════════════════════════════════════════════════════════════════════
# PHASE 3: Resolve APP_KEY (preserve on reruns)
# ══════════════════════════════════════════════════════════════════════════════

log_section "Resolving APP_KEY..."

EXISTING_APP_KEY=$(
    api_request "GET" "applications/$ADMIN_UUID/envs" "" \
    | jq -r '.[] | select(.key == "APP_KEY") | .value // empty' 2>/dev/null \
    || echo ""
)

if [[ -n "$EXISTING_APP_KEY" && "$EXISTING_APP_KEY" != "null" && "$EXISTING_APP_KEY" == base64:* ]]; then
    APP_KEY="$EXISTING_APP_KEY"
    echo "   ♻️  Preserved existing APP_KEY."
else
    APP_KEY="base64:$(openssl rand -base64 32)"
    echo "   ✅ Generated new APP_KEY (value redacted)."
fi

# ══════════════════════════════════════════════════════════════════════════════
# PHASE 4: Preflight Check
# ══════════════════════════════════════════════════════════════════════════════

log_section "Preflight check..."
preflight_ok=true
for v in TRAEFIK_PREFIX ROUTE_DOMAIN APP_KEY ADMIN_UUID PROXY_UUID COOLIFY_API_TOKEN; do
    if [[ -z "${!v:-}" ]]; then
        echo "   ❌ Missing: $v"
        preflight_ok=false
    fi
done
[[ "$preflight_ok" == "false" ]] && exit 1
echo "   ✅ All values present."
echo ""
echo "   Configuration summary:"
echo "   ├── Domain:       $ROUTE_DOMAIN"
echo "   ├── Prefix:       $TRAEFIK_PREFIX"
echo "   ├── Admin UUID:   $ADMIN_UUID"
echo "   ├── Proxy UUID:   $PROXY_UUID"
echo "   └── APP_KEY:      $(mask "$APP_KEY" 10)"

# ══════════════════════════════════════════════════════════════════════════════
# PHASE 5: Inject Environment Variables
# ══════════════════════════════════════════════════════════════════════════════

log_section "Injecting environment variables..."

make_env() {
    local key="$1" value="$2" shown_once="${3:-false}"
    jq -n --arg k "$key" --arg v "$value" --argjson so "$shown_once" \
        '{key: $k, value: $v, is_preview: false, is_literal: true, is_multiline: false, is_shown_once: $so}'
}

# ── Admin App env vars ──
echo ""
echo "   📦 Admin app ($ADMIN_STACK_NAME):"

GLITCHTIP_ENVS=""
if [[ -n "$GLITCHTIP_DOMAIN" ]]; then
    GLITCHTIP_ENVS=",
    $(make_env "GLITCHTIP_HOST" "$GLITCHTIP_DOMAIN"),
    $(make_env "GLITCHTIP_DOMAIN" "https://$GLITCHTIP_DOMAIN")"
    if [[ -n "${GLITCHTIP_API_TOKEN:-}" ]]; then
        GLITCHTIP_ENVS="$GLITCHTIP_ENVS,
    $(make_env "GLITCHTIP_API_TOKEN" "$GLITCHTIP_API_TOKEN" "true"),
    $(make_env "GLITCHTIP_ORG_SLUG" "$GLITCHTIP_ORG_SLUG")"
    fi
fi

ADMIN_ENV_JSON=$(jq -n --argjson envs "[
    $(make_env "APP_KEY" "$APP_KEY"),
    $(make_env "COOLIFY_API_TOKEN" "$COOLIFY_API_TOKEN" "true"),
    $(make_env "COOLIFY_APP_UUID" "$ADMIN_UUID"),
    $(make_env "COOLIFY_PROXY_APP_UUID" "$PROXY_UUID"),
    $(make_env "TRAEFIK_PREFIX" "$TRAEFIK_PREFIX"),
    $(make_env "ADMIN_HOST" "$ROUTE_DOMAIN"),
    $(make_env "COOLIFY_INSTANCE_URL" "$COOLIFY_HOST")$GLITCHTIP_ENVS
]" '{data: $envs}')

ADMIN_ENV_COUNT=$(echo "$ADMIN_ENV_JSON" | jq '.data | length')
echo "$ADMIN_ENV_JSON" | jq -r '.data[] | "      \(.key) = \(if .key == "APP_KEY" or .key == "COOLIFY_API_TOKEN" then "***redacted***" else .value end)"'

api_request "PATCH" "applications/$ADMIN_UUID/envs/bulk" "$ADMIN_ENV_JSON" > /dev/null
echo "   ✅ $ADMIN_ENV_COUNT env vars injected into admin app."

# ── Proxy App env vars ──
echo ""
echo "   📦 Proxy app ($PROXY_STACK_NAME):"

PROXY_ENV_JSON=$(jq -n --argjson envs "[
    $(make_env "ADMIN_HOST" "$ROUTE_DOMAIN"),
    $(make_env "TRAEFIK_PREFIX" "$TRAEFIK_PREFIX")
]" '{data: $envs}')

PROXY_ENV_COUNT=$(echo "$PROXY_ENV_JSON" | jq '.data | length')
echo "$PROXY_ENV_JSON" | jq -r '.data[] | "      \(.key) = \(.value)"'

api_request "PATCH" "applications/$PROXY_UUID/envs/bulk" "$PROXY_ENV_JSON" > /dev/null
echo "   ✅ $PROXY_ENV_COUNT env vars injected into proxy app."

log_elapsed

# PHASE 6: Shared Docker Network — handled automatically
# ══════════════════════════════════════════════════════════════════════════════
# The compose files define "ycookies-shared" as a named bridge network
# (not external). Docker auto-creates it on first `docker compose up`.
# No SSH or manual network creation needed.
echo "   ✅ Network ycookies-shared: auto-created by Docker compose."

# ══════════════════════════════════════════════════════════════════════════════
# PHASE 7: Deploy Admin App (must come first — provides MySQL + Redis)
# ══════════════════════════════════════════════════════════════════════════════

log_section "Deploying admin app (provides MySQL + Redis for proxy)..."

# Step 7a: Trigger deploy #1 (compose gets parsed, containers build)
deploy_and_wait "$ADMIN_UUID" "$ADMIN_STACK_NAME"
ADMIN_DEPLOY1_UUID="$LAST_DEPLOY_UUID"

# Step 7b: Wait for deploy #1 to actually finish building
# This is critical — domains can only be set AFTER Coolify parses the compose
# and discovers the service names. And the redeploy with domains should only
# happen after the first deploy's containers are running.
if [[ -n "$ADMIN_DEPLOY1_UUID" ]]; then
    if ! wait_for_deployment "$ADMIN_DEPLOY1_UUID" "$ADMIN_STACK_NAME" 12; then
        echo "" >&2
        echo "   ❌ Admin deploy #1 failed. Cannot continue." >&2
        echo "   Check Coolify logs: https://$COOLIFY_HOST" >&2
        exit 1
    fi
fi

# Step 7c: Now set domains (compose is parsed, services are known)
echo "   Setting domain: laravel → https://$ROUTE_DOMAIN"

if [[ -n "$GLITCHTIP_DOMAIN" ]]; then
    ADMIN_DOMAINS_JSON=$(jq -n \
        --arg domain "https://$ROUTE_DOMAIN" \
        --arg gtdomain "https://$GLITCHTIP_DOMAIN" \
        '{docker_compose_domains: [{name: "laravel", domain: $domain}, {name: "glitchtip-web", domain: $gtdomain}]}'
    )
else
    ADMIN_DOMAINS_JSON=$(jq -n \
        --arg domain "https://$ROUTE_DOMAIN" \
        '{docker_compose_domains: [{name: "laravel", domain: $domain}]}'
    )
fi

api_request "PATCH" "applications/$ADMIN_UUID" "$ADMIN_DOMAINS_JSON" > /dev/null
echo "   ✅ Admin domains configured."

# Step 7d: Redeploy admin with correct domains → Traefik routes to cookies.ypsilon.dev
echo "   Triggering admin redeploy with domains..."
deploy_and_wait "$ADMIN_UUID" "$ADMIN_STACK_NAME"
ADMIN_DEPLOY2_UUID="$LAST_DEPLOY_UUID"

# Wait for deploy #2 to finish — ensures Traefik picks up correct domain BEFORE proxy starts
if [[ -n "$ADMIN_DEPLOY2_UUID" ]]; then
    if ! wait_for_deployment "$ADMIN_DEPLOY2_UUID" "$ADMIN_STACK_NAME" 12; then
        echo "   ⚠️  Admin redeploy with domains may still be building. Continuing..." >&2
    fi
fi

log_elapsed

# ══════════════════════════════════════════════════════════════════════════════
# PHASE 7.5: Sync Shared Secret (admin → proxy)
# ══════════════════════════════════════════════════════════════════════════════
# Coolify auto-generates SERVICE_BASE64_64_PROXY independently for each app.
# Both apps must share the SAME secret for proxy ↔ Laravel auth to work.

log_section "Syncing shared secret from admin to proxy..."

ADMIN_ENVS_DATA=$(api_request "GET" "applications/$ADMIN_UUID/envs" "")
ADMIN_SHARED_SECRET=$(echo "$ADMIN_ENVS_DATA" | jq -r '[.[] | select(.key == "SERVICE_BASE64_64_PROXY")] | first | .value // empty' | tr -d '\r')

if [[ -z "$ADMIN_SHARED_SECRET" || "$ADMIN_SHARED_SECRET" == "null" ]]; then
    echo "   ⚠️  Admin's SERVICE_BASE64_64_PROXY not found yet. Waiting 30s..." >&2
    sleep 30
    ADMIN_ENVS_DATA=$(api_request "GET" "applications/$ADMIN_UUID/envs" "")
    ADMIN_SHARED_SECRET=$(echo "$ADMIN_ENVS_DATA" | jq -r '[.[] | select(.key == "SERVICE_BASE64_64_PROXY")] | first | .value // empty' | tr -d '\r')
fi

if [[ -n "$ADMIN_SHARED_SECRET" && "$ADMIN_SHARED_SECRET" != "null" ]]; then
    # Use bulk env update to set/overwrite proxy's SERVICE_BASE64_64_PROXY
    SYNC_BODY=$(jq -n --arg v "$ADMIN_SHARED_SECRET" '{data: [{key: "SERVICE_BASE64_64_PROXY", value: $v, is_preview: false, is_literal: true, is_multiline: false, is_shown_once: false}]}')
    api_request "PATCH" "applications/$PROXY_UUID/envs/bulk" "$SYNC_BODY" > /dev/null
    echo "   ✅ Synced SERVICE_BASE64_64_PROXY from admin → proxy ($(mask "$ADMIN_SHARED_SECRET" 8))"
else
    echo "   ⚠️  Could not retrieve admin's SERVICE_BASE64_64_PROXY. Manual sync required." >&2
fi
log_elapsed

# ══════════════════════════════════════════════════════════════════════════════
# PHASE 8: Deploy Proxy App (connects to admin's MySQL + Redis)
# ══════════════════════════════════════════════════════════════════════════════

log_section "Deploying proxy app (connects to admin's Redis + Laravel)..."
deploy_and_wait "$PROXY_UUID" "$PROXY_STACK_NAME"
PROXY_DEPLOY1_UUID="$LAST_DEPLOY_UUID"

# Wait for proxy deploy #1 to finish
if [[ -n "$PROXY_DEPLOY1_UUID" ]]; then
    if ! wait_for_deployment "$PROXY_DEPLOY1_UUID" "$PROXY_STACK_NAME" 8; then
        echo "   ⚠️  Proxy deploy may still be building. Continuing to health check..." >&2
    fi
fi

# Proxy domain routing is managed by Coolify via docker_compose_domains.
# CoolifyService::syncDomains() patches the proxy app's domains when
# domains are added/removed in the admin panel.
echo "   ✅ Proxy deployed (domains managed by Coolify docker_compose_domains)."
log_elapsed

# ══════════════════════════════════════════════════════════════════════════════
# PHASE 9: Final Health Verification
# ══════════════════════════════════════════════════════════════════════════════
# Poll HTTP endpoints until they return 200, or fail after max wait.
# This ensures the installer only declares success when the system actually works.

log_section "Verifying endpoints are healthy..."

HEALTH_ENDPOINTS=(
    "https://$ROUTE_DOMAIN/up|Admin health"
    "https://$ROUTE_DOMAIN/admin/login|Admin login page"
)
if [[ -n "$GLITCHTIP_DOMAIN" ]]; then
    HEALTH_ENDPOINTS+=("https://$GLITCHTIP_DOMAIN|GlitchTip")
fi

MAX_HEALTH_WAIT=300  # 5 minutes max
HEALTH_INTERVAL=30   # Check every 30 seconds
HEALTH_START=$(date +%s)
ALL_HEALTHY=false

while true; do
    ELAPSED=$(( $(date +%s) - HEALTH_START ))
    if [[ $ELAPSED -ge $MAX_HEALTH_WAIT ]]; then
        break
    fi

    ALL_HEALTHY=true
    for entry in "${HEALTH_ENDPOINTS[@]}"; do
        url="${entry%%|*}"
        label="${entry##*|}"
        http_code=$(curl -sS -o /dev/null -w "%{http_code}" --connect-timeout 10 --max-time 15 "$url" 2>/dev/null || echo "000")

        if [[ "$http_code" == "200" ]]; then
            echo "   ✅ $label: $http_code" >&2
        else
            ALL_HEALTHY=false
            ELAPSED_MIN=$((ELAPSED / 60))
            ELAPSED_SEC=$((ELAPSED % 60))
            echo "   ⏳ [${ELAPSED_MIN}m${ELAPSED_SEC}s] $label: $http_code (waiting...)" >&2
        fi
    done

    if [[ "$ALL_HEALTHY" == "true" ]]; then
        break
    fi

    sleep $HEALTH_INTERVAL
done

if [[ "$ALL_HEALTHY" == "true" ]]; then
    echo "   ✅ All endpoints healthy!" >&2
else
    echo "" >&2
    echo "   ⚠️  Some endpoints not yet healthy after ${MAX_HEALTH_WAIT}s." >&2
    echo "   They may still be starting. Check: bash coolify-healthcheck.sh" >&2
fi

log_elapsed

# ══════════════════════════════════════════════════════════════════════════════
INSTALL_END=$(date +%s)
TOTAL_ELAPSED=$(( INSTALL_END - INSTALL_START ))
TOTAL_MIN=$(( TOTAL_ELAPSED / 60 ))
TOTAL_SEC=$(( TOTAL_ELAPSED % 60 ))

echo ""
echo "════════════════════════════════════════════════════════"
if [[ "$ALL_HEALTHY" == "true" ]]; then
    echo "🎉 YCookies deployed and verified!"
else
    echo "🍪 YCookies deployed (health check pending)"
fi
echo "════════════════════════════════════════════════════════"
echo ""
echo "   Admin App:  $ADMIN_UUID"
echo "   Proxy App:  $PROXY_UUID"
echo "   Domain:     https://$ROUTE_DOMAIN"
echo "   Admin:      https://$ROUTE_DOMAIN/admin"
if [[ -n "$GLITCHTIP_DOMAIN" ]]; then
    echo "   GlitchTip:  https://$GLITCHTIP_DOMAIN"
fi
echo ""
echo "   ⏱  Completed in ${TOTAL_MIN}m ${TOTAL_SEC}s"
echo ""
echo "   Next steps:"
echo "   1. Sync domains:  curl -X POST -H 'X-Proxy-Secret: <secret>' https://$ROUTE_DOMAIN/api/proxy/sync-domains"
echo ""
echo "════════════════════════════════════════════════════════"
