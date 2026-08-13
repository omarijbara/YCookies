#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────
# Push the Docker Hub repository descriptions (overview pages) for the
# three YCookies images from docs/dockerhub/*.md.
#
# Uses docker-pushrm (https://github.com/christian-korneck/docker-pushrm),
# which authenticates with the credentials already stored by `docker login`
# — run this on the same host where you are logged in to Docker Hub.
#
# Usage:
#   ./scripts/sync-dockerhub-docs.sh
#   YCOOKIES_NS=myuser ./scripts/sync-dockerhub-docs.sh
# ─────────────────────────────────────────────────────────────────────
set -euo pipefail

NS="${YCOOKIES_NS:-ypsilondev}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PUSHRM_VERSION="v1.9.0"
PUSHRM="/usr/local/bin/docker-pushrm"

if ! command -v docker-pushrm >/dev/null 2>&1; then
    ARCH="$(uname -m)"
    case "$ARCH" in
        x86_64)          BIN="docker-pushrm_linux_amd64" ;;
        aarch64 | arm64) BIN="docker-pushrm_linux_arm64" ;;
        *) echo "Unsupported arch: $ARCH — install docker-pushrm manually"; exit 1 ;;
    esac
    echo "==> Installing docker-pushrm ${PUSHRM_VERSION} (${BIN})..."
    curl -fsSL -o "$PUSHRM" \
        "https://github.com/christian-korneck/docker-pushrm/releases/download/${PUSHRM_VERSION}/${BIN}"
    chmod +x "$PUSHRM"
fi

sync() {
    local image="$1" short="$2"
    echo "==> Syncing overview for ${NS}/${image}"
    docker-pushrm "docker.io/${NS}/${image}" \
        --file "${ROOT}/docs/dockerhub/${image}.md" \
        --short "$short"
}

sync ycookies         "Self-hosted enterprise cookie consent manager (CMP) — Laravel control plane"
sync ycookies-proxy   "YCookies consent reverse proxy — blocks trackers server-side before consent"
sync ycookies-scanner "YCookies scan worker with headless Chromium — tracker detection + GDPR verdicts"

echo "✅ Docker Hub overviews updated: https://hub.docker.com/u/${NS}"
