# &#x1F36A; YCookies Coolify Installer

> **Zero-touch deployment** of the YCookies split architecture.  
> Creates two independent Coolify apps that never interfere with each other.

## Architecture

```
┌─────────────────────┐    ┌──────────────────────┐
│  ycookies-admin      │    │  ycookies-proxy       │
│                      │    │                       │
│  Laravel  ─── MySQL  │◄───│  Node.js Proxy        │
│  Queue    ─── Redis  │    │  (Fastify)            │
│  GlitchTip           │    │                       │
│                      │    │                       │
│  cookies.ypsilon.dev │    │  duftz.de, *.proxy    │
└──────────┬───────────┘    └───────────┬───────────┘
           │       ycookies-shared       │
           └────────────────────────────┘
```

**Admin deploys never restart the proxy. Proxied domains stay live.**

---

## Prerequisites

| Tool | Install |
|------|---------|
| `bash` | Git Bash (Windows), native (macOS/Linux) |
| `curl` | `choco install curl` / `brew install curl` |
| `jq` | `choco install jq` / `brew install jq` |
| `openssl` | `choco install openssl` / `brew install openssl` |

You also need a **Coolify API Token** — find it at:  
`Coolify Dashboard → Settings → Keys & Tokens → API tokens`

---

## Quick Start

### Interactive (prompts for everything)

```bash
export COOLIFY_API_TOKEN='your-token-here'
bash coolify-installer.sh
```

### Non-Interactive (CI/CD or scripted)

```bash
export COOLIFY_API_TOKEN='<your-coolify-api-token>'

bash coolify-installer.sh \
  --host https://coolify.revyome.com \
  --domain cookies.ypsilon.dev \
  --prefix ycookies \
  --project gez503gdgqdmhpd35qrgnc38 \
  --server kcwsok0kk88kwc8cs8s48sow \
  --destination c48ggccg8c8gcosk48wc0080 \
  --github-app ry33yy1dsftaprkou4pio9dt \
  --glitchtip-domain sentry.ypsilon.dev \
  --glitchtip-token '' \
  --glitchtip-org default
```

---

## Modes

### Install (default)

Full deployment — creates apps, injects env vars, deploys, syncs secrets, triggers domain sync.

```bash
bash coolify-installer.sh --domain cookies.ypsilon.dev --prefix ycookies
```

### Verify (`--verify`)

Checks health, env vars, and connectivity **without deploying**.  
Returns exit code 0 if all checks pass, 1 if any fail.

```bash
bash coolify-installer.sh --verify --prefix ycookies --domain cookies.ypsilon.dev
```

**What it checks:**
- ✅ Both apps exist in Coolify
- ✅ Both apps are `running:healthy`
- ✅ Admin health endpoint (`/up`) returns HTTP 200
- ✅ Required env vars set on both apps
- ✅ `SERVICE_BASE64_64_PROXY` matches between admin and proxy

### Cleanup (`--cleanup`)

Deletes both apps for a fresh start. **Requires typing `DELETE` to confirm.**

```bash
bash coolify-installer.sh --cleanup --prefix ycookies
```

---

## All Options

| Flag | Required | Description |
|------|----------|-------------|
| `--domain` | Yes (install) | Primary domain (e.g. `cookies.ypsilon.dev`) |
| `--prefix` | Yes | Unique Traefik prefix (e.g. `ycookies`) |
| `--host` | Yes | Coolify instance URL |
| `--project` | Auto | Coolify Project UUID (prompted if missing) |
| `--server` | Auto | Coolify Server UUID (auto-picked) |
| `--destination` | Auto | Coolify Destination UUID (auto-discovered) |
| `--github-app` | Auto | Coolify GitHub App UUID (prompted if missing) |
| `--private-key` | Auto | Coolify Deploy Key UUID |
| `--glitchtip-domain` | No | GlitchTip subdomain (e.g. `sentry.revyome.com`) |
| `--glitchtip-token` | No | GlitchTip API token (skip prompt) |
| `--glitchtip-org` | No | GlitchTip org slug (default: `default`) |
| `--verify` | — | Run in verify mode |
| `--cleanup` | — | Run in cleanup mode |
| `--help` | — | Show help |

| Environment Variable | Required | Description |
|---------------------|----------|-------------|
| `COOLIFY_API_TOKEN` | Yes | Coolify API token |
| `COOLIFY_HOST` | No | Alternative to `--host` flag |

---

## What the Installer Does (10 Phases)

| Phase | Description |
|-------|-------------|
| 1 | **Discover** — auto-picks project, server, destination, GitHub App |
| 2 | **Create apps** — creates or reuses `ycookies-admin` + `ycookies-proxy` |
| 3 | **APP_KEY** — generates new key or preserves existing one |
| 4 | **Preflight** — validates all required values |
| 5 | **Env vars** — injects config into both apps via bulk API |
| 6 | **Network** — creates `ycookies-shared` Docker network |
| 7 | **Admin deploy** — deploys admin, configures domains |
| 7.5 | **Secret sync** — copies `SERVICE_BASE64_64_PROXY` from admin → proxy |
| 8 | **Proxy deploy** — deploys proxy (joins shared network) |
| 9 | **Health check** — polls both apps until healthy (~8-12 min) |
| 9.5 | **Domain sync** — calls Laravel API to update PROXY_RULE with all domains |
| 10 | **Summary** — prints UUIDs, URLs, and elapsed time |

---

## Idempotency

The installer is safe to run multiple times:

- **Apps** are reused by name (no duplicates)
- **APP_KEY** is preserved across reruns
- **Docker network** creation is idempotent
- **Env vars** are bulk-patched (last write wins)

---

## Troubleshooting

### "Invalid JSON" on Windows Git Bash
All JSON is built with `jq -n`, not heredocs. If you still see this, ensure `jq` is installed: `choco install jq`

### Admin is healthy but proxy returns 503
The proxy can't find domain configs. Trigger a domain sync:
1. Go to `https://<domain>/admin`
2. Open any domain → click Save
3. This triggers `CoolifyService::syncDomains()`, updating PROXY_RULE

### Shared secret mismatch
Run `--verify` to check if secrets match. If they don't, rerun the installer — Phase 7.5 will fix it.

### Health check times out
- Admin needs MySQL + Redis to be ready (~3-5 min)
- Proxy needs admin to be ready first (~1-2 min after admin)
- Total first-deploy time: ~8-12 minutes
