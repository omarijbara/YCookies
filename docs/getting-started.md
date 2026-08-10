# Getting Started with YCookies

Welcome to YCookies! This guide will walk you through setting up your first domain, running your first scan, and publishing the proxy configuration.

## 1. Installation & Login

If you haven't already, install YCookies on your server or use a hosted instance.
Once installed, log into your admin dashboard. 
Upon logging in, you'll see your main workspace dashboard where you can manage your domains and view analytics.

## 2. Setting Up Your First Domain

To start managing cookies for a website:

1. Click on **Domains** in the sidebar navigation.
2. Click **Create Domain**.
3. Fill in the details:
   - **Name**: A descriptive name (e.g., *My Main Website*).
   - **Host (FQDN)**: The full domain name including the subdomain (e.g., `www.example.com`).
   - **Theme & Positioning**: Choose a position for the consent banner on your site.
4. Click **Create**.

Your domain is now registered in the YCookies platform.

## 3. Running Your First Scan

YCookies features an automated scanning engine that crawls your website to find external scripts and cookies.

1. Navigate to the **Scans** tab inside your new Domain.
2. Click **Run Scan Now**. (Depending on your site size, this might take a few minutes).
3. The background worker will simulate a visitor, logging network requests and cookies set.
4. Once completed, review the generated **Services**. YCookies will attempt to auto-categorize known cookies and scripts base on our library templates.

## 4. Configuring the Cookie Banner

To ensure compliance using the data just gathered:

1. Review the generated **Services** and their respective **Cookie Groups** (Essential, Marketing, Analytics).
2. Tweak texts, themes, and branding colors via the **CookieBars** section.
3. Once satisfied, click **Publish Revision**. This pushes the configuration to the Edge Proxy node!

## 5. Integrating with Your Site

You can integrate YCookies in two ways:
- **Proxy Mode (Recommended)**: Serve your website traffic through the YCookies Edge Proxy to automatically block undetected scripts before they even reach the browser window. See the [Proxy Mode Setup Guide](proxy-setup.md).
- **Static JavaScript Snippet (Fallback)**: If you cannot modify DNS, include the `<script>` tag shown on the domain's dashboard right after `<head>`. 

Congratulations! Your site now features a fully compliant, self-updating Cookie Consent banner.
