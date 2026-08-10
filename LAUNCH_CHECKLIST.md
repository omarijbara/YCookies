# YCookies — Final Detailed Launch Checklist (v1.0)

> **Tracking:** Prefer the live **[Notion Launch Checklist (v1.8)](https://www.notion.so/33205bf282248125b1efd88032e2d3ad)** for checkboxes. For **what is already implemented in code** vs what remains manual/Product, see **`docs/launch-checklist-status.md`**.

> **Audit date:** 2026-03-30
> **Release gate:** Nothing is shipped to broader production or tagged `v1.0` until **all P0 items are closed** and the **Final Verification** block passes.
> **Single source of truth** — mark `- [x]` when complete.

---

## RELEASE-GATE (P0) — Absolute blockers

> [!CAUTION]
> Close these first. No broader rollout or v1.0 tag until every P0 is `[x]`.

### 1. Full test-suite green

- [ ] **Fix all failing PHPUnit / Node tests and make CI green**
  - **Files:** `tests/*`, `AdminCrudTest.php`, `ConsentIngestTest.php`, `ScanDomainCookies.php`, `DomainProvisionCommand.php`, Service resource tests
  - [ ] Fix domain create/edit validation; update factories to supply required fields *(AdminCrudTest)*
  - [ ] Align `/api/log-consent` validation and model casts to accept boolean group values; handle XSS safe paths *(ConsentIngestTest)*
  - [ ] Resolve `Queueable` property conflicts in `ScanDomainCookies` job (rename conflicting props, implement `ShouldQueue`)
  - [ ] Fix `Service → domains()` relationship and Filament filters (pivot columns and `wherePivot` usage)
  - [ ] Replace PHPUnit doc-block metadata with PHP 8 attributes
  - **Commands:**
    ```bash
    vendor/bin/phpunit
    cd services/proxy && node --test
    ```
  - **Acceptance:** Local + CI runs pass; no failing tests on GitHub Actions
  - **Owner:** Dev1 / CI Owner

### 2. Decide & ship `domain:provision`

- [ ] **Scope decision & implementation**
  - [ ] Product owner determines if `domain:provision` is required in MVP — record decision in `RELEASE_SCOPE.md`
  - [ ] **If yes:** implement `DomainProvisionCommand.php`, write unit/integration tests, wire into admin flows and installer
  - [ ] **If no:** remove command and associated tests; update docs
  - **Acceptance:** Tests pass and admin flow is consistent with scope decision
  - **Owner:** Product (decision) / Dev1 (implementation)

### 3. Merge Phase 1c PR — scanner-worker isolation

- [ ] **Merge & validate Phase 1c**
  - [ ] Merge PR that isolates scanner-worker to its own container, fixes scheduler, adds health endpoints
  - [ ] **Staging validation:** scanner runs without affecting default worker; scheduled scans run; health endpoints respond 200
  - **Acceptance:** Staging smoke passing; no restart storms observed
  - **Owner:** Dev1 / Ops

### 4. Manifest runtime safety (critical)

> [!WARNING]
> These three are the manifest/runtime gating items from the migration review. Must be closed before broad rollout.

- [ ] **Fail-closed manifest verification**
  - **What:** Node proxy must verify signed manifest and artifact hashes — refuse to use unverifiable manifest (no silent fallback to legacy DB assembly)
  - **Where:** `services/proxy/manifest-consumer.js`, `manifest-verifier.js`, Laravel `RevisionSigner` / `RevisionPublisher`
  - **Acceptance:** If signature or artifact hash fails verification, proxy returns hard error or passive fail-safe (not silently using legacy config); runtime metrics increment `verification_failures`
  - **Evidence:** Produce deliberately-bad signed manifest → confirm proxy refuses it
  - **Owner:** Dev2 (design + review) / Dev1 (implementation)

- [ ] **RevisionResolver cache invalidation on publish/rollback**
  - **What:** `RevisionPublisher` must call `RevisionResolver::invalidate(domain)` (or equivalent) after commit and on rollback so `manifest_resolved:{domain}` is not stale for up to 300s
  - **Where:** `app/Runtime/Publisher/RevisionPublisher.php`, `app/Runtime/Consumer/RevisionResolver.php`
  - **Acceptance:** Publish → immediate `GET /api/proxy-config/{host}` returns new revision; no 5-minute lag. Unit test asserting immediate resolution
  - **Owner:** Dev1

- [ ] **Static-loader-first hot path (emit `static_loader_url`)**
  - **What:** `DomainCompiler` must produce `base_artifact.bootstrapper.static_loader_url` with immutable static loader; proxy `html-injector.js` must prefer static loader over dynamic `/api/script`
  - **Where:** `app/Runtime/Compiler/DomainCompiler.php`, `services/proxy/html-injector.js`
  - **Acceptance:** Production canary HTML injects static loader URL; `/api/script` is fallback only; static loader served as immutable asset
  - **Owner:** Dev1 / Dev2 for review

### 5. Publish/rollback drill on blocker-bearing canary

- [ ] **Run publish/rollback drills on a real blocker-bearing canary**
  - [ ] Set up second canary domain with non-empty `script_blockers` + `content_blockers` + multilingual/theme
  - [ ] Observe: publish → immediate consumption by proxy, blocker enforcement on page
  - [ ] Observe: rollback → immediate reversion
  - [ ] Verify `/api/proxy-config/{host}`, `/api/script/{site}.js`, `/api/config/{site}`, proxy logs, `/statsz` before/after transitions
  - **Acceptance:** No silent fallback; blocker enforcement proven; pointer changes immediate
  - **Owner:** Dev2 / Ops

---

## PHASE 1 — Infrastructure Stabilization

> Complete all infra tasks before broadening rollout.

### DB migration and backup

- [ ] Pre-migration backup (`mysqldump` or `spatie/laravel-backup`)
- [ ] Run migrations in staging: `php artisan migrate --env=staging`
- [ ] Restore validation: restore from backup into fresh DB → run `php artisan migrate:status` + full test suite
- **Acceptance:** Migration runs clean; restore is successful

### ADMIN_HOST rename

- [x] Replace `LARAVEL_HOST` with `ADMIN_HOST` in `docker-compose.yml`, `entrypoint.sh`, `server.js`, env templates, Coolify variables
- [ ] Validate admin UI accessible at new host
- **Acceptance:** Admin functions accessible; CI updated

### MySQL & backup automation

- [ ] Configure scheduled `mysqldump` or `spatie/laravel-backup` with retention policy
- [ ] Automate verification job: `php artisan backup:run --only-db` + `php artisan backup:list`
- **Acceptance:** Automated backups present; restore verified at least once

### Staged rollout / first-request stampede mitigation

- [ ] Implement staggered warmup after deploy: `proxy:flush-cache` → staggered refetches or `RevisionPublisher` post-publish warmup job
- [ ] Add health-check and circuit-breaker to avoid stampedes (backoff on origin during high error rates)
- **Acceptance:** Deploy warmup runs; origin load spike handled in staging

### Scanner-worker isolation

- [ ] Phase 1c PR merged; container + resource limits set in `docker-compose.yml`; scheduler fixed
- [ ] Health endpoint: `GET /healthz` or `php artisan runtime:check`
- **Acceptance:** Scanner container independent; no impact to default worker

---

## PHASE 2 — Consent Widget Hardening

> The widget is product-critical. Fix compliance and UX.

### Google Consent Mode v2

- [ ] Inject `gtag('consent','default', {...})` as early as possible on page load (static loader)
- [ ] Call `gtag('consent','update', {...})` on user actions and consent changes
- [ ] Map `CookieGroup` → GCM signals (`ad_storage`, `analytics_storage`, `ad_user_data`, `ad_personalization`)
- **Tests:** GA4/GTM debug shows consent signals matching user choices

### IAB TCF v2.3 client-side

- [ ] `__tcfapi` window function implemented; responds to common CMP calls
- [ ] Generate TC String using `@iabtechlabtcf/cmpapi` or equivalent on consent → `POST /api/tcf/record`
- **Tests:** TC string present and recorder accepts it; GVL load works (`GET /api/tcf/gvl`)

### GPC handling

- [ ] Read `navigator.globalPrivacyControl` and `Sec-GPC` request header server-side
- [ ] If GPC set, auto-reject non-essential cookies and reflect banner state
- **Acceptance:** Manual test and automated E2E confirm GPC path

### Widget code quality & UX

- [ ] **Build:** Minify/tree-shake `manager.js` (currently 115KB), output source maps for debug
- [ ] **Unit tests:** Jest/Vitest for consent logic and persistence
- [ ] **Accessibility:** WCAG 2.1 AA — keyboard nav, ARIA labels, focus trapping
- [ ] **3-layer consent precedence:** Instance → provider → category controls and provider placeholders for embeds
- **Tests:** Unit + Dusk/Playwright scenarios for banner behavior across browsers

---

## PHASE 3 — Proxy Maturity

> Stability, performance, and compatibility.

### Page-level response caching (nonce-aware)

- [ ] Implement cache-of-HTML-with-placeholders; per-request nonce insertion
- [ ] Cache key: `host + path + query` (route fingerprinting via `route-fingerprint.js`)
- [ ] TTL invalidation on `config_version` changes (`RevisionPublisher` post-publish hook flush/invalidate)
- **Acceptance:** Cached pages served < 100ms TTFB in benchmark

### WebSocket passthrough

- [ ] Node proxy accepts `Connection: Upgrade` + `Upgrade: websocket` and forwards frames
- **Acceptance:** WordPress live preview / other WS flows function through proxy

### Platform compatibility matrix

- [ ] Test 10 target platforms:
  - [ ] WordPress (Plesk) ✅
  - [ ] WordPress (Cloudflare-fronted)
  - [ ] Shopify (output HTML)
  - [ ] Next.js SSR
  - [ ] Nuxt SSR
  - [ ] React SPA (client-only)
  - [ ] Hugo / Jekyll static
  - [ ] Strict CSP + `strict-dynamic`
  - [ ] Brotli / gzip / deflate origins
  - [ ] Large pages (>5MB HTML) + IPv6 origins
- **Acceptance:** Document issues and provide mitigations for each

### Resiliency & circuit breaker

- [ ] If mutation fails, fallback to pass-through unchanged HTML
- [ ] Add structured GlitchTip error logging for mutations
- **Acceptance:** Mutation failure does not break origin traffic; errors logged

### Performance tuning

- [ ] Tune `agent-pool.js` connection pool; configure Redis memory and monitor
- **Acceptance:** Benchmark TTFB < 100ms for cached pages; baseline recorded

---

## PHASE 4 — Admin Panel Polish & Features

> Finish admin UX and SaaS primitives.

### Dashboard & quick actions

- [ ] Implement widgets: domain count, active consent rate, scan summary
- [ ] Quick-action cards: "Add Domain", "Run Scan", "View Alerts"
- [ ] Traffic chart (last 7/30 days) from `traffic_metrics`
- **Acceptance:** Admin dashboard visible with accurate data

### Billing & subscription enforcement

- [ ] Define plans: Free / Pro / Agency with concrete limits (domains, scans) — **Product decision required**
- [ ] Enforce limits in domain creation, scanner, proxy enabling
- [ ] Implement Stripe webhooks (`payment_failed`, `subscription_cancelled`) and grace periods
- [ ] Usage metering display (domains used / allowed)
- **Acceptance:** Billing flows tested end-to-end; webhook handlers tested

### Agency onboarding

- [ ] End-to-end wizard flow for tenant/group creation, domain provisioning
- [ ] Email verification step
- [ ] Default template pre-population with best-practice config
- **Acceptance:** E2E tests verify complete wizard path

### Package Library & i18n

- [ ] Batch update: "Update all outdated packages" action
- [ ] Auto-update notifications via SMTP when template versions change
- [ ] Translation coverage for all `__('ycookies.*')` labels
- [ ] Admin panel language switcher (EN, DE, AR)
- [ ] RTL layout validation for Arabic
- **Acceptance:** Test template installation + updates; UI translations complete

### Domain Form UX

- [ ] Proxy setup wizard with DNS instructions, visual verification, one-click enable guardrails
- [ ] Show current A/CNAME records vs expected
- **Acceptance:** Wizard works with real DNS check

### Consent Logs & User management

- [ ] Export consent logs (CSV/JSON)
- [ ] Aggregated consent rate dashboard (% accepted per category over time)
- [ ] GDPR purge: "Purge consent logs older than X months"
- [ ] Invitation system (invite team members to a Group)
- [ ] Role-based access (Admin, Editor, Viewer) — permissions tables exist
- [ ] Password reset flow
- **Acceptance:** Admin can export logs and purge per policy; invitations and roles work

---

## PHASE 5 — Testing & CI/CD

> Make CI authoritative and trustworthy.

### CI pipeline

- [ ] Build GitHub Actions workflow:
  - [ ] PHPUnit (PHP 8.2 + MySQL service container)
  - [ ] Node proxy tests (`node --test` — all 256+ tests)
  - [ ] ESLint / code style checks
  - [ ] PHPStan / Larastan static analysis
  - [ ] Docker build verification (both `Dockerfile.laravel` and `services/proxy/Dockerfile`)
- [ ] Gate merges on tests + static analysis
- [ ] Auto-deploy to staging on PR merge; auto-deploy to production on tag/release
- **Acceptance:** PRs require passing tests + static analysis to merge

### E2E & cross-browser

- [ ] Laravel Dusk E2E tests:
  - [ ] Admin login flow
  - [ ] Domain CRUD (create → edit → proxy enable → delete)
  - [ ] Package Library: install template → verify services created
  - [ ] Cookie bar designer: change settings → preview
  - [ ] Scanner: trigger scan → verify results appear
  - [ ] Consent flow: proxy page → banner interaction → consent logged
- [ ] Playwright cross-browser (Chrome, Firefox, Safari, mobile viewports)
- **Acceptance:** E2E flows pass in CI on staging

### Performance & security testing

- [ ] k6 load tests (`k6-load-suite.js`) — establish baselines
- [ ] Concurrent domain load simulation
- [ ] OWASP ZAP scan wired to CI (rules file: `.github/zap-rules.tsv`)
- [ ] Lighthouse CI for performance/accessibility scoring
- [ ] `TEST_PLAN.md` maintained and mapped to test coverage
- **Acceptance:** Security scans produce no P1 vulnerabilities

---

## PHASE 6 — Documentation & Onboarding

> Deliver customer and developer docs.

### User documentation

- [ ] Getting Started guide (install → first domain → first scan)
- [ ] Proxy Mode setup guide (DNS, origin URL, SSL, verification)
- [ ] CookieBar Designer guide (colors, typography, triggers, translations)
- [ ] Package Library guide (search, install, update, custom services)
- [ ] Google Consent Mode v2 integration guide
- [ ] IAB TCF v2.3 setup guide
- [ ] Troubleshooting FAQ
- **Acceptance:** Docs hosted and linked from admin panel and repo

### Developer documentation

- [ ] API reference (all endpoints with request/response examples)
- [ ] Custom service/blocker creation guide
- [ ] Webhook integration guide (consent events)
- [ ] Self-hosting guide (Docker Compose + Coolify)
- **Acceptance:** Developer guide present and tested with sample API calls

### Installer verification

- [ ] Verify `coolify-installer.sh` on fresh VPS in both interactive and non-interactive modes
- [ ] Include post-install health check
- [ ] Migration guide from competing CMPs (Cookiebot, CookieYes)
- **Acceptance:** Fresh VPS installation succeeds end-to-end

### Release notes

- [ ] `CHANGELOG.md` with semantic versioning
- [ ] GitHub release with migration notes and upgrade instructions
- **Acceptance:** v1.0 release prepared with full changelog

---

## PHASE 7 — Security Audit & Hardening

> [!CAUTION]
> Security is blocking. As a CMP handling GDPR consent data, this is non-negotiable.

### CSRF / SQL / XSS reviews

- [ ] Verify all Filament forms use CSRF / Livewire CSRF
- [ ] Audit all Blade templates: no `{!! !!}` for untrusted content
- [ ] Audit `ScriptScannerService.php` (54KB — largest service, Puppeteer-driven)
- [ ] Audit widget JS: no user-supplied content injected without sanitization
- [ ] Audit proxy `html-injector.js`: injected content is always safe
- **Acceptance:** No use of `{!! !!}` for untrusted content; fixes applied

### SSRF & HMAC

- [ ] Audit `UrlValidator.php` — private/reserved IP blocking, DNS rebinding protections
- [ ] Audit `ssrf.js` coverage for all fetch paths in Node proxy
- [ ] HMAC secret rotation test: rotate keys → confirm 24h grace period works in manifest verification
- **Acceptance:** SSRF mitigated; HMAC rotation tested end-to-end

### Privacy compliance

- [ ] Consent log retention policy (auto-purge after configurable period)
- [ ] IP hashing in consent logs
- [ ] Data deletion/export capability
- [ ] GDPR Article 30: processing activities record
- [ ] Data Processing Agreement (DPA) template for customers
- **Acceptance:** Legal/compliance sign-off

### Dependencies

- [ ] `composer audit` — no critical PHP vulnerabilities
- [ ] `npm audit` — no critical Node vulnerabilities
- [ ] Pin critical dependency versions
- **Acceptance:** No critical unpatched advisories

---

## PHASE 8 — Launch Readiness & Closeout

> Prepare production, monitoring, and marketing.

### Production hardening

- [ ] Set `APP_DEBUG=false`, `APP_ENV=production` in env templates
- [ ] Rate limits tuned and tested (currently 200 req/min per IP)
- [ ] Redis `maxmemory` policy configured
- [ ] MySQL `max_connections` + `innodb_buffer_pool_size` tuned
- **Acceptance:** Production environment passes health checks and load tests

### Monitoring & runbooks

- [ ] GlitchTip error tracking verified (Laravel + Node proxy)
- [ ] External uptime monitoring for `cookies.ypsilon.dev` and proxy domains
- [ ] Disk space / CPU / memory alerting thresholds configured
- [ ] **Runbooks written:**
  - [ ] Manifest rollback procedure
  - [ ] Signing key rotation procedure
  - [ ] Queue failure recovery
  - [ ] Incident response playbook
- **Acceptance:** Ops team rehearsal passes

### Backups & TLS

- [ ] `spatie/laravel-backup` runs + restore test passes
- [ ] MySQL volume snapshot plan documented
- [ ] Redis persistence (RDB/AOF) verified
- [ ] Let's Encrypt auto-renewal verified for all domains
- [ ] Wildcard certificate strategy if required for scale
- **Acceptance:** Restore and Let's Encrypt auto-renew tested

### Marketing & final deploy

- [ ] Landing page (product marketing site)
- [ ] Pricing page with tier comparison
- [ ] Demo/trial setup (free tier sandbox)
- [ ] Beta customer outreach (beyond duftz.de and barbershop-dibo.de)
- [ ] Final deploy checklist executed (`/deploy` workflow)
- [ ] Tag `v1.0.0` and publish GitHub release
- [ ] Notify customers
- **Acceptance:** v1.0 release published and customers notified

---

## FINAL VERIFICATION — Release Criteria

> [!IMPORTANT]
> **All must be true before tagging v1.0.**

- [ ] Full test suite passes (unit, integration, Node tests, E2E)
- [ ] One real domain can: onboard → publish manifest → log consent → scan successfully
- [ ] No open P1 or P2 bugs
- [ ] Manifest verification is fail-closed; resolver invalidation works; static loader flow proven
- [ ] Ops can recover/rollback without developer intervention using runbooks
- [ ] Security audit passed (or remaining items are low-risk and documented)
- [ ] Documentation and runbooks published
- [ ] CI gates and automatic deploy rules validated

---

## Open Decisions (record in `RELEASE_SCOPE.md`)

- [ ] **Billing tiers and enforcement** — Free / Pro / Agency limits — *Owner: Product*
- [ ] **TCF v2.3 requirement** — required for v1.0 or post-launch? — *Owner: Product / Security*
- [ ] **Self-hosting requirement** — installer required for v1.0? — *Owner: Product / Ops*
- [ ] **v1.0 scale target** — how many proxied domains? — *Owner: Product / Ops*
- [ ] **Widget-first vs Admin-polish priority** — *Owner: Product*

---

## CI / PR Rules (must be enforced)

- [ ] Every PR must: pass PHPUnit, Node tests, ESLint, PHPStan/Larastan, and Docker build verification
- [ ] Any change to manifest pipeline must include integration test simulating publish + verifying resolver invalidation + manifest verification metrics
- [ ] Doc changes (API or installer) must include a smoke script for verification

---

## Smoke Scripts / Commands (copy-pasteable)

### Run full test suite

```bash
# PHP unit
composer install
vendor/bin/phpunit --testsuite=Unit,Feature

# Node tests (proxy)
cd services/proxy
npm ci
node --test
```

### Publish + immediate verify

```bash
# Publish a manifest revision
php artisan runtime:compile-and-publish <domain_or_job_args>

# Immediately query
curl -s -H "Accept: application/json" \
  "https://cookies.ypsilon.dev/api/proxy-config/<host>" | jq .

# Verify resolver invalidation
php artisan manifest:resolver:invalidate <domain>

# Validate proxy behavior
curl -s "https://<canary-host>/" | head -n 200
```

### Test failure handling

```bash
# Create a bad manifest (tamper signature) and verify proxy rejects
# (fetch published manifest, flip bytes, serve to endpoint proxy reads)
```

### Backup & restore

```bash
# Run backup
php artisan backup:run --only-db

# List backups
php artisan backup:list

# Restore (follow spatie docs or mysqldump restore)
```

---

## Suggested Owners & Roles

| Role | Responsibility |
|------|---------------|
| **Dev1** | Implementation: compiler/publisher, Laravel runtime, unit tests, Phase 1-3 tasks |
| **Dev2** | Reviewer: manifest/security/Node proxy architecture |
| **Ops** | Staging/prod deployment, backups, installer, monitoring |
| **QA** | Test-suite ownership, Dusk/Playwright, acceptance testing |
| **Security** | Audits (XSS/SSRF/HMAC), compliance sign-off |
| **Product** | Decision owner: scope, billing, launch readiness |

---

## Timeline Suggestion

1. **Finish P0 items first** (tests + manifest runtime items) — typically 1–3 sprints of focused engineering
2. **Run at least two full publish/rollback drills:** one on staging, one on blocker-bearing canary
3. **After P0 closes:** run full security audit and CI gating
4. **Do not tag v1.0** until Final Verification block passes and Product + Security sign off
