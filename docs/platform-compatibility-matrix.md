# Platform compatibility matrix

Living document: mark stacks as you **certify** them in staging or production. “Reported” means community or one-off success without full regression; “Not tested” is the default.

| Stack | Status | Notes |
|--------|--------|--------|
| WordPress (Plesk, direct origin) | Not tested | Proxy + script modes; watch admin AJAX and `admin-ajax.php` paths |
| WordPress (Cloudflare in front) | Not tested | Confirm origin headers, cache levels, Rocket Loader / CF optimizations |
| Shopify (theme HTML) | Not tested | Often strict CSP; may need script mode or careful proxy routing |
| Next.js SSR / Nuxt SSR | Not tested | Streaming HTML + hydration; verify banner and GCM order |
| React / Vue SPA (client-rendered) | Not tested | Script/bootstrapper timing vs route changes |
| Hugo / Jekyll / static | Not tested | Usually script mode friendly; fewer SSR edge cases |
| Strict CSP + `strict-dynamic` | Not tested | See [Installation CSP note](getting-started.md) and [consent-mode-tcf.md](consent-mode-tcf.md) |
| Brotli / gzip / deflate origins | Not tested | Proxy must preserve encoding semantics |
| Large HTML (>5 MB) | Not tested | Memory and timeout tuning on proxy |
| IPv6-only origins | Not tested | `UrlValidator` / proxy origin resolution |

## How to certify a row

1. Pick a **representative site** (staging clone if possible).  
2. Enable **Proxy** or **script** mode per [proxy-setup.md](proxy-setup.md) / [getting-started.md](getting-started.md).  
3. Verify: banner, **GCM default/update**, consent POST, optional **TCF** `__tcfapi`, scanner on a sample URL.  
4. Run **Playwright** E2E against a canary if you have one, or manual checklist.  
5. Update the table: set **Status** to `Certified (YYYY-MM)` and add **Notes** (theme name, CSP snippet, caveats).

## Automation

- **GitHub Actions:** ein Workflow **`ci-cd.yml`** („Deploy Gate“) — siehe [TEST_PLAN.md](../TEST_PLAN.md). Playwright gegen Produktion ist **nicht** fest eingeplant; bei Bedarf `npm run test:e2e` lokal/CI ergänzen.  
- Playwright ersetzt **keine** pro-Stack-Zertifizierung in dieser Matrix.
