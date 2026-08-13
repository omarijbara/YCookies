# YCookies — Consent Reverse Proxy (Data Plane)

The Node/Fastify reverse proxy that sits in front of your customers' websites: it injects the YCookies consent runtime into HTML, **blocks third-party tracking scripts server-side before consent**, streams everything else untouched, and reports edge metrics back to the control plane. Multi-tier config caching (RAM → Redis → HTTP → disk) keeps it serving even when the control plane is down.

Companion images: [`ypsilondev/ycookies`](https://hub.docker.com/r/ypsilondev/ycookies) (control plane) · [`ypsilondev/ycookies-scanner`](https://hub.docker.com/r/ypsilondev/ycookies-scanner).

📦 **Source, full documentation & compose stack:** https://github.com/omarijbara/YCookies

## Usage

Part of the [ready-made compose stack](https://github.com/omarijbara/YCookies/blob/main/deploy/docker-compose.yml). Standalone:

```bash
docker run -d -p 8081:80 \
  -e LARAVEL_URL=http://app:80 \
  -e PROXY_SHARED_SECRET=<same value as the control plane> \
  -e REDIS_URL=redis://redis:6379 \
  ypsilondev/ycookies-proxy
```

Point customer domains' DNS at this proxy (through your TLS terminator); it resolves each domain's config from the control plane automatically.

## Environment

| Variable | Required | Purpose |
|---|---|---|
| `LARAVEL_URL` | ✅ | Internal URL of the control plane |
| `PROXY_SHARED_SECRET` | ✅ | HMAC secret authenticating proxy ↔ control-plane traffic |
| `PROXY_PORT` | — | Listen port (default `80`) |
| `REDIS_URL` | — | Enables the shared config push-cache |
| `CONFIG_CACHE_TTL` | — | Config freshness window in seconds (default `300`) |
| `LOG_LEVEL`, `SENTRY_NODE_DSN` | — | Logging / error tracking |

Full reference: https://github.com/omarijbara/YCookies#%EF%B8%8F-environment-reference

## Tags

- `latest`, `X.Y.Z`, `X.Y` — releases
- `edge` — latest development build

Health check: `GET /health`.

## License

[Elastic License 2.0](https://github.com/omarijbara/YCookies/blob/main/LICENSE).
