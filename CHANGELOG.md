# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - Official Launch - 2026-04-05

### Added

- **GDPR & Privacy Compliance:** Full `GdprService` implementation for organizational data export and "Right to be Forgotten" (permanent deletion). Includes Artisan commands `ycookies:gdpr:export`, `ycookies:gdpr:delete`, and Filament UI actions.
- **Outbound Webhooks:** Multi-tenant webhook support via `webhook_endpoints`. Real-time notifications for scan completions with HMAC-SHA256 signatures (`X-YCookies-Signature`).
- **Agency Onboarding & Multi-Tenancy:** Streamlined group registration with automated domain/cookie bar provisioning.
- **Security & Integrity:** Automated Quality Gates in `ci-cd.yml` (PHPStan, Unit/Feature tests, Proxy tests). CSP hardening for previews and strict attribute protection in non-production.
- **Node.js Proxy Enhancements:** High-performance HTML streaming injection with circuit breaker fallbacks and rate limiting.
- **Package Library:** Verified community tracker definitions for rapid configuration.
- **Analytics & Reporting:** Consent statistics dashboard and automated traffic reporting.
- **Scanner:** Puppeteer-based headless crawling for deep tracker discovery.
- **Stripe & Billing:** Fully integrated subscription tier enforcement using Laravel Cashier.

### Changed

- **CI/CD Consolidation:** Migrated multiple workflows into a single `Deploy Gate` (`ci-cd.yml`) for higher reliability and faster feedback loops.
- **Documentation:** Full refresh of `docs/self-hosting.md`, `docs/migration-guide.md`, and the Platform Compatibility Matrix.
- **Global Attributes:** Enabled `Model::preventSilentlyDiscardingAttributes()` outside production to catch misconfigurations earlier.

### Fixed

- Resolved major Node.js proxy buffer splitting bugs affecting streamed responses.
- Fixed tenant-scoping issues in Filament widgets and global search.
- Corrected type-safe handling of PHP 8.2 variances in core models.
- Fixed mass-assignment vulnerabilities in Domain and Group models.
- Resolved race conditions in manifest revision allocation.

### Performance

- Added multi-layered caching for runtime proxy configurations (Redis + memory).
- Optimized traffic metrics aggregation using batched histogram processing.
- Reduced scan-worker overhead for high-traffic sites.
