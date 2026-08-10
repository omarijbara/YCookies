# Google Consent Mode v2 & IAB TCF v2.3 Integration Guide

## Google Consent Mode v2

YCookies integrates fully with Google Consent Mode v2, ensuring that Google tags (Analytics, Ads) adjust their behavior based on the user's consent status.

### 1. Enabling Consent Mode
- Go to your Domain settings -> **Consent Frameworks**.
- Check the box for **Enable Google Consent Mode v2**.

### 2. How it works
YCookies will automatically inject the `gtag('consent', 'default', ...)` initialization script into the `<head>` of your website via the Proxy or local integration snippet.
- Before consent: `ad_storage`, `ad_user_data`, `ad_personalization`, and `analytics_storage` are set to `denied`.
- After consent: Allowed categories are instantly updated to `granted`.

### 3. Advanced Configuration
You can pass custom region-specific defaults. In your Domain settings, specify regions (e.g., `EU`, `US-CA`) to have different default states before the user interacts with the banner.

---

## IAB TCF v2.3

The IAB Transparency and Consent Framework (TCF) standardizes how consent is communicated to the ad-tech ecosystem.

### 1. Activation
- In Domain settings -> **Consent Frameworks**, select **Enable IAB TCF v2.3**.

### 2. Vendor Declaration
- You must declare the IAB vendors you work with. YCookies synchronizes the global vendor list automatically.
- Assign your active services to the corresponding IAB TCF vendors within the **Services** tab.

### 3. Exposing the API
YCookies will automatically expose the `__tcfapi` window object on your site. Ad networks operating on your website will query this API to receive the encoded "TC String".

### 4. Backend sync
After consent changes, the widget may POST the TC string to your YCookies API (e.g. `POST /api/tcf/record` with `site_id` and payload). Ensure the domain’s `site_id` matches the script tag and that CORS/rate limits allow your traffic pattern.

### 5. Verification checklist
- In the browser console, confirm `typeof window.__tcfapi === 'function'` after the banner script loads.
- Use DevTools **Network** to confirm `gtag('consent', 'default'|'update', …)` runs when expected (Consent Mode).
- On sites with a **strict CSP**, follow [Installation → CSP note](getting-started.md) and [GTM integration](gtm-integration.md); consider **Proxy mode** if inline script allowances are not possible.

---

## Related documentation

- [GTM integration](gtm-integration.md) — tags, dataLayer, common pitfalls  
- [Cookie bar & script library](cookiebar-and-library.md) — embed vs advanced bootstrapper  
- [Proxy setup](proxy-setup.md) — server-side injection and headers  
- Automated check: `tests/Feature/CspHeadersTest.php` asserts CSP on `/ycookies/preview` when `config('csp.enabled')` is true (`App\Csp\Policies\YCookiesPolicy`).
