# Google Tag Manager (GTM) Integration Guide

YCookies Consent Manager provides a bulletproof, out-of-the-box integration for Google Tag Manager (GTM) and Google Consent Mode v2. It uses an advanced dual-layer data logic to expose precisely mapped consent states.

## Overview

Whenever a user accepts, rejects, or manages their cookie preferences, YCookies fires a unified event to the `dataLayer` alongside the native `gtag('consent', 'update')` commands.

### The Power Payload

When a user clicks "Accept All," the following payload is instantly dispatched to your dataLayer:

```json
{
  "event": "ycookies_consent_update",
  "consent": {
    "ad_storage": "granted",
    "ad_user_data": "granted",
    "ad_personalization": "granted",
    "analytics_storage": "granted",
    "functionality_storage": "granted",
    "personalization_storage": "denied",
    "security_storage": "denied"
  },
  "ycookies": {
    "groups": {
      "essential": true,
      "marketing": true,
      "statistics": true
    }
  },
  "timestamp": 1773524673666
}
```

## How to Configure GTM

### 1. The Trigger

Create a new **Custom Event** trigger in GTM.

- **Trigger Type:** Custom Event
- **Event Name:** `ycookies_consent_update`
- **This trigger fires on:** All Custom Events

Use this trigger to fire your Tags (e.g., Meta Pixel, Google Analytics, LinkedIn Insight) instead of the generic "All Pages" or "Page View" triggers. This guarantees your tags only fire *after* consent is established on the page.

### 2. The Variables

YCookies conveniently exposes dual-mapped variables so you can read both the strict Google signals *and* the raw YCookies groups in GTM.

Create a **Data Layer Variable** for any Google Consent Mode v2 signal:

- **Variable Type:** Data Layer Variable
- **Data Layer Variable Name:** `consent.ad_storage`
- **Data Layer Variable Name:** `consent.analytics_storage`

Or, map directly to YCookies groups if you are firing non-Google tags:

- **Data Layer Variable Name:** `ycookies.groups.marketing`
- **Data Layer Variable Name:** `ycookies.groups.statistics`

### 3. Tested and Verified

Our robust system guarantees accurate mapping, tested rigorously down to the frame on domains like `ycookies.test`.

![Phase 2 Test Run - Verified manager.js Output](./assets/manager_gtm_test.webp)
