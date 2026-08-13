#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────
# Build and push the YCookies Docker images from ANY Docker host —
# no GitHub Actions required.
#
# Images published:
#   <ns>/ycookies          (Dockerfile.laravel, target: production)
#   <ns>/ycookies-scanner  (Dockerfile.laravel, target: scanner)
#   <ns>/ycookies-proxy    (services/proxy/Dockerfile)
#
# Prerequisites (one-time, on the build host):
#   docker login -u <namespace>       # paste your Docker Hub access token
#
# Usage:
#   ./scripts/publish-images.sh [TAG]
#
# Examples:
#   ./scripts/publish-images.sh edge          # test publish -> :edge
#   ./scripts/publish-images.sh v1.0.0        # release -> :1.0.0 :1.0 :latest
#   PLATFORMS=linux/amd64 ./scripts/publish-images.sh edge
#                                             # amd64 only (much faster; skip
#                                             # QEMU-emulated arm64 build)
#   YCOOKIES_NS=myuser ./scripts/publish-images.sh edge
# ─────────────────────────────────────────────────────────────────────
set -euo pipefail

NS="${YCOOKIES_NS:-ypsilondev}"
INPUT_TAG="${1:-edge}"
PLATFORMS="${PLATFORMS:-linux/amd64,linux/arm64}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# A semver tag (v1.2.3 or 1.2.3) also publishes :MAJOR.MINOR and :latest.
TAGS=()
if [[ "$INPUT_TAG" =~ ^v?([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
    TAGS=("${BASH_REMATCH[1]}.${BASH_REMATCH[2]}.${BASH_REMATCH[3]}" "${BASH_REMATCH[1]}.${BASH_REMATCH[2]}" "latest")
else
    TAGS=("$INPUT_TAG")
fi

echo "==> Publishing ${NS}/{ycookies,ycookies-scanner,ycookies-proxy}"
echo "    tags:      ${TAGS[*]}"
echo "    platforms: ${PLATFORMS}"

# arm64 on an amd64 host (or vice versa) needs QEMU binfmt handlers.
HOST_ARCH="$(uname -m)"
if [[ "$PLATFORMS" == *arm64* && "$HOST_ARCH" != "aarch64" && "$HOST_ARCH" != "arm64" ]]; then
    echo "==> Installing QEMU binfmt handlers for arm64 emulation..."
    docker run --privileged --rm tonistiigi/binfmt --install arm64 >/dev/null
fi

# Multi-platform builds need a docker-container buildx builder.
if ! docker buildx inspect ycookies-builder >/dev/null 2>&1; then
    docker buildx create --name ycookies-builder --driver docker-container >/dev/null
fi
docker buildx use ycookies-builder

build() {
    local image="$1" context="$2" dockerfile="$3" target="${4:-}"

    local args=(--platform "$PLATFORMS" -f "$dockerfile" --push)
    [[ -n "$target" ]] && args+=(--target "$target")
    local t
    for t in "${TAGS[@]}"; do
        args+=(-t "${NS}/${image}:${t}")
    done

    echo ""
    echo "── building ${NS}/${image} ─────────────────────────────"
    docker buildx build "${args[@]}" "$context"
}

build ycookies         "$ROOT"                "$ROOT/Dockerfile.laravel"          production
build ycookies-scanner "$ROOT"                "$ROOT/Dockerfile.laravel"          scanner
build ycookies-proxy   "$ROOT/services/proxy" "$ROOT/services/proxy/Dockerfile"

echo ""
echo "✅ Published: ${NS}/ycookies, ${NS}/ycookies-scanner, ${NS}/ycookies-proxy (tags: ${TAGS[*]})"
echo "   https://hub.docker.com/u/${NS}"
