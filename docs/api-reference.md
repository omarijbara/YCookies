# YCookies API Reference

Base URL for all routes below: **`https://<your-admin-host>/api`** (e.g. `https://cookies.ypsilon.dev/api`).

Laravel applies the `api` middleware group (no session CSRF; use throttling-aware clients). **Admin CRUD** (domains, scans, billing) is handled in **Filament**, not via a public `/api/v1/...` REST surface.

---

## Health & operations

### `GET /api/healthz`

Deep health check: database, Redis, storage writable.

**Response (200 healthy / 503 degraded):** JSON with `status`, `checks`, `timestamp`.

---

## Consent widget & configuration (public, tenant-scoped)

### `GET /api/config/{site_id}`

JSON consent configuration for the domain (`site_id`). May use manifest projection when enabled.

- **Throttle:** `api-tenant` (see `AppServiceProvider`).

### `GET /api/script/{site_id}.js`

Bundled manager script + injected `window.YCookies.config`. `site_id`: UUID or numeric id (see route `where`).

### `GET /api/boot/{site_id}.js`

Synchronous bootstrapper (dynamic script blocklist).

### `GET /api/hub/{site_id}`

HTML/JS for cross-domain consent hub.

### `POST /api/log-consent`

Ingest consent from the widget (beacon / `sendBeacon` may use `text/plain` body).

**JSON body (typical):**

| Field | Type | Notes |
|--------|------|--------|
| `site_id` | string | Required |
| `uid` | string | Optional visitor consent UID |
| `consent` | object | Required |
| `consent.type` | string | `all`, `essential`, `custom`, `explicit`, `renewed` |
| `consent.groups` | object | Optional map of group key → boolean |
| `consent.services` | string[] | Optional service keys |
| `cookie_version` | int | Optional |
| `tc_string` | string | Optional IAB TC string |

**Responses:** `200` `{ "status": "ok" }`, `400` / `422` / `404` with JSON error payload.

---

## IAB TCF

### `GET /api/tcf/gvl`

Cached Global Vendor List payload for the CMP.

### `POST /api/tcf/record`

Attach or record a TC string (audit).

**JSON body:**

| Field | Type |
|--------|------|
| `site_id` | string, required |
| `tc_string` | string, required |
| `uid` | string, optional |

**Throttle:** 60/min per route config.

---

## Telemetry

### `POST /api/rum/beacon`

Browser RUM payload. **Throttle:** 60/min.

### `POST /api/metrics/batch`

Node proxy batch metrics. **Auth:** HMAC middleware `proxy.hmac` (server-to-server).

---

## Proxy (Node ↔ Laravel)

### `GET /api/proxy-config/{host}`

Signed proxy configuration for `host`. **Auth:** `VerifyProxyConfigSignature` (middleware `proxy.config.signature`).

### `GET|HEAD /api/proxy-config/healthcheck`

Empty response for internal probes.

### `GET /api/proxy/health`

Laravel-side check that reaches the Node proxy container.

### `POST /api/proxy/sync-domains`

Triggers Coolify domain sync. **Header:** `X-Proxy-Secret` must match `PROXY_SHARED_SECRET` / configured shared secret.

---

## Outbound webhooks (no public route)

YCookies can **POST** signed JSON to URLs you configure per tenant (**System → Webhooks** in Filament). This is **outbound** from your server to customer infrastructure (e.g. `scan.completed` after a scan). It is **not** listed in `routes/api.php`. Details: [custom-services-webhooks.md](custom-services-webhooks.md).

---

## Environment & security notes

- **Rate limits:** `api-tenant` is 200 req/min per `site_id` (or IP fallback); some routes use `60,1`.
- **CORS:** Script and chunk routes set permissive origins where needed for embeds; lock down at reverse proxy if required.
- **Coolify API** (`/api/v1/...` on your Coolify host) is **not** YCookies — see `coolify-healthcheck.sh` and `docs/self-hosting.md`.

---

## Related

- `routes/api.php` — source of truth for paths and middleware  
- `tests/Feature/` — many HTTP contracts covered (consent ingest, proxy config, TCF, …)
