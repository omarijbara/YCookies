# YCookies — Test plan (traceability)

This document maps automated checks to product areas. Update it when you add major features or CI jobs.

## Continuous integration (GitHub Actions)

| Workflow | Trigger | Scope |
|----------|---------|--------|
| `ci-cd.yml` (name: **Deploy Gate**) | Push / PR to `main`; push tags `v*` (ignores changes under `docs/**`, `**/*.md`, `CHANGELOG*`) | **Quality Gates:** `php artisan test`, PHPStan (`phpstan.neon` + baseline), `npm run test:unit` (Vitest), `services/proxy` `npm ci && npm test`. **Non-blocking (continue-on-error):** Dusk against `php artisan serve :8001`, Lighthouse CI on `http://localhost:8001/`. **Deploy job:** optional Coolify GET deploy when `COOLIFY_APP_UUID_STAGING` / `COOLIFY_APP_UUID_PROD` + `COOLIFY_API_TOKEN` are set. |

**Playwright** (`e2e/*.spec.ts`, `npm run test:e2e`) is not in `ci-cd.yml` by default — run locally or add a dedicated workflow.

## PHP (PHPUnit)

| Suite | Path | Covers |
|-------|------|--------|
| Unit | `tests/Unit/` | Jobs, runtime helpers, rate limiting, metrics, … |
| Feature | `tests/Feature/` | HTTP APIs, Filament/Livewire, consent ingest, runtime manifest, Coolify, scanner, outbound webhooks (`OutboundWebhookTest`), CSP headers on preview (`CspHeadersTest`), GDPR lifecycle (`GdprServiceTest`), … |
| Browser (Dusk) | `tests/Browser/` | Not in default `phpunit.xml`; local: `php artisan dusk` with `.env.dusk.local`; CI step is best-effort (ChromeDriver/seed not fully wired in Deploy Gate). |

## JavaScript

| Runner | Path | Covers |
|--------|------|--------|
| Vitest | `resources/js/tests/*.test.js` | Consent manager (`manager.js`): GCM mapping, cookies, URL overrides, TCF stub behaviour |
| Proxy | `services/proxy/test/` | HTML transform, cache, SSRF, WebSocket, circuit breaker, … |

## End-to-end

| Tool | Path | Notes |
|------|------|-------|
| Playwright | `e2e/*.spec.ts` | Admin, API, proxy, onboarding; run `npm run test:e2e` (often against staging/prod `BASE_URL`). |

## Manual / checklist-aligned (not fully automated)

- Platform matrix (WordPress, Shopify, Next.js, strict CSP) — `docs/platform-compatibility-matrix.md` + launch checklist; certify stacks and update both.
- OWASP ZAP — optional; not wired in `ci-cd.yml`.
- k6 Baselines — lokal: `k6 run services/proxy/test/k6-load-suite.js` (optional `--out json=…`); Schwellen im Runbook festhalten. Optional: `cd services/proxy && npx playwright test test/playwright-matrix.spec.mjs`.
- CSP enforcement against real customer pages — verify per deployment; Spatie CSP is configured in-app.

## Related docs

- `docs/launch-checklist-status.md` — close out Notion launch items against the repo
- `docs/getting-started.md`, `docs/proxy-setup.md`, `docs/self-hosting.md`, `docs/platform-compatibility-matrix.md`, `docs/custom-services-webhooks.md`, `docs/migration-guide.md`
- `docs/ops/manifest.md` (runbook)
- `docs/ops/backup-restore.md` (Spatie backup + Restore-Smoke)
- `docs/gdpr-dsar-outline.md` (DSAR / Löschung — Prozessrahmen + Artisan)
- `.env.production.example` (production defaults)
