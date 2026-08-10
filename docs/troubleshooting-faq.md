# YCookies Troubleshooting FAQ

## Proxy Mode Issues

**Q: My website is showing a 502 Bad Gateway error.**
- Ensure your DNS A/CNAME records correctly point to the YCookies proxy node.
- Verify that your "Origin URL" is correctly configured in the YCookies Admin Panel and is reachable from our proxy servers.

**Q: Scripts are not being blocked.**
- Check if your Proxy mode is enabled. If it is in "Report Only" mode, it will just monitor scripts without blocking them.
- Ensure the specific service you want to block is active and assigned to the correct category.
- Some scripts might be injected dynamically via external JS. Ensure the parent domain is fully covered.

**Q: The Cookie Banner is not appearing.**
- Verify that the Domain setup is complete and the script injector is activated.
- If using Proxy Mode, ensure your site's HTML is being properly proxied (check the TTFB benchmark).

## Usage & Limits

**Q: Why am I blocked from adding a new domain?**
- You have reached the domain quota for your subscription plan. Upgrade your plan or delete unused domains.

**Q: What happens if I go over my monthly Scan limit?**
- Automatic scans will be paused until the next billing cycle. Manual scans will raise a limit error in the UI.

## Integrations

**Q: Google Analytics is reporting fewer sessions.**
- This is expected when operating under strict GDPR compliance. Users who reject "Analytics" cookies will not trigger the Google Analytics tracking scripts. Use Google Consent Mode v2 for advanced cookieless pings.

**Q: Styles are missing after enabling YCookies Proxy.**
- Sometimes aggressive CSP headers or caching layers can drop CSS. Check your browser's Developer Tools network console. Ensure `strict-dynamic` settings don't conflict with your CSS loading mechanism.

---

## Admin / Filament

**Q: 419 Page Expired after login or when saving a form.**  
- Usually session/cookie issues: ensure `APP_URL` matches the URL you use, `SESSION_DOMAIN` is correct (often `null` for single host), and HTTPS terminates so `SESSION_SECURE_COOKIE` matches your setup. Clear cookies and retry.

**Q: Pulse or Telescope returns 403.**  
- Pulse is restricted to the `super_admin` role by default (`AppServiceProvider`).

**Q: “Purge by retention policy” on Consent Logs does nothing visible.**  
- Only **super_admin** sees the action. Purge deletes rows **older than** the group’s `consent_retention_days` for **that tenant’s domains** only. The scheduled job still processes **all** groups nightly.

---

## Consent API / widget

**Q: `POST /api/log-consent` returns 422.**  
- Validation expects `consent.type` and a `consent` object; group values must be **booleans**, not strings. See [API reference](api-reference.md).

**Q: Consent not stored / empty stats.**  
- Confirm `site_id` matches an **active** domain. Check rate limits (429) and that requests are not blocked by ad blockers when testing from the browser.

---

## CI / GitHub Actions

**Q: Deploy Gate fails on “Production HTTP smoke”.**  
- The workflow calls your real `/up` and proxy `/health` URLs. Verify repository **Variables** `PROD_LARAVEL_UP_URL` / `PROD_PROXY_HEALTH_URL` if defaults are wrong.

**Q: Coolify step warns “COOLIFY_API_TOKEN not set”.**  
- Add the secret under **Settings → Secrets** in GitHub; the job still passes but skips API checks.

---

## Error tracking (GlitchTip / Sentry)

**Q: Errors not appearing in GlitchTip.**  
- Check `SENTRY_LARAVEL_DSN` / `SENTRY_NODE_DSN` and that the GlitchTip project accepts events from your deployment URL. Proxy and PHP use separate DSNs.
