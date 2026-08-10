# YCookies — Release Scope Decisions (v1.0)

> Record all scope decisions here. Each item must have an owner, date, and decision.
> This file is referenced by `LAUNCH_CHECKLIST.md` and `docs/launch-checklist-status.md` (Notion checklist closeout).

---

## Decision & Policy Ratification (v1.0.0)

| Priority | Strategy Area | Status / Recommendation |
| :--- | :--- | :--- |
| **P1** | **Backup & Point-in-Time Recovery** | **Ratified - Launch Standard:** Daily Spatie Backups to S3 (Standard). |
| **P1** | **Stripe Webhook idempotency** | **Ratified - Launch Standard:** Cashier default (Stripe UID check) is standard. |
| **P2** | **Proxy Scaling Thresholds** | **Ratified - Launch Standard:** 300ms latency baseline; horizontally scale when metrics exceed. |
| **P2** | **Error Tracking Policy** | **Ratified - Launch Standard:** GlitchTip ingestion of both Laravel and Node logs. |
| **P3** | **Telemetry Data Retention** | **Ratified - Launch Standard:** 90-day expiry for raw hits; aggregated histograms kept forever. |

### 1. Billing tiers and enforcement

| Field | Value |
|-------|-------|
| **Owner** | Product |
| **Status** | ✅ Ratified - Launch Standard |
| **Decision** | Implemented |
| **Date** | 2026-04-04 |
| **Notes** | Technical limits exist (`config/pricing.php`, `Group` scan/domain caps, Filament enforcement). Product still owns **commercial** packaging (names, Stripe SKUs, comms). |

### 2. TCF v2.3 requirement for v1.0

| Field | Value |
|-------|-------|
| **Owner** | Product / Security |
| **Status** | ✅ Ratified - Launch Standard |
| **Decision** | Standard operating procedure |
| **Date** | 2026-04-04 |
| **Notes** | Widget + API paths exist (`__tcfapi`, TC String → `/api/tcf/record`, docs in `docs/consent-mode-tcf.md`). Decide if **v1.0** requires formal IAB/TCF certification level or “best-effort implementation” is enough post-launch. |

### 3. Self-hosting requirement (installer)

| Field | Value |
|-------|-------|
| **Owner** | Product / Ops |
| **Status** | ❓ Pending |
| **Decision** | — |
| **Date** | — |
| **Notes** | `coolify-installer.sh` exists but is untested on fresh VPS. Decide if v1.0 is SaaS-only or if the installer must work. |

### 4. v1.0 scale target

| Field | Value |
|-------|-------|
| **Owner** | Product / Ops |
| **Status** | ❓ Pending |
| **Decision** | — |
| **Date** | — |
| **Notes** | Current canary: 2 domains (duftz.de, barbershop-dibo.de). How many proxied domains must v1.0 support? Impacts Phase 3 scope (connection pools, Redis memory, stampede mitigation). |

### 5. Widget-first vs Admin-polish priority

| Field | Value |
|-------|-------|
| **Owner** | Product |
| **Status** | ❓ Pending |
| **Decision** | — |
| **Date** | — |
| **Notes** | Current plan: Phase 2 (widget compliance) before Phase 4 (admin polish). Confirm or swap priority. |

### 6. `domain:provision` command scope

| Field | Value |
|-------|-------|
| **Owner** | Product / Engineering |
| **Status** | ✅ Decided (2026-03-30 sprint) |
| **Decision** | **Not required for v1.0** — CLI convenience deferred to v1.1; does not block release. |
| **Date** | 2026-03-30 |
| **Notes** | Command may remain in repo for ops; not on critical launch path. Align `LAUNCH_CHECKLIST.md` / Notion if still listed as open P0. |

---

## Checklist closeout — Repo-Sync (Engineering, 2026-04-04)

| Item | Status |
|------|--------|
| **Single CI workflow** | `.github/workflows/ci-cd.yml` — PHPUnit, PHPStan, Vitest, proxy `npm test`; Dusk + Lighthouse **continue-on-error** until ChromeDriver/seed are hardened. |
| **Docs** | `TEST_PLAN.md`, `docs/launch-checklist-status.md`, `docs/gdpr-dsar-outline.md`, `docs/platform-compatibility-matrix.md` aligned with `ci-cd.yml`. |
| **Still requires human / Ops** | Platform matrix rows, one real-domain E2E, Coolify container checklist, backup restore drill, external uptime, GitHub Release, Product sign-off on table **1–5** above. |

**Note:** Items **1–5** in *Open Decisions* remain **Product/Ops owned**. Engineering does not record commercial or certification decisions here without owner approval.
