# YCookies Self-Hosting Guide (Coolify & Docker)

Deploy YCookies on your own infrastructure for full data control. This project is optimized for **Coolify** (Traefik, TLS, env management) and a **split stack**: Laravel **admin/API** and **Node proxy** can deploy independently so proxy restarts do not take down the control plane.

## Prerequisites

- VPS: **Ubuntu 22.04+** (or compatible), **≥ 4 GB RAM** and **≥ 2 vCPU** for small installs; scale up for heavy scanning and proxy traffic.
- DNS: Domains for **admin** (Filament) and **customer sites** proxied through the Node service (often many hostnames).
- Git access to this repository.

## Architecture (reference)

| Layer | Role | Typical image / compose |
|--------|------|---------------------------|
| **Laravel** | Filament admin, REST `/api`, queues, schedules | `Dockerfile.laravel` |
| **Node proxy** | HTML streaming, blockers, cache, WebSocket | `services/proxy/Dockerfile` |
| **MySQL / Redis** | App data, queues, proxy cache pub/sub | `docker-compose.yaml` or Coolify services |
| **Workers** | `queue-worker`, `health-worker`, `observability-worker`, `scanner-worker` | Same Laravel image, different commands |

Split compose files for production-style deploys:

- [`coolify/admin/docker-compose.yaml`](../coolify/admin/docker-compose.yaml) — admin stack  
- [`coolify/proxy/docker-compose.yaml`](../coolify/proxy/docker-compose.yaml) — proxy-only stack  

Monolithic local stack: [`docker-compose.yaml`](../docker-compose.yaml) (comments list Coolify magic env vars).

## 1. Install Coolify (control plane)

On the VPS:

```bash
wget -q https://get.coollabs.io/coolify/install.sh -O install.sh
sudo bash ./install.sh
```

Create two applications (or use the provided installer script):

- **Admin**: Docker Compose from repo, context **admin** compose path (see `coolify-installer.sh`).
- **Proxy**: Separate app, build from `services/proxy`, wired to Redis and Laravel API URL.

Automated path: run [`coolify-installer.sh`](../coolify-installer.sh) from the repo (see script header for `COOLIFY_API_TOKEN` and host). After install, verify with [`coolify-healthcheck.sh`](../coolify-healthcheck.sh).

### Installer smoke checklist (repeatable)

Use this before claiming a full “greenfield VPS” test (that remains a manual release gate):

1. Dependencies: `curl`, `jq`, `openssl` (the script exits early if any are missing).
2. Credentials: `COOLIFY_API_TOKEN` and optional `COOLIFY_HOST` (see script header and `.env.production.example`).
3. Run **interactive**: `./coolify-installer.sh` or **non-interactive**: `./coolify-installer.sh --domain your-admin.example.com --prefix ycookies`.
4. In Coolify: two apps (admin + proxy) present; env vars populated; redeploy succeeds.
5. From CI or a shell: `COOLIFY_HEALTHCHECK_STRICT=1 bash coolify-healthcheck.sh …` (see `deploy-gate.yml`).

## 2. Environment variables

Use **[`.env.production.example`](../.env.production.example)** as the checklist of production keys. Highlights:

| Area | Variables (examples) |
|------|----------------------|
| Core | `APP_KEY`, `APP_URL`, `APP_DEBUG=false`, `ADMIN_HOST` |
| Database | `DB_*`, Coolify `SERVICE_PASSWORD_DB` |
| Proxy HMAC | `PROXY_SHARED_SECRET` / `SERVICE_BASE64_64_PROXY` (must match Node + Laravel) |
| Redis | `REDIS_HOST`, used by Laravel and Node proxy (`REDIS_URL` on proxy) |
| Coolify sync | `COOLIFY_API_TOKEN`, `COOLIFY_APP_UUID`, `COOLIFY_INSTANCE_URL` |
| Observability | `SENTRY_*`, `GLITCHTIP_*` |
| Logging | Prefer `LOG_STACK=daily` and `LOG_DAILY_DAYS` (see example file) |

**GitHub Actions** (optional): set `COOLIFY_API_TOKEN` and optional `PROD_LARAVEL_UP_URL` / `PROD_PROXY_HEALTH_URL` for deploy-gate checks (see [`.github/workflows/deploy-gate.yml`](../.github/workflows/deploy-gate.yml)).

## 3. Workers, scheduler, and scanner

- **Queue**: `php artisan queue:work` — the main **`queue-worker`** service consumes the **`default`** queue (outbound webhooks, Coolify sync, etc.); separate workers use `health`, `observability`, `scanner`.
- **Schedule**: Laravel scheduler must run every minute (`schedule:run` via Coolify cron or `schedule:work` long-running).
- **Scanner worker**: uses Chromium/Puppeteer; resource limits in compose — do not run heavy scans on the same container as the proxy if possible.

## 4. First deploy checklist

1. Run migrations: `php artisan migrate --force`.
2. Create admin user / run seeders as per your runbook.
3. Confirm **`GET /up`** (admin) and proxy **`/health`** on the edge URL.
4. Optional: `GET /api/healthz` for DB/Redis/storage checks.
5. Configure **Traefik** / Coolify labels so HTTP(S) hits port **80** on Laravel (not PHP-FPM 9000) — see comments in `docker-compose.yaml`.
6. Align **proxy routing** (`PROXY_RULE` / Coolify sync) with customer domains.

## 5. Backups and data lifecycle

- **Database**: `spatie/laravel-backup` is configured; ensure cron can run `backup:run` and that dumps are stored off-node (S3 or volume snapshots).
- **Redis**: Compose enables **AOF** for durability of cache metadata; treat as cache, not primary store.
- **Consent logs**: retention via `consent_retention_days` per group; scheduled purge + optional Filament action (super_admin).

## 6. Upgrades

1. Pull new image / redeploy from `main` (or pinned tag).
2. `php artisan migrate --force`.
3. `php artisan config:cache` / `route:cache` in production as you normally would.
4. Bust proxy cache if required (Laravel publishes config version / hooks — see `docs/ops/manifest.md`).

### Outbound webhooks (Coolify / SSH — im Laravel-Container)

Nach einem Deploy mit der `webhook_endpoints`-Migration:

1. **Migration** (falls noch nicht gelaufen):
   ```bash
   php artisan migrate --force --no-interaction
   ```
2. **Filament Shield** — Rechte für die neue Ressource anlegen (lokal oder auf dem Server dieselbe Codebasis):
   ```bash
   php artisan shield:generate -n --panel=admin --resource=WebhookEndpointResource --ignore-existing-policies --relationships
   ```
   Danach in **Shield → Rollen** die neuen Permissions den Mandanten-Rollen **admin** / **editor** zuweisen, falls ihr nicht nur mit `super_admin` arbeitet (`super_admin` umgeht die Policy per Gate in der Regel).
3. **Queue** — Der Service **`queue-worker`** in `docker-compose.yaml` / `coolify/admin` startet `queue:work` **ohne** `--queue=…` und verarbeitet damit die Queue **`default`**, auf der `DeliverOutboundWebhook` liegt. Stelle sicher, dass dieser Container **läuft und healthy** ist (Restart nach Deploy).

## 7. Related documentation

- [Proxy setup](proxy-setup.md) — DNS, origin, TLS from the customer’s perspective  
- [Troubleshooting FAQ](troubleshooting-faq.md) — 419, CI, GlitchTip, consent API  
- [Platform compatibility matrix](platform-compatibility-matrix.md) — certified stacks (living document)  
- [Custom services & webhooks](custom-services-webhooks.md) — Filament-Services, Blocker-Ressourcen, Stripe-Webhooks (Cashier)  
- [Migration from other CMPs](migration-guide.md)  
- [Manifest / rollback runbook](ops/manifest.md)  
- [API reference](api-reference.md) — public `/api` routes  

## 8. Support expectations

Self-hosted operators should monitor **disk**, **MySQL slow log**, **Redis memory** (`/statsz` on proxy where exposed), and **GlitchTip** (or Sentry) for Laravel + Node. For platform-specific CMS behaviour, use the compatibility matrix and add findings as you certify stacks.
