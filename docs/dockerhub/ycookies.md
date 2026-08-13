# YCookies — Control Plane

**Self-hosted, enterprise-grade cookie consent management (CMP)** — your own Cookiebot/Borlabs alternative. This image is the Laravel 12 + Filament control plane: the admin panel, consent/config APIs, queue runner, and scheduler (nginx + PHP-FPM + cron under supervisord).

Companion images: [`ypsilondev/ycookies-proxy`](https://hub.docker.com/r/ypsilondev/ycookies-proxy) (consent reverse proxy, the data plane) · [`ypsilondev/ycookies-scanner`](https://hub.docker.com/r/ypsilondev/ycookies-scanner) (scan worker with Chromium).

📦 **Source, full documentation & compose stack:** https://github.com/omarijbara/YCookies

## Quick start

Use the ready-made compose stack (app + workers + proxy + MySQL + Redis):

```bash
git clone https://github.com/omarijbara/YCookies.git && cd YCookies/deploy
cp .env.example .env   # fill in the 5 required values
docker compose up -d
docker compose exec app php artisan db:seed --class=AdminUserSeeder
```

Admin panel on port `8080`, consent proxy on `8081` — put your own TLS terminator in front.

## Required environment

| Variable | Purpose |
|---|---|
| `APP_URL` | Public URL of the admin panel |
| `APP_KEY` | Laravel encryption key (`base64:...`) |
| `DB_HOST` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | MySQL 8 connection |
| `PROXY_SHARED_SECRET` | HMAC secret shared with the proxy image |

The container validates required env at boot and runs migrations automatically. Full environment reference: https://github.com/omarijbara/YCookies#%EF%B8%8F-environment-reference

## Tags

- `latest`, `X.Y.Z`, `X.Y` — releases
- `edge` — latest development build

Health check: `GET /up`. Runs as a queue worker instead of the web server when given a command, e.g. `php artisan queue:work --queue=default,health,observability`.

## License

[Elastic License 2.0](https://github.com/omarijbara/YCookies/blob/main/LICENSE) — free to use, modify, and self-host (including commercially); may not be offered to third parties as a hosted/managed service.
