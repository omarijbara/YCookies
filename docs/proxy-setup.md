# Proxy Mode Setup Guide

YCookies Proxy Mode is the recommended method for delivering 100% compliant website experiences. By sending your traffic through the YCookies Edge Proxy, invasive scripts are blocked before they are even sent to the browser, significantly reducing page size and guaranteeing zero unauthorized cookies.

## Prerequisites
- A configured Domain in the YCookies Admin Panel.
- Access to your actual host server (e.g., Apache, NGINX) or your DNS provider (e.g., Cloudflare, Route53, GoDaddy).
- A valid SSL certificate managed by either YCookies or your host.

## How it works
1. **Visitor** requests `https://www.example.com`.
2. **DNS** routes `www.example.com` to the YCookies Proxy server IP.
3. The **Proxy** fetches the raw HTML page from your true hosting server (the origin server).
4. The **Proxy** removes recognized scripts/embeds from the page based on the visitor's cookie consent, preventing them from running.
5. The **Proxy** injects the YCookies consent banner placeholder and returns the sanitized HTML to the browser.
6. Only when the user grants consent does the banner re-insert the actual scripts.

## Step 1: DNS Setup

You need to change your domain's A or CNAME record to point at the YCookies Edge Proxy rather than your web host directly.

1. Navigate to your Dashboard -> Domains -> View your domain.
2. Look for the **Proxy Configuration** section.
3. Note down the **Proxy IP** or **Proxy CNAME** listed there.
4. Log into your domain registrar (e.g. GoDaddy) or DNS manager (e.g. Cloudflare).
5. Edit the specific record for your subdomain (e.g., `@` for root or `www`).
6. Update it to point to the address provided by YCookies.

*Note: If using Cloudflare, we recommend keeping the Cloudflare proxy toggle to "DNS Only" initially for testing.*

## Step 2: Establish the Origin URL

To ensure the proxy knows where to fetch your real content from, add the correct **Origin URL**.

1. In the YCookies panel, enter your actual web hosting IP or internal domain acting as the *origin*.
2. For example, if your origin is an internal load balancer, use `https://10.0.0.51:80`.
3. Save the Proxy settings. 

> [!CAUTION] 
> Do not set the Origin URL to the proxy domain itself, as it creates an endless loop!

## Step 3: SSL Verification

The Proxy needs SSL capability to proxy secure HTTPS content. YCookies automatically provides Let's Encrypt certificates to proxied domains.
Make sure that your domain passes the DNS verification status on the YCookies admin panel before enforcing HTTPS rewriting.

## Step 4: Verification

Check if YCookies is properly receiving requests:
1. Clear your browser cache.
2. Navigate to your domain `https://www.example.com`.
3. Open Developer Tools (F12) and inspect the `Network` tab.
4. View the initial Document response headers. Look for `X-YCookies-Cache` or the custom injection script nonce.
5. If the Cookie Banner auto-loads and correctly blocks external widgets like YouTube or Google Analytics, proxy interception is a success!

## Troubleshooting

- **502 Bad Gateway**: This happens when the Origin server URL is down or refusing connections from YCookies IPs. Whitelist YCookies IPs on your security backend.
- **Constant Redirect Loops**: This can happen if the origin server redirects HTTP -> HTTPS but your DNS edge uses Flexible SSL. Check Cloudflare strictly sets "Full" or "Full (Strict)" SSL.
