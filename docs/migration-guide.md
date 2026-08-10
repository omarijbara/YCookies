# Migration Guide: Moving to YCookies from Competing CMPs

Switching to YCookies from legacy platforms like Cookiebot, CookieYes, or OneTrust enables you to use our Proxy mode for true zero-js blocking, improving performance and accuracy. This guide walks you through migrating safely.

## Phase 1: Preparation (Audit & Copy)

Before turning off your current CMP, you need to replicate its logic in YCookies.

1. **Audit your current services.**
   - Log into your legacy CMP.
   - List all scripts, cookies, and tags currently categorized.
2. **Replicate in YCookies.**
   - In your YCookies Admin Panel, go to the **Services** section for your Domain.
   - Install matching packages from the **Package Library** (Google Analytics, Meta Pixel, etc.).
   - If any specialized custom scripts exist, create **Custom Services** for them within the matching categories (Analytics, Marketing, Necessary).

## Phase 2: Installing YCookies

Depending on your site constraints, you can choose Proxy Integration or JS snippet integration.

*If using JS Snippet Mode:*
- Add the YCookies initialization snippet to the very top of your `<head>` tag. It **must** remain higher than the old CMP snippet during the transition.

*If using Proxy Mode:*
- Configure your origin URL and update your DNS records to route traffic through the YCookies proxy. 
- You can leave your old CMP script inside the HTML code for now; the Proxy won't break it.

## Phase 3: The Swap (Rolling out YCookies)

When YCookies is fully configured:

1. **Remove Old Tags**: Delete the `<script>` tag referencing Cookiebot (e.g., `uc.js`) or CookieYes from your `<head>`.
2. **Clear Caches**: Purge Cloudflare, Fastly, or your origin caching layer.
3. **Verify**: Visit your website in an incognito window. The YCookies consent banner should appear. Test granting partial consent and verify the correct scripts load.

## Important: Consent Re-collection
Because YCookies stores consent in a new decentralized cookie model (`yc_consent`), returning visitors will be prompted to re-consent. This is legally required when changing CMP architectures to preserve the chain of consent integrity.
