/**
 * YCookies Consent Manager - Core Engine
 * Hand-coded completely in vanilla JS to prevent framework clashes on client websites.
 */
import { CmpApi } from '@iabtechlabtcf/cmpapi';
import { TCModel, TCString, GVL } from '@iabtechlabtcf/core';

class YCookiesManager {
    constructor() {
        // Read the configuration ID injected by the script tag
        // e.g., <script src="..." data-ycookies-id="UUID"></script>
        this.scriptTag = document.currentScript || document.querySelector('script[data-ycookies-id]');
        this.siteId = (window.YCookies && window.YCookies.config && window.YCookies.config.site_id) 
            ? window.YCookies.config.site_id 
            : (this.scriptTag ? this.scriptTag.getAttribute('data-ycookies-id') : null);

        this.config = null;
        this.uiContainer = null;
        this.consentState = {
            essential: true, // Always true
        };
        this.regionCode = 'EU'; // Default to EU for safety

        // Detect page language for translation resolution
        this.pageLocale = (document.documentElement.lang || 'en').split('-')[0].toLowerCase();
        this.ipapiData = null;

        // The default gtag initialization is deferred until initGoogleConsentMode() is called
        // so we can use the backend configuration if server-injected, or initialize it 
        // immediately as denied if not.
        window.dataLayer = window.dataLayer || [];
        if (!window.gtag) {
            window.gtag = function() { window.dataLayer.push(arguments); };
        }
        
        // Push a very early default just in case, will be overwritten by initGoogleConsentMode
        window.gtag('consent', 'default', {
            'ad_storage': 'denied',
            'ad_user_data': 'denied',
            'ad_personalization': 'denied',
            'analytics_storage': 'denied',
            'personalization_storage': 'denied',
            'functionality_storage': 'denied',
            'security_storage': 'denied',
            'wait_for_update': 500
        });

        // Initialize IAB TCF 2.2 Stub synchronously
        if (typeof window.__tcfapi === 'undefined') {
            window.__tcfapi = function (command, version, callback, parameter) {
                if (command === 'ping') {
                    callback({ gdprApplies: true, cmpLoaded: true, cmpStatus: 'stub' }, true);
                }
            };
        }

        // Expose init immediately but don't auto-call if we are in preview mode
        if (window.YCookiesPreviewMode) {
            return;
        }

        this.errorCount = 0;
        this._discoveryBuffer = [];
        this._discoveryFlushTimer = null;
        this.setupErrorTracking();
        this.init();
    }

    setupErrorTracking() {
        const report = (err, type = 'error') => {
            if (this.errorCount >= 3 || !this.siteId) return;
            this.errorCount++;

            const payload = JSON.stringify({
                site_id: this.siteId,
                type: 'rum_error',
                level: type === 'error' ? 'error' : 'warning',
                message: err.message || String(err),
                stack: err.stack || '',
                url: window.location.href,
                user_agent: navigator.userAgent,
                timestamp: new Date().toISOString()
            });
            // Fetch uses absolute API base to prevent hitting the proxy
            let rumApiBase = this.scriptTag ? this.scriptTag.getAttribute('data-ycookies-api') : null;
            if (!rumApiBase) rumApiBase = this.config?._api_base || '';
            if (rumApiBase && rumApiBase.endsWith('/')) rumApiBase = rumApiBase.slice(0, -1);

            if (window.fetch) {
                fetch(`${rumApiBase}/api/rum/beacon`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'text/plain', 'Accept': 'application/json' },
                    body: payload,
                    keepalive: true,
                    credentials: 'omit'
                });
            } else if (navigator.sendBeacon) {
                navigator.sendBeacon(`${rumApiBase}/api/rum/beacon`, payload);
            }
        };

        window.addEventListener('error', (event) => report(event.error || event.message));
        window.addEventListener('unhandledrejection', (event) => report(event.reason, 'promise'));
    }

    async init() {
        if (!this.siteId) {
            console.error('[YCookies] No data-ycookies-id found on script tag. Aborting initialization.');
            return;
        }

        // 1. Fetch Geolocation (non-blocking for UI, but blocking for default signals)
        await this.fetchGeolocation();

        // 2. Fetch Tenant Configuration
        await this.loadConfig();

        if (!this.config) return;

        // Expose locale globally for external scripts to know actual resolved language
        window.YCookies = window.YCookies || {};
        window.YCookies.locale = this.config.localization?.locale || 'en';

        // 3a. Stub out the IAB TCF Framework early to catch vendor calls
        this.initTCF();

        // 3b. Set Google Consent Mode default state IMMEDIATELY based on new data
        this.initGoogleConsentMode();

        // 4. Load Existing Consent from First-Party Cookie OR Hub
        const hubLoaded = await this.loadHubConsent();
        if (!hubLoaded) {
            this.loadLocalConsent();
        }

        // NEW: Consent Versioning Check
        const savedVersion = localStorage.getItem('ycookies_consent_version');
        const currentVersion = this.config.consent_version || 1;
        
        if (this.hasConsented() && savedVersion && parseInt(savedVersion, 10) !== parseInt(currentVersion, 10)) {
            console.warn(`[YCookies] Consent version mismatch (${savedVersion} vs ${currentVersion}). Forcing re-consent.`);
            this.clearConsent();
        }

        // 5. Initialize Interceptors (Monkey Patching & MutationObserver)
        this.applyInterceptors();

        // 5b. Flush discovery buffer on page unload
        window.addEventListener('pagehide', () => this.flushDiscoveryBuffer());

        // NEW: Consent Precedence Layer 1 - URL Params Override
        this.applyUrlConsentOverrides();

        // Check Global Privacy Control (GPC)
        this.gpcActive = false;
        if (typeof navigator !== 'undefined' && navigator.globalPrivacyControl === true) {
            console.log('[YCookies] Global Privacy Control (GPC) browser signal detected. Auto-rejecting non-essential cookies.');
            this.gpcActive = true;
        } else if (this.scriptTag && this.scriptTag.getAttribute('data-ycookies-gpc') === '1') {
            console.log('[YCookies] Global Privacy Control (Sec-GPC) network signal detected. Auto-rejecting non-essential cookies.');
            this.gpcActive = true;
        }

        if (this.gpcActive) {
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({ event: 'ycookies_gpc_detected', ycookies_gpc_source: (navigator.globalPrivacyControl ? 'browser' : 'network') });
        }

        // NEW: Check Enterprise Geo-Restriction (Strict EU Only)
        // This overrides all other geo_rules if the tenant enabled it.
        const visitorCountry = this.config.visitor_country || this.regionCode; 
        const euCountries = ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE'];
        
        let bypassBannerDueToGeo = false;
        if (this.config.geo_restriction_eu && !euCountries.includes(visitorCountry)) {
             console.log('[YCookies] Visitor outside EU. Auto-consenting due to Enterprise Geo-Restriction.');
             bypassBannerDueToGeo = true;
        }

        // 6. Check Geo Rules & Render UI
        const geoRules = this.config.geo_rules || {};
        const regionalRule = geoRules[visitorCountry] || geoRules['EU'] || { mode: 'optin' };

        if (!this.hasConsented()) {
            if (this.gpcActive) {
                // Strict reject due to GPC
                console.log('[YCookies] Applying Precedence 3: GPC');
                this.saveConsent(this.generateEssentialOnlyConsent());
            } else if (bypassBannerDueToGeo || regionalRule.mode === 'auto') {
                // Auto-consent for completely relaxed regions OR outside EU (when restriction active)
                console.log('[YCookies] Applying Precedence 4/5: Regional/Global Auto Consent');
                const fullConsent = this.generateFullConsentObject();
                this.saveConsent(fullConsent);
            } else if (regionalRule.mode === 'optout' && regionalRule.banner === false) {
                // Implicit opt-in, no banner shown unless revoked
                console.log('[YCookies] Applying Precedence 4: Regional Opt-Out');
                const fullConsent = this.generateFullConsentObject();
                this.saveConsent(fullConsent);
                // We don't render the banner, they are explicitly opted in
            } else {
                // Strict optin or optout WITH banner
                this.handleBannerTrigger();
            }
        } else {
            this.injectConsentedServices();
            this.updateGoogleConsentMode();
            this.pushDataLayerEvents(this.consentState);
            this.injectReopenWidget();
            this.renderPrivacyPolicyTable();
            
            // Re-save version just in case they arrived with cookie but no localStorage version
            if (!savedVersion && this.config.consent_version) {
                localStorage.setItem('ycookies_consent_version', this.config.consent_version);
            }
        }

        // 7. Fire Developer Callback
        this.dispatchHook('ready', { config: this.config, state: this.consentState, region: visitorCountry });

        // 8. Initialize Embed Placeholder Runtime (v2 content blocker UX)
        this.initEmbedPlaceholders();

        // 9. Initialize Floating Content Blocker widgets (e.g. chat icons for blocked scripts)
        this.initFloatingBlockers();

        // 10. Discovery: report ALL proxy-blocked resources (runs regardless of consent state)
        this._reportProxyBlockedScriptsAndStyles();
    }

    /**
     * Handles displaying the consent banner based on the configured trigger mode
     */
    handleBannerTrigger() {
        // If config says load immediately, or if we are in iframe preview
        const triggerMode = this.config.ui_config?.trigger_mode || 'interaction';
        
        if (triggerMode === 'load' || window.location.href.includes('/ycookies-preview')) {
            this.renderConsentBanner();
            return;
        }

        let isTriggered = false;

        const showBanner = () => {
            if (isTriggered) return;
            isTriggered = true;
            
            this.renderConsentBanner();
            
            // Cleanup listeners
            window.removeEventListener('scroll', showBanner);
            document.removeEventListener('click', showBanner);
            document.removeEventListener('keydown', showBanner);
            document.removeEventListener('mousemove', showBanner);
            document.removeEventListener('touchstart', showBanner);
        };

        if (triggerMode === 'scroll') {
            window.addEventListener('scroll', showBanner, { passive: true, once: true });
        } else if (triggerMode === 'interaction') {
            window.addEventListener('scroll', showBanner, { passive: true, once: true });
            document.addEventListener('click', showBanner, { passive: true, once: true });
            document.addEventListener('keydown', showBanner, { passive: true, once: true });
            document.addEventListener('mousemove', showBanner, { passive: true, once: true });
            document.addEventListener('touchstart', showBanner, { passive: true, once: true });
        }
    }

    /**
     * Specialized bootloader for the Filament Admin Iframe.
     * Bypasses the network API payload fetch completely (since config is injected),
     * disables domain local storage, and forcefully renders the banner.
     */
    initPreviewMode() {
        console.log('[YCookies] Booting in LIVE PREVIEW mode...');

        // Detect if we are inside the Consent Debugger (not the admin panel preview)
        let isDebuggerContext = false;
        try {
            isDebuggerContext = window.parent !== window &&
                window.parent.location.href.includes('/ycookies/debugger');
        } catch (e) {
            // Cross-origin — check referrer as fallback
            isDebuggerContext = document.referrer.includes('/ycookies/debugger');
        }
        this._isDebuggerMode = isDebuggerContext;

        if (isDebuggerContext) {
            console.log('[YCookies] Debugger mode detected — real handlers active.');
        }

        // Force state unconsented to show the UI
        this.consentState = { essential: true };

        // Listen for Livewire Filament postMessage updates
        window.addEventListener('message', (event) => {
            let data = event.data;
            if (typeof data === 'string') {
                try { data = JSON.parse(data); } catch (e) { return; }
            }

            if (data && data.type === 'ycookies_live_preview') {
                console.log('[YCookies] Received live config update:', data.ui_config, data.translations);
                this.config.ui_config = data.ui_config;
                if (data.translations) {
                    this.config.translations = data.translations;
                }
                // Re-render
                if (this.uiContainer && this.uiContainer.parentNode) {
                    this.uiContainer.parentNode.removeChild(this.uiContainer);
                }
                this.renderConsentBanner();
                if (!this._isDebuggerMode) {
                    this.stubPreviewButtons();
                }
            }
        });

        // Render purely based on the injected window.YCookies.config
        this.renderConsentBanner();
        // Only stub buttons in admin panel preview, not in the debugger
        if (!this._isDebuggerMode) {
            this.stubPreviewButtons();
        }
    }

    stubPreviewButtons() {
        if (this.uiContainer && this._shadow) {
            const shadow = this._shadow;
            ['yc-btn-accept', 'yc-btn-essential', 'yc-btn-save', 'yc-btn-save-consent', 'yc-btn-essential-only'].forEach(id => {
                const btn = shadow.getElementById(id);
                if (btn) {
                    btn.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        console.log(`[Preview Simulation] ${id} clicked. Refreshing demo state.`);
                        // Hide it gracefully
                        const overlay = shadow.getElementById('yc-overlay');
                        if (overlay) overlay.classList.remove('yc-visible');
                        
                        setTimeout(() => {
                            // Re-render
                            if (this.uiContainer && this.uiContainer.parentNode) {
                                this.uiContainer.parentNode.removeChild(this.uiContainer);
                            }
                            this.renderConsentBanner();
                            this.stubPreviewButtons();
                        }, 600);
                    }, true); // Use capture phase to intercept
                }
            });
        }
    }

    /**
     * Reaches out to the Laravel API endpoint created in Phase 1
     */
    async loadConfig() {
        // First, check if config was already injected by the server (ScriptDeliveryController)
        if (window.YCookies && window.YCookies.config && window.YCookies.config.site_id) {
            this.config = window.YCookies.config;
            console.log('[YCookies] Using server-injected configuration.');
            return;
        }

        // Fallback: fetch from API if not injected (static loader mode)
        try {
            // Resolve the API base URL. Priority:
            // 1. data-ycookies-api attribute (set by proxy injector for cross-origin)
            // 2. Derive from script src (same-origin Vite build)
            // 3. Fall back to script origin
            let apiBase = '';
            if (this.scriptTag) {
                apiBase = this.scriptTag.getAttribute('data-ycookies-api')
                    || this.scriptTag.getAttribute('data-ycookies-base')
                    || '';
            }
            if (!apiBase && this.scriptTag) {
                const src = this.scriptTag.getAttribute('src') || '';
                if (src.includes('/build/')) {
                    apiBase = src.split('/build/')[0];
                } else {
                    // Extract origin from absolute URL
                    try {
                        const url = new URL(src, window.location.href);
                        apiBase = url.origin;
                    } catch { apiBase = ''; }
                }
            }
            
            // 1. Check URL param `?lang=` first (for explicit overrides)
            const urlParams = new URLSearchParams(window.location.search);
            let lang = urlParams.get('lang') || urlParams.get('locale') || urlParams.get('language');
            
            // 2. Check saved cookie
            if (!lang) {
                const langMatch = document.cookie.match(/(^| )ycookies_lang=([^;]+)/);
                if (langMatch) lang = langMatch[2];
            }
            
            // 3. Auto-detect from HTML tag or Browser (Backend still enforces auto_detect rules)
            if (!lang) {
                lang = document.documentElement.lang || navigator.language.split('-')[0] || 'en';
            }
            
            const response = await fetch(`${apiBase}/api/config/${this.siteId}?lang=${lang}`);

            if (!response.ok) throw new Error('Network response was not ok');

            this.config = await response.json();
        } catch (error) {
            console.error('[YCookies] Failed to load configuration:', error);
        }
    }

    /**
     * Determines regional strictness via ipapi.co.
     */
    /**
     * Parse and apply URL parameters to override consent
     * Used exclusively for YCookies precedence #1 (override over local state).
     */
    applyUrlConsentOverrides() {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('ycookies_consent')) {
            const consentOverride = urlParams.get('ycookies_consent');
            if (consentOverride === 'accept_all') {
                console.log('[YCookies] Applying URL override (Precedence 1): Accept All');
                this.consentState = this.generateFullConsentObject();
                this.saveLocalCookie(this.consentState);
                if (this.config && this.config.consent_version) {
                    localStorage.setItem('ycookies_consent_version', this.config.consent_version);
                }
            } else if (consentOverride === 'essential_only') {
                console.log('[YCookies] Applying URL override (Precedence 1): Essential Only');
                this.consentState = this.generateEssentialOnlyConsent();
                this.saveLocalCookie(this.consentState);
                if (this.config && this.config.consent_version) {
                    localStorage.setItem('ycookies_consent_version', this.config.consent_version);
                }
            } else if (consentOverride === 'reset') {
                 console.log('[YCookies] Applying URL override (Precedence 1): Reset');
                 this.clearConsent();
            }
        }
    }

    async fetchGeolocation() {
        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 1500); // Fast timeout

            // Note: Since this executes on the client's browser, the IP checked is the visitor's
            const response = await fetch('https://ipapi.co/json/', { signal: controller.signal });
            clearTimeout(timeoutId);

            if (response.ok) {
                this.ipapiData = await response.json();
                if (this.ipapiData && this.ipapiData.country_code) {
                    this.regionCode = this.ipapiData.country_code;
                }
            }
        } catch (error) {
            console.warn('[YCookies] Geolocation failed, defaulting to EU strict mode.', error);
        }
    }

    /**
     * Helper to generate a full consent object based on configuration 
     */
    generateFullConsentObject() {
        const consent = {};
        if (this.config && this.config.cookie_groups) {
            this.config.cookie_groups.forEach(group => {
                consent[group.key] = true;
                if (group.services) {
                    group.services.forEach(svc => consent[svc.key] = true);
                }
                if (group.virtual_services) {
                    group.virtual_services.forEach(vs => consent[vs.key] = true);
                }
            });
        }
        return consent;
    }

    /**
     * Reads the 'ycookies_consent' first-party cookie to check past choices
     */
    loadLocalConsent() {
        const match = document.cookie.match(new RegExp('(^| )ycookies_consent=([^;]+)'));
        if (match) {
            try {
                this.consentState = JSON.parse(decodeURIComponent(match[2]));
            } catch (e) {
                console.warn('[YCookies] Malloc consent cookie format.');
            }
        }
    }

    /**
     * Writes choices back to local storage and dispatches update hooks
     */
    saveConsent(newConsent) {
        this.consentState = { ...this.consentState, ...newConsent };

        this.saveLocalCookie(this.consentState);
        
        // Save version to enforce future consent rescans if the admin updates their policy
        if (this.config && this.config.consent_version) {
            localStorage.setItem('ycookies_consent_version', this.config.consent_version);
        }
        
        this.syncToHub();

        this.injectConsentedServices();
        this.updateGoogleConsentMode();
        this.updateTCF(this.consentState);
        this.pushDataLayerEvents(this.consentState);
        this.injectReopenWidget();
        this.renderPrivacyPolicyTable();
        this.sendConsentBeacon();

        // v2: Auto-restore embeds when category consent changes (after embed runtime init)
        if (this._embedPlaceholdersReady) {
            this.autoRestoreEmbeds();
        }

        this.dispatchHook('update', { state: this.consentState });
    }

    /**
     * Helper to write the cookie to the local domain
     */
    saveLocalCookie(state) {
        const cookieStr = encodeURIComponent(JSON.stringify(state));
        const expires = new Date();
        expires.setTime(expires.getTime() + (365 * 24 * 60 * 60 * 1000));
        document.cookie = `ycookies_consent=${cookieStr};expires=${expires.toUTCString()};path=/;SameSite=Lax`;
    }
    
    /**
     * Helper to clear consent completely and force a re-render
     */
    clearConsent() {
        // Reset state
        this.consentState = { essential: true };
        
        // Remove cookie
        document.cookie = `ycookies_consent=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/; SameSite=Lax`;
        
        // Clear localStorage version
        localStorage.removeItem('ycookies_consent_version');
        
        console.log('[YCookies] Consent cleared.');
    }

    /**
     * Initializes IAB TCF 2.3 Command Router using @iabtechlabtcf/cmpapi
     */
    initTCF() {
        if (!this.config?.tcf_enabled) {
            // Even if disabled, we should provide a stub so vendors don't crash
            if (typeof window.__tcfapi === 'undefined') {
                window.__tcfapi = function (command, version, callback) {
                    if (command === 'ping') {
                        callback({ gdprApplies: true, cmpLoaded: true, cmpStatus: 'stub' }, true);
                    } else if (callback) {
                        callback(null, false);
                    }
                };
            }
            return;
        }

        const cmpId = this.config.tcf_cmp_id || 999;
        const cmpVersion = 1;
        const isServiceSpecific = true; // YCookies is specific per domain by default

        // Initialize the CmpApi which takes over window.__tcfapi
        this.cmpApi = new CmpApi(cmpId, cmpVersion, isServiceSpecific, {
            // Optional custom commands map
        });

        // Set initial state. If we already have consent locally, we will update it later 
        // in loadLocalConsent / loadHubConsent. For now, we indicate UI is needed if no consent exists.
        if (!this.hasConsented()) {
            this.cmpApi.update('', true);
        }
    }

    /**
     * Updates the TC String via @iabtechlabtcf/core and notifies vendors
     */
    async updateTCF(state) {
        if (!this.cmpApi || !this.config?.tcf_enabled) return;

        try {
            GVL.baseUrl = 'https://vendor-list.consensu.org/v3/';
            const gvl = new GVL('vendor-list.json');
            await gvl.readyPromise;

            const tcModel = new TCModel(gvl);
            tcModel.cmpId = this.config.tcf_cmp_id || 999;
            tcModel.cmpVersion = 1;
            tcModel.consentScreen = 1;
            tcModel.consentLanguage = (this.pageLocale || 'en').toUpperCase();
            tcModel.isServiceSpecific = true;
            
            // Map YCookies consent state to TCF Purposes
            let purposeConsents = new Set();
            let purposeLegitimateInterests = new Set();
            let specialFeatureOptins = new Set();

            if (this.config && this.config.cookie_groups) {
                // Dynamically resolve purposes from configured groups
                this.config.cookie_groups.forEach(group => {
                    const hasConsent = !!state[group.key] || !!group.is_required;
                    if (hasConsent && group.tcf_purposes) {
                        group.tcf_purposes.forEach(p => purposeConsents.add(parseInt(p, 10)));
                        group.tcf_purposes.forEach(p => purposeLegitimateInterests.add(parseInt(p, 10)));
                    }
                    if (hasConsent && group.tcf_special_features) {
                        group.tcf_special_features.forEach(f => specialFeatureOptins.add(parseInt(f, 10)));
                    }
                });
            } else if (this.config && this.config.tcf_mapping) {
                // Legacy / simplified global tcf_mapping fallback
                Object.entries(this.config.tcf_mapping).forEach(([groupKey, purposes]) => {
                    if (state[groupKey]) {
                        purposes.forEach(p => {
                            purposeConsents.add(parseInt(p, 10));
                            purposeLegitimateInterests.add(parseInt(p, 10));
                        });
                    }
                });
            } else {
                // MVP Fallback mapping
                if (state.analytics) {
                    [7, 8].forEach(p => purposeConsents.add(p));
                    [7, 8].forEach(p => purposeLegitimateInterests.add(p));
                    specialFeatureOptins.add(1);
                }
                if (state.marketing) {
                    [1, 2, 3, 4].forEach(p => purposeConsents.add(p));
                }
                if (state.personalization) {
                    [5, 6].forEach(p => purposeConsents.add(p));
                }
            }

            tcModel.purposeConsents.set(Array.from(purposeConsents));
            tcModel.purposeLegitimateInterests.set(Array.from(purposeLegitimateInterests));
            tcModel.specialFeatureOptins.set(Array.from(specialFeatureOptins));

            // Grant all vendors for the selected purposes (Simplified mapping for MVP)
            tcModel.vendorConsents.set(Object.keys(gvl.vendors).map(id => parseInt(id, 10)));
            tcModel.vendorLegitimateInterests.set(Object.keys(gvl.vendors).map(id => parseInt(id, 10)));

            const encodedString = TCString.encode(tcModel);
            
            // Send update to CMP API
            this.cmpApi.update(encodedString, false);
            console.log('[YCookies] TC String updated:', encodedString);

            // POST to backend sync
            this.syncTCFString(encodedString);

        } catch (e) {
            console.error('[YCookies] Failed to build TCString:', e);
            this.cmpApi.update(null); 
        }
    }

    /**
     * Send the TC String to the defined tracking endpoint
     */
    syncTCFString(tcString) {
        if (!this.siteId || window.YCookiesPreviewMode) return;
        
        try {
            const apiHost = this.scriptTag ? new URL(this.scriptTag.src).origin : '';
            const payload = JSON.stringify({
                site_id: this.siteId,
                tc_string: tcString,
                uuid: localStorage.getItem('ycookies_uuid'),
                url: window.location.href,
            });

            // Fire and forget beacon
            if (navigator.sendBeacon) {
                navigator.sendBeacon(`${apiHost}/api/tcf/record`, payload);
            } else {
                fetch(`${apiHost}/api/tcf/record`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: payload,
                    keepalive: true
                });
            }
        } catch (e) {
            console.warn('[YCookies] Failed to sync TC String', e);
        }
    }

    /**
     * Initializes Google Consent Mode v2 default state.
     * v2: Uses per-service consent_mode_mapping to build strict defaults.
     */
    initGoogleConsentMode() {
        if (!this.config?.tcm_config?.enabled) return;
        
        window.dataLayer = window.dataLayer || [];
        function gtag() { dataLayer.push(arguments); }
        window.gtag = window.gtag || gtag;

        if (!this.config.tcm_config.has_google_services) {
            console.log('[YCookies] Google Consent Mode skipped: No services with consent signals on this domain.');
            return;
        }

        const defaultConsent = this.calculateGoogleConsent();
        
        // Check if we have prior consent stored
        const hasStoredConsent = this.hasConsented();
        
        if (hasStoredConsent) {
            // If returning user, immediately apply their prior choices as 'default'
            // to avoid flashes of unconsented state for GA4
            window.gtag('consent', 'default', defaultConsent);
        } else {
            // New user, strictly deny everything mapped
            const strictDefaults = { ...defaultConsent };
            
            // v2: Collect all declared signals from per-service mappings
            let hasV2Signals = false;
            if (this.config?.cookie_groups) {
                for (const group of this.config.cookie_groups) {
                    for (const service of (group.services || [])) {
                        if (!service.consent_mode_mapping) continue;
                        const signals = service.consent_mode_mapping.consent_signals || [];
                        for (const signal of signals) {
                            hasV2Signals = true;
                            strictDefaults[signal] = 'denied';
                        }
                    }
                }
            }
            
            // Fallback: legacy tcm_config.mapping
            if (!hasV2Signals && this.config.tcm_config.mapping) {
                Object.values(this.config.tcm_config.mapping).forEach(signals => {
                     signals.forEach(signal => strictDefaults[signal] = 'denied');
                });
            }
            
            window.gtag('consent', 'default', strictDefaults);
        }
        console.log('[YCookies] GCM v2 default initialized:', hasStoredConsent ? defaultConsent : 'Strictly Denied');
    }

    /**
     * Calculates the Google Consent state using per-service consent_mode_mapping (v2)
     * with fallback to legacy tcm_config.mapping for backward compatibility.
     * 
     * v2 ConsentResolver: Each service declares its own consent signals via consent_mode_mapping.
     * If a service's parent group is consented, its signals flip to 'granted'.
     * This is data-driven — no hardcoded service names, no centralized mapping.
     */
    calculateGoogleConsent() {
        const consent = {
            'ad_storage': 'denied',
            'ad_user_data': 'denied',
            'ad_personalization': 'denied',
            'analytics_storage': 'denied',
            'personalization_storage': 'denied',
            'functionality_storage': 'denied',
            'security_storage': 'denied'
        };

        // v2: Per-service consent_mode_mapping projection
        let hasV2Mappings = false;
        if (this.config?.cookie_groups) {
            for (const group of this.config.cookie_groups) {
                const groupConsented = !!this.consentState[group.key];
                const services = group.services || [];
                
                for (const service of services) {
                    if (!service.consent_mode_mapping) continue;
                    hasV2Mappings = true;
                    
                    const signals = service.consent_mode_mapping.consent_signals || [];
                    if (signals.length === 0) continue;
                    
                    if (groupConsented) {
                        for (const signal of signals) {
                            if (consent.hasOwnProperty(signal)) {
                                consent[signal] = 'granted';
                            }
                        }
                    }
                }
            }
        }

        // Fallback: legacy tcm_config.mapping (group → [signals])
        if (!hasV2Mappings && this.config?.tcm_config?.mapping) {
            Object.entries(this.config.tcm_config.mapping).forEach(([group, signals]) => {
                if (this.consentState[group]) {
                    signals.forEach(signal => consent[signal] = 'granted');
                }
            });
        }

        if (this.config?.tcm_config?.advanced_consent_mode && consent['ad_storage'] === 'granted') {
            consent.region = ['DE'];
        }

        return consent;
    }

    /**
     * Update Google Consent Mode v2 based on current consent state.
     * Called by saveConsent() whenever the user saves their choices.
     */
    updateGoogleConsentMode() {
        if (!this.config?.tcm_config?.enabled) return;
        
        if (!this.config.tcm_config.has_google_services) {
             return;
        }

        const consent = this.calculateGoogleConsent();
        window.gtag('consent', 'update', consent);
        console.log('[YCookies] GCM v2 updated:', consent);
        
        // Push the unified dataLayer event
        this.pushYCookiesConsentUpdateEvent(consent);
    }
    
    /**
     * Pushes the primary integration event for GTM/Tealium
     */
    pushYCookiesConsentUpdateEvent(googleConsentPayload) {
        const eventData = {
            event: 'ycookies_consent_update',
            consent: googleConsentPayload,
            ycookies: { groups: this.consentState },
            timestamp: Date.now()
        };
        
        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push(eventData);
        window.dispatchEvent(new CustomEvent('ycookies_consent_update', { detail: eventData }));
    }

    /**
     * Push individual consent events to the dataLayer for each consented group/service.
     * This allows GTM triggers to fire based on specific consent categories.
     * Schema: ycookies-opt-in-{service_key}
     */
    pushDataLayerEvents(consentState) {
        if (!consentState) return;

        window.dataLayer = window.dataLayer || [];
        
        // Always push an initialization event for generic tracking
        window.dataLayer.push({ event: 'ycookies_initialized' });

        // Push an event for each group that is consented
        for (const [key, value] of Object.entries(consentState)) {
            if (value === true) {
                window.dataLayer.push({
                    event: `ycookies-opt-in-${key}`,
                    ycookies_group: key,
                    ycookies_granted: true
                });
            }
        }

        // Also push individual service-level events if available
        if (this.config && this.config.cookie_groups) {
            for (const group of this.config.cookie_groups) {
                if (!consentState[group.key]) continue;
                
                const services = group.services || [];
                for (const service of services) {
                    // Only push if the specific service is also granted
                    if (consentState[service.key] === true) {
                        window.dataLayer.push({
                            event: `ycookies-opt-in-${service.key}`,
                            ycookies_service: service.key,
                            ycookies_group: group.key,
                            ycookies_granted: true
                        });
                    }
                }
            }
        }

        console.log('[YCookies] DataLayer events pushed for consent state.');
    }

    /**
     * Helper to sync consent state to the cross-domain central hub iframe
     */
    syncToHub() {
        if (this.hubIframe && this.hubIframe.contentWindow) {
            this.hubIframe.contentWindow.postMessage({
                type: 'ycookies_sync',
                action: 'write',
                payload: this.consentState
            }, '*');
        }
    }

    /**
     * Asynchronously loads consent from the Central Hub via Cross-Origin Iframe
     */
    async loadHubConsent() {
        if (!this.config || !this.config.cross_domain_enabled) return false;
        
        return new Promise((resolve) => {
            // Resolve API base URL (same logic as loadConfig/sendConsentBeacon):
            let apiBase = '';
            if (this.scriptTag) {
                apiBase = this.scriptTag.getAttribute('data-ycookies-api')
                    || this.scriptTag.getAttribute('data-ycookies-base')
                    || '';
            }
            if (!apiBase && this.scriptTag) {
                const src = this.scriptTag.getAttribute('src') || '';
                if (src.includes('/build/')) {
                    apiBase = src.split('/build/')[0];
                } else if (src.includes('/api/')) {
                    apiBase = src.split('/api/')[0];
                } else {
                    try {
                        const u = new URL(src, window.location.href);
                        apiBase = u.origin;
                    } catch { apiBase = ''; }
                }
            }
            const iframe = document.createElement('iframe');
            iframe.style.display = 'none';
            iframe.src = `${apiBase}/api/hub/${this.siteId}`;
            
            // Timeout to fallback to local cookies if hub fails
            let timeout = setTimeout(() => {
                console.warn('[YCookies] Consent Hub timeout. Falling back to local cookies.');
                resolve(false);
            }, 3000);

            const listener = (event) => {
                if (event.source !== iframe.contentWindow) return;
                
                const data = event.data;
                if (!data) return;
                
                if (data.type === 'ycookies_hub_ready') {
                    // Hub is ready, request data
                    iframe.contentWindow.postMessage({ type: 'ycookies_sync', action: 'read' }, '*');
                } else if (data.type === 'ycookies_sync_response') {
                    clearTimeout(timeout);
                    window.removeEventListener('message', listener);
                    
                    if (data.payload) {
                        this.consentState = data.payload;
                        this.saveLocalCookie(this.consentState);
                        resolve(true);
                    } else {
                        resolve(false); // No data in hub, proceed to local cookie fallback
                    }
                }
            };
            
            window.addEventListener('message', listener);
            
            // Wait for body to be available to inject hidden iframe
            if (document.body) {
                document.body.appendChild(iframe);
            } else {
                window.addEventListener('DOMContentLoaded', () => document.body.appendChild(iframe));
            }
            this.hubIframe = iframe;
        });
    }

    /**
     * Sends the current consent state to the YCookies Analytics Backend
     */
    sendConsentBeacon() {
        if (!this.siteId || window.YCookiesPreviewMode) return;

        try {
            const apiBase = this.getCmpApiBase();
            const url = `${apiBase}/api/log-consent`;
            
            // Generate or reuse a consent UID for tracking consent changes
            if (!this.consentUid) {
                this.consentUid = localStorage.getItem('ycookies_consent_uid') || this.generateUID();
                localStorage.setItem('ycookies_consent_uid', this.consentUid);
            }

            const groups = {};
            const services = [];
            let consentType = 'custom';

            if (this.config && this.config.cookie_groups) {
                let allGranted = true;
                let onlyEssential = true;
                this.config.cookie_groups.forEach(group => {
                    const granted = !!this.consentState[group.key] || !!group.is_required;
                    groups[group.key] = granted;
                    if (!granted && !group.is_required) allGranted = false;
                    if (granted && !group.is_required) onlyEssential = false;
                    if (granted && group.services) {
                        group.services.forEach(svc => {
                            if (this.consentState[svc.key] !== false) services.push(svc.key);
                        });
                    }
                });
                if (allGranted) consentType = 'all';
                else if (onlyEssential) consentType = 'essential';
            }

            const payload = JSON.stringify({
                site_id: this.siteId,
                uid: this.consentUid,
                cookie_version: this.config ? (this.config.consent_version || 1) : 1,
                consent: { type: consentType, groups: groups, services: services },
                provider_overrides: this._providerOverrides ? Array.from(this._providerOverrides) : [],
                region: this.regionCode
            });

            // Use sendBeacon for fire-and-forget logging that works even during page unload
            if (navigator.sendBeacon) {
                // Determine raw beacon API or fetch fallback since sendBeacon only accepts certain content types easily
                // We'll use a Blob to set application/json
                const blob = new Blob([payload], { type: 'application/json' });
                navigator.sendBeacon(url, blob);
            } else {
                // Fallback for older browsers
                fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: payload,
                    keepalive: true
                }).catch(() => {});
            }
        } catch (e) {
            console.error('[YCookies] Failed to beacon consent log', e);
        }
    }

    /**
     * Generate a unique consent UID (32 hex chars)
     */
    generateUID() {
        const arr = new Uint8Array(16);
        crypto.getRandomValues(arr);
        return Array.from(arr, b => b.toString(16).padStart(2, '0')).join('');
    }

    hasConsented() {
        return document.cookie.includes('ycookies_consent=');
    }

    /**
     * Specifically checks if a particular service has been consented to.
     * Mimics BorlabsCookie.Consents.hasConsent to allow easy integration with GTM Variable Templates.
     */
    hasConsentedService(serviceKey) {
        if (!this.consentState) return false;
        return this.consentState[serviceKey] === true;
    }

    /**
     * Specifically checks if a particular consent group has been consented to.
     */
    hasConsentedGroup(groupKey) {
        if (!this.consentState) return false;
        return this.consentState[groupKey] === true;
    }

    /**
     * Mounts the Vanilla JS UI into a closed Shadow DOM to prevent CSS leaks
     * from the client's website (Tailwind, Bootstrap, etc.)
     */
    renderConsentBanner() {
        console.log('[YCookies] Rendering Consent Banner UI...');

        const mountTarget = this.getUiMountTarget();
        if (!mountTarget) return;

        this.uiContainer = document.createElement('div');
        this.uiContainer.id = 'ycookies-consent-wrapper';
        mountTarget.appendChild(this.uiContainer);

        const shadow = this.uiContainer.attachShadow({ mode: 'closed' });
        this._shadow = shadow; // Store ref for showDialog() since shadow is closed

        // 1. Generate Dynamic CSS from ui_config
        const cssVars = this.generateDynamicCss();

        // 2. Generate content templates based on the 4 layouts
        const { positionClass, bannerHtml } = this.generateBannerHtml();

        // 3. Assemble full HTML
        shadow.innerHTML = `
            <style>${cssVars}</style>
            <div class="yc-overlay yc-pos-${positionClass}" id="yc-overlay">
                ${bannerHtml}
            </div>
        `;

        const overlay = shadow.getElementById('yc-overlay');
        setTimeout(() => overlay.classList.add('yc-visible'), 50);

        // 5. Bind Accordion Toggles
        shadow.querySelectorAll('.toggle-desc').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const targetId = e.currentTarget.getAttribute('data-target');
                const item = shadow.getElementById(targetId);
                const isExpanded = item.classList.toggle('expanded');
                e.currentTarget.setAttribute('aria-expanded', isExpanded);
            });
        });

        // Accordion Checkbox Cascading (Two-way logic)
        shadow.querySelectorAll('.yc-group-chk').forEach(groupChk => {
            groupChk.addEventListener('change', (e) => {
                const groupKey = e.target.getAttribute('data-group');
                shadow.querySelectorAll(`.yc-svc-chk[data-group="${groupKey}"]`).forEach(svcChk => {
                    if (!svcChk.disabled) {
                        svcChk.checked = e.target.checked;
                    }
                });
            });
        });

        // Cascade UP: Uncheck or check parent group based on children
        shadow.querySelectorAll('.yc-svc-chk').forEach(svcChk => {
            svcChk.addEventListener('change', (e) => {
                const groupKey = e.target.getAttribute('data-group');
                const groupChk = shadow.querySelector(`.yc-group-chk[data-group="${groupKey}"]`);
                if (groupChk && !groupChk.disabled) {
                    const siblings = shadow.querySelectorAll(`.yc-svc-chk[data-group="${groupKey}"]`);
                    const allChecked = Array.from(siblings).every(chk => chk.checked);
                    const someChecked = Array.from(siblings).some(chk => chk.checked);
                    // If at least one is checked, consider the group "partially" or fully checked for UI precedence.
                    groupChk.checked = someChecked; 
                }
            });
        });

        // 6. Bind Button Click Handlers
        this.bindBannerButtons(shadow, overlay);
        
        // 7. Focus Trap
        this.trapFocus(shadow, overlay);
    }

    /**
     * Binds all consent button click handlers within the shadow DOM.
     */
    bindBannerButtons(shadow, overlay) {
        // Accept All — consent to everything
        const acceptBtn = shadow.getElementById('yc-btn-accept');
        if (acceptBtn) {
            acceptBtn.addEventListener('click', () => {
                const fullConsent = this.generateFullConsentObject();
                this.saveConsent(fullConsent);
                this.closeBanner(overlay);
            });
        }

        // Save Consent — save exactly what's currently checked (includes preselected)
        const saveConsentBtn = shadow.getElementById('yc-btn-save-consent');
        if (saveConsentBtn) {
            saveConsentBtn.addEventListener('click', () => {
                const consent = this.readCheckboxConsent(shadow);
                this.saveConsent(consent);
                this.closeBanner(overlay);
            });
        }

        // Preferences / Save — same as Save Consent (reads checkbox states)
               const manageBtn = shadow.getElementById('yc-btn-manage');
        if (manageBtn) {
            manageBtn.addEventListener('click', () => {
                const detailsTabBtn = shadow.querySelector('.yc-tab-btn[data-tab="details"]');
                if (detailsTabBtn) detailsTabBtn.click();
            });
        }

        // Language Switcher Toggle
        const langSelect = shadow.querySelector('.yc-lang-select');
        if (langSelect) {
            langSelect.addEventListener('change', (e) => {
                const newLang = e.target.value;
                const expires = new Date();
                expires.setTime(expires.getTime() + (365 * 24 * 60 * 60 * 1000));
                document.cookie = `ycookies_lang=${newLang};expires=${expires.toUTCString()};path=/;SameSite=Lax`;
                
                if (window.YCookies) {
                    window.YCookies.config = null;
                }
                
                const url = new URL(window.location.href);
                if (url.searchParams.has('lang')) {
                    url.searchParams.set('lang', newLang);
                    window.location.href = url.toString();
                } else {
                    window.location.reload();
                }
            });
        }    const saveBtn = shadow.getElementById('yc-btn-save');
        if (saveBtn) {
            saveBtn.addEventListener('click', () => {
                const consent = this.readCheckboxConsent(shadow);
                this.saveConsent(consent);
                this.closeBanner(overlay);
            });
        }

        // Essential Only — accept ONLY required groups, deny everything else
        const essOnlyBtn = shadow.getElementById('yc-btn-essential-only');
        if (essOnlyBtn) {
            essOnlyBtn.addEventListener('click', () => {
                const consent = this.generateEssentialOnlyConsent();
                this.saveConsent(consent);
                this.closeBanner(overlay);
            });
        }

        // Manage / Essential — same as Essential Only
        const essBtn = shadow.getElementById('yc-btn-essential');
        if (essBtn) {
            essBtn.addEventListener('click', () => {
                const consent = this.generateEssentialOnlyConsent();
                this.saveConsent(consent);
                this.closeBanner(overlay);
            });
        }
    }

    /**
     * Reads checkbox states from the banner's shadow DOM and builds a consent object.
     */
    readCheckboxConsent(shadow) {
        const consent = {};
        if (this.config && this.config.cookie_groups) {
            this.config.cookie_groups.forEach(group => {
                const chk = shadow.getElementById(`yc-chk-${group.key}`);
                const isChecked = chk ? chk.checked : !!group.is_required;
                consent[group.key] = isChecked;

                // Also read individual service checkboxes
                if (group.services) {
                    group.services.forEach(svc => {
                        const svcChk = shadow.getElementById(`yc-chk-svc-${svc.key}`);
                        consent[svc.key] = svcChk ? svcChk.checked : isChecked;
                    });
                }
                // Read virtual service checkboxes (discovered resources)
                if (group.virtual_services) {
                    group.virtual_services.forEach(vs => {
                        const vsChk = shadow.getElementById(`yc-chk-svc-${vs.key}`);
                        consent[vs.key] = vsChk ? vsChk.checked : isChecked;
                    });
                }
            });
        }
        return consent;
    }

    /**
     * Generates a consent object where only required groups are consented
     */
    generateEssentialOnlyConsent() {
        const consent = {};
        if (this.config && this.config.cookie_groups) {
            this.config.cookie_groups.forEach(group => {
                consent[group.key] = !!group.is_required;
                if (group.services) {
                    group.services.forEach(svc => {
                        consent[svc.key] = !!group.is_required;
                    });
                }
                if (group.virtual_services) {
                    group.virtual_services.forEach(vs => {
                        consent[vs.key] = !!group.is_required;
                    });
                }
            });
        }
        return consent;
    }

    /**
     * Traps focus within the provided container (e.g., banner shadow root)
     * Suitable for dialog/modal accessibility.
     */
    trapFocus(shadowRoot, overlayElement) {
        // Find all focusable elements inside the shadow root window
        const focusableElementsString = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';
        let focusableElements = shadowRoot.querySelectorAll(focusableElementsString);
        
        // Filter out non-visible elements
        focusableElements = Array.from(focusableElements).filter(el => {
            return el.offsetWidth > 0 || el.offsetHeight > 0 || el.getClientRects().length > 0;
        });

        if (focusableElements.length === 0) return;

        const firstTabStop = focusableElements[0];
        const lastTabStop = focusableElements[focusableElements.length - 1];

        // Focus the first element initially
        setTimeout(() => firstTabStop.focus(), 100);

        shadowRoot.addEventListener('keydown', function(e) {
            if (e.key === 'Tab' || e.keyCode === 9) {
                // If Shift + Tab
                if (e.shiftKey) {
                    if (shadowRoot.activeElement === firstTabStop) {
                        e.preventDefault();
                        lastTabStop.focus();
                    }
                } else {
                    // If Tab
                    if (shadowRoot.activeElement === lastTabStop) {
                        e.preventDefault();
                        firstTabStop.focus();
                    }
                }
            } else if (e.key === 'Escape' || e.keyCode === 27) {
                // Not closing the consent banner on ESC usually (as it's often blocking),
                // but some regions require dismissibility.
            }
        });
    }

    /**
     * Re-opens the consent banner so the user can change their preferences.
     * Called by the floating "reopen widget" and the privacy policy page button.
     */
    showDialog() {
        // Remove existing banner/overlay if present
        if (this.uiContainer && this.uiContainer.parentNode) {
            this.uiContainer.parentNode.removeChild(this.uiContainer);
            this.uiContainer = null;
        }
        
        // Re-render the banner
        this.renderConsentBanner();
        
        // Pre-check boxes based on existing consent state
        const shadow = this._shadow;
        if (shadow && this.config && this.config.cookie_groups) {
            this.config.cookie_groups.forEach(group => {
                const chk = shadow.getElementById(`yc-chk-${group.key}`);
                if (chk && !chk.disabled) {
                    chk.checked = !!this.consentState[group.key];
                    // Also update child service checkboxes
                    if (group.services) {
                        group.services.forEach(svc => {
                            const svcChk = shadow.getElementById(`yc-chk-svc-${svc.key}`);
                            if (svcChk && !svcChk.disabled) {
                                svcChk.checked = !!this.consentState[svc.key];
                            }
                        });
                    }
                }
            });
        }
    }

    /**
     * Extracts tenant UI config and maps it to a dynamic stylesheet
     */
    generateDynamicCss() {
        const ui = this.config.ui_config || {};
        const rawColors = ui.colors || {};
        const colors = {
            primary: rawColors.primary || '#3b82f6',
            background: rawColors.background || '#111827',
            text: rawColors.text || '#f3f4f6',
            link: rawColors.link || '#60a5fa',
        };
        const rawTypo = ui.typography || {};
        const typo = {
            font_family: rawTypo.font_family || 'system-ui, -apple-system, sans-serif',
            font_size: rawTypo.font_size || 15,
        };
        const rawEffects = ui.effects || {};
        const effects = {
            glassmorphism: rawEffects.glassmorphism ?? false,
            dark_mode: rawEffects.dark_mode ?? true,
        };
        const isPreviewMode = !!window.YCookiesPreviewMode;
        const overlayPosition = isPreviewMode ? 'absolute' : 'fixed';
        const hostPosition = isPreviewMode ? 'position:absolute;inset:0;' : '';

        const bgDrop = effects.glassmorphism ? `rgba(${this.hexToRgb(colors.background)}, 0.8)` : colors.background;
        const bannerFilter = effects.glassmorphism ? 'backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);' : '';
        const baseFontSize = typo.font_size ? `${typo.font_size}px` : '15px';

        return `:host{all:initial;display:block;${hostPosition}font-family:${typo.font_family};font-size:${baseFontSize};line-height:1.5;}
                @keyframes yc-popIn{0%{opacity:0;transform:translateY(20px) scale(0.96);}100%{opacity:1;transform:translateY(0) scale(1);}}
                .yc-overlay{position:${overlayPosition};inset:0;background:rgba(0,0,0,0.45);z-index:2147483647;display:flex;opacity:0;transition:opacity 0.3s ease;padding:24px;box-sizing:border-box;pointer-events:none;}
                .yc-overlay.yc-visible{opacity:1;pointer-events:auto;}
                
                /* Positioning Classes */
                .yc-pos-center{align-items:center;justify-content:center;}
                .yc-pos-bottom-left{align-items:flex-end;justify-content:flex-start;}
                .yc-pos-bottom-right{align-items:flex-end;justify-content:flex-end;}
                .yc-pos-bottom-center{align-items:flex-end;justify-content:center;}
                .yc-pos-top{align-items:flex-start;justify-content:center;}
                
                /* Base Banner Structure */
                .yc-banner{background:${bgDrop};${bannerFilter} border-radius:20px;width:100%;display:flex;flex-direction:column;box-shadow:0 30px 60px rgba(0,0,0,0.3), inset 0 1px 0 rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.1);color:${colors.text};}
                .yc-overlay.yc-visible .yc-banner{opacity:1;}
                
                /* ========================
                   LAYOUT: BOX MODAL (Existing)
                   ======================== */
                .yc-layout-box_modal{max-width:580px;border-radius:24px;}
                .yc-layout-box_modal .yc-header{padding:32px 32px 16px;border-bottom:none;text-align:center;}
                .yc-layout-box_modal .yc-title{font-size:1.5rem;font-weight:700;margin:0;}
                .yc-layout-box_modal .yc-body{padding:0 32px 24px;text-align:center;}
                .yc-layout-box_modal .yc-desc{margin-bottom:20px;font-size:1rem;opacity:0.85;color:${colors.text};}
                .yc-layout-box_modal .yc-footer{padding:24px 32px;background:rgba(0,0,0,0.15);display:flex;flex-direction:column;gap:12px;border-bottom-left-radius:24px;border-bottom-right-radius:24px;border-top:1px solid rgba(255,255,255,0.05);}
                .yc-layout-box_modal .yc-links{padding-top:8px;}

                /* ========================
                   LAYOUT: BOX COMPACT (Existing)
                   ======================== */
                .yc-layout-box_compact{max-width:420px;border-radius:20px;font-size:0.95em;}
                .yc-layout-box_compact .yc-header{padding:24px 24px 12px;border:none;}
                .yc-layout-box_compact .yc-title{font-size:1.25rem;}
                .yc-layout-box_compact .yc-body{padding:0 24px 24px;}
                .yc-layout-box_compact .yc-footer{padding:20px 24px;display:flex;flex-direction:column;gap:12px;background:rgba(0,0,0,0.1);border-top:1px solid rgba(255,255,255,0.05);}

                /* ========================
                   LAYOUT: BAR MODERN (Existing)
                   ======================== */
                .yc-layout-bar_modern{max-width:1100px;flex-direction:row;align-items:stretch;border-radius:20px;overflow:hidden;}
                .yc-layout-bar_modern .yc-header{display:none;}
                .yc-layout-bar_modern .yc-body{padding:32px;display:flex;flex-direction:column;justify-content:center;flex:1;}
                .yc-layout-bar_modern .yc-title{display:block;font-size:1.25rem;font-weight:700;margin-bottom:8px;}
                .yc-layout-bar_modern .yc-footer{padding:32px;background:rgba(0,0,0,0.1);border-left:1px solid rgba(255,255,255,0.08);border-top:none;display:flex;flex-direction:column;justify-content:center;gap:12px;min-width:280px;}
                .yc-layout-bar_modern .yc-links{flex-direction:row;justify-content:flex-start;margin-top:12px;}

                /* ========================
                   LAYOUT: BAR ULTRASLIM (Existing)
                   ======================== */
                .yc-layout-bar_ultraslim{max-width:100%;border-radius:0;border:none;flex-direction:row;align-items:center;padding:16px 32px;box-shadow:0 -10px 30px rgba(0,0,0,0.2);}
                .yc-layout-bar_ultraslim .yc-header{display:none;}
                .yc-layout-bar_ultraslim .yc-body{padding:0;display:flex;flex-direction:row;align-items:center;gap:24px;flex:1;}
                .yc-layout-bar_ultraslim .yc-desc{margin:0;font-size:0.95rem;}
                .yc-layout-bar_ultraslim .yc-footer{padding:0;background:transparent;border:none;display:flex;flex-direction:row;align-items:center;gap:12px;}
                .yc-layout-bar_ultraslim .yc-btn{width:auto;padding:10px 20px;white-space:nowrap;border-radius:12px;}
                .yc-layout-bar_ultraslim .yc-links{display:none;}

                /* ========================
                   NEW: BOX FLOAT CORNER
                   ======================== */
                .yc-layout-box_float_corner{max-width:380px;border-radius:24px;box-shadow:0 30px 60px rgba(0,0,0,0.4);margin:16px;}
                .yc-layout-box_float_corner .yc-header{padding:28px 28px 12px;border:none;}
                .yc-layout-box_float_corner .yc-title{font-size:1.15rem;font-weight:700;}
                .yc-layout-box_float_corner .yc-body{padding:0 28px 20px;font-size:0.9rem;}
                .yc-layout-box_float_corner .yc-footer{padding:20px 28px;background:transparent;border-top:1px solid rgba(255,255,255,0.08);display:flex;flex-direction:column;gap:10px;}
                .yc-layout-box_float_corner .yc-btn{border-radius:14px;padding:12px;}

                /* ========================
                   NEW: BOX MODERN GLASS
                   ======================== */
                .yc-layout-box_modern_glass{max-width:540px;border-radius:32px;background:rgba(${this.hexToRgb(colors.background)}, 0.45) !important;backdrop-filter:blur(32px) saturate(200%) !important;-webkit-backdrop-filter:blur(32px) saturate(200%) !important;border:1px solid rgba(255,255,255,0.2);box-shadow:inset 0 0 0 1px rgba(255,255,255,0.1), 0 40px 80px rgba(0,0,0,0.5);}
                .yc-layout-box_modern_glass .yc-header{padding:40px 40px 16px;border:none;text-align:center;}
                .yc-layout-box_modern_glass .yc-title{font-size:1.75rem;font-weight:800;background:linear-gradient(135deg, #fff 0%, rgba(255,255,255,0.6) 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
                .yc-layout-box_modern_glass .yc-body{padding:0 40px 24px;text-align:center;}
                .yc-layout-box_modern_glass .yc-footer{padding:32px 40px;background:rgba(0,0,0,0.2);display:flex;flex-direction:column;gap:16px;border-bottom-left-radius:32px;border-bottom-right-radius:32px;}
                .yc-layout-box_modern_glass .yc-btn{border-radius:100px;font-weight:700;text-transform:uppercase;letter-spacing:1px;font-size:0.8rem;padding:16px 32px;}

                /* ========================
                /* ========================
                   NEW: BAR SPLIT
                   ======================== */
                .yc-layout-bar_split{max-width:1200px;flex-direction:row;align-items:center;border-radius:100px;padding:24px 40px;margin:24px;box-shadow:0 25px 50px rgba(0,0,0,0.4), inset 0 1px 0 rgba(255,255,255,0.15);}
                .yc-layout-bar_split .yc-header{display:none;}
                .yc-layout-bar_split .yc-body{padding:0;flex:1;display:flex;flex-direction:column;align-items:flex-start;justify-content:center;gap:12px;margin:0;}
                .yc-layout-bar_split .yc-desc{margin:0;font-size:0.9rem;font-weight:400;padding-right:24px;}
                .yc-layout-bar_split .yc-list{display:flex;flex-direction:row;align-items:center;margin:0;padding:0;overflow:visible;max-height:none;gap:16px;}
                .yc-layout-bar_split .yc-item{margin:0;border:none;border-radius:0;background:transparent;white-space:nowrap;padding:0;}
                .yc-layout-bar_split .yc-item:hover{background:transparent;border-color:transparent;}
                .yc-layout-bar_split .yc-item-header{padding:0;display:flex;flex-direction:row;align-items:center;}
                .yc-layout-bar_split .yc-checkbox{margin-right:8px;}
                .yc-layout-bar_split .yc-item-title{font-size:0.9rem;font-weight:500;display:flex;align-items:center;gap:0;}
                .yc-layout-bar_split .yc-item-title .yc-badge{display:none;}
                .yc-layout-bar_split .yc-item-header .toggle-desc{display:none;}
                .yc-layout-bar_split .yc-footer{padding:0 0 0 32px;background:transparent;border:none;display:flex;flex-direction:row;align-items:center;gap:12px;border-left:1px solid rgba(255,255,255,0.1);}
                .yc-layout-bar_split .yc-btn{border-radius:100px;padding:10px 24px;width:auto;white-space:nowrap;font-size:0.9rem;}
                .yc-layout-bar_split .yc-links{display:none;}
                
                /* Accordion List Elements */
                .yc-list{list-style:none;margin:20px 0 0;padding:0;max-height:45vh;overflow-y:auto;text-align:left;}
                .yc-list::-webkit-scrollbar{width:6px;}
                .yc-list::-webkit-scrollbar-track{background:rgba(255,255,255,0.05);border-radius:4px;}
                .yc-list::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.2);border-radius:4px;}
                .yc-item{border:1px solid rgba(255,255,255,0.1);border-radius:12px;margin-bottom:10px;background:rgba(255,255,255,0.03);overflow:hidden;transition:all 0.2s;}
                .yc-item:hover{background:rgba(255,255,255,0.06);border-color:rgba(255,255,255,0.15);}
                .yc-item-header{display:flex;align-items:center;padding:16px;}
                .yc-checkbox{margin-right:16px;width:20px;height:20px;accent-color:${colors.primary};cursor:pointer;transition:transform 0.1s;}
                .yc-checkbox:active{transform:scale(0.9);}
                .yc-item-title{font-weight:600;font-size:1rem;cursor:pointer;flex:1;display:flex;align-items:center;gap:10px;}
                .yc-badge{opacity:0.6;font-size:0.85rem;font-weight:400;background:rgba(255,255,255,0.1);padding:2px 8px;border-radius:20px;}
                .yc-item-desc{font-size:0.9rem;opacity:0.85;line-height:1.6;padding:0 16px 20px 52px;display:none;}
                .yc-item.expanded .yc-item-desc{display:block;animation:fadeDesc 0.3s ease;}
                @keyframes fadeDesc{from{opacity:0;transform:translateY(-5px);}to{opacity:0.85;transform:translateY(0);}}
                .yc-services{margin-top:16px;border-top:1px dashed rgba(255,255,255,0.1);padding-top:16px;}
                .yc-svc-item{margin-bottom:12px;display:flex;flex-direction:column;gap:8px;background:rgba(0,0,0,0.15);padding:14px;border-radius:10px;}
                .yc-svc-item:last-child{margin-bottom:0;}
                .yc-svc-item-header{display:flex;align-items:center;width:100%;}
                .yc-svc-title{font-weight:600;font-size:0.95rem;}
                .yc-svc-provider{font-size:0.8rem;opacity:0.7;margin-left:auto;background:rgba(255,255,255,0.05);padding:4px 8px;border-radius:6px;}
                .yc-svc-details-row{font-size:0.85rem;opacity:0.8;line-height:1.5;margin-bottom:8px;}
                .yc-svc-details-row a{color:${colors.link};text-decoration:none;border-bottom:1px solid currentColor;}
                .yc-svc-purpose{font-size:0.85rem;line-height:1.5;opacity:0.9;margin-bottom:8px;}
                
                /* Tables inside accordion */
                .yc-cookies-table{width:100%;border-collapse:collapse;font-size:0.8rem;margin-top:12px;background:rgba(0,0,0,0.1);border-radius:8px;overflow:hidden;}
                .yc-cookies-table th, .yc-cookies-table td{text-align:left;padding:10px 12px;border-bottom:1px solid rgba(255,255,255,0.05);}
                .yc-cookies-table th{font-weight:600;opacity:0.8;background:rgba(255,255,255,0.03);}
                .yc-cookies-table tr:last-child td{border-bottom:none;}
                .yc-chevron{width:20px;height:20px;transition:transform .3s ease;opacity:0.7;}
                .yc-item.expanded .yc-chevron{transform:rotate(180deg);}
                
                /* Universal Button Styles */
                .yc-btn{cursor:pointer;border:none;padding:14px 20px;border-radius:12px;font-weight:600;font-size:0.95rem;font-family:inherit;transition:all 0.2s cubic-bezier(0.16, 1, 0.3, 1);outline:0;width:100%;display:flex;align-items:center;justify-content:center;gap:8px;}
                .yc-btn:active{transform:scale(0.97);}
                .yc-btn-primary{background:${colors.primary};color:#fff;box-shadow:0 4px 14px 0 rgba(${this.hexToRgb(colors.primary)}, 0.4);}
                .yc-btn-primary:hover{filter:brightness(1.1);transform:translateY(-2px);box-shadow:0 6px 20px rgba(${this.hexToRgb(colors.primary)}, 0.5);}
                .yc-btn-secondary{background:rgba(255,255,255,0.1);color:${colors.text};border:1px solid rgba(255,255,255,0.05);}
                .yc-btn-secondary:hover{background:rgba(255,255,255,0.15);}
                .yc-btn-outline{background:0 0;color:${colors.text};border:1px solid rgba(255,255,255,0.2);opacity:0.85;}
                .yc-btn-outline:hover{background:rgba(255,255,255,0.08);opacity:1;}
                
                .yc-links{display:flex;justify-content:center;gap:20px;font-size:0.85rem;}
                .yc-links a{color:${colors.link};text-decoration:none;transition:all 0.2s;opacity:0.8;}
                .yc-links a:hover{opacity:1;filter:brightness(1.2);}
                
                /* Mobile Responsiveness */
                @media (max-width: 640px) {
                    .yc-layout-box_modal, .yc-layout-box_modern_glass{border-radius:20px;margin:16px;}
                    .yc-layout-box_compact .yc-footer{flex-direction:column;}
                    .yc-layout-bar_modern{flex-direction:column;border-radius:20px;margin:16px;}
                    .yc-layout-bar_modern .yc-footer{border-left:none;border-top:1px solid rgba(255,255,255,0.08);}
                    .yc-layout-bar_ultraslim{flex-direction:column;padding:20px;gap:16px;}
                    .yc-layout-bar_ultraslim .yc-body{flex-direction:column;text-align:center;}
                    .yc-layout-bar_ultraslim .yc-footer{width:100%;flex-direction:column;}
                    .yc-layout-bar_ultraslim .yc-btn{width:100%;}
                    .yc-layout-bar_split{flex-direction:column;border-radius:24px;padding:24px;margin:16px;gap:20px;}
                    .yc-layout-bar_split .yc-body{padding:0;text-align:center;}
                    .yc-layout-bar_split .yc-footer{width:100%;flex-direction:column;}
                    .yc-layout-bar_split .yc-btn{width:100%;}
                }`;
    }

    getUiMountTarget() {
        if (window.YCookiesPreviewMode) {
            return document.getElementById('ycookies-preview-canvas') || document.body;
        }

        return document.body;
    }

    /**
     * Resolve a translation value. If val is a per-language object like
     * {"en": "Accept All", "de": "Alle akzeptieren"}, extract the correct
     * locale based on the page's <html lang="xx"> attribute.
     * Falls back: exact locale → base lang → 'en' → first available → raw val.
     */
    t(val) {
        if (val === null || val === undefined) return '';
        let result = '';
        if (typeof val === 'string') {
            result = val;
        } else if (typeof val === 'object' && !Array.isArray(val)) {
            const locale = this.pageLocale;
            if (val[locale] !== undefined) {
                result = val[locale];
            } else if (val['en'] !== undefined) {
                result = val['en'];
            } else {
                const keys = Object.keys(val);
                result = keys.length > 0 ? val[keys[0]] : '';
            }
        } else {
            result = String(val);
        }
        return this.sanitizeHtml(result);
    }

    /**
     * Safely sanitizes dirty HTML strings using DOMParser.
     * Prevents XSS while preserving structural tags (<b>, <a>, <p> etc.)
     */
    sanitizeHtml(dirty) {
        if (!dirty) return '';
        try {
            const parser = new DOMParser();
            const doc = parser.parseFromString(dirty, 'text/html');
            const badTags = doc.querySelectorAll('script, iframe, object, embed, style, link, meta, base');
            badTags.forEach(el => el.remove());
            
            const all = doc.querySelectorAll('*');
            for (let i = 0; i < all.length; i++) {
                const el = all[i];
                for (let j = el.attributes.length - 1; j >= 0; j--) {
                    const attr = el.attributes[j];
                    const name = attr.name.toLowerCase();
                    const value = attr.value.toLowerCase().replace(/\s/g, '');
                    
                    if (name.startsWith('on') || 
                        (name === 'href' && value.startsWith('javascript:')) || 
                        (name === 'src' && value.startsWith('javascript:'))) {
                        el.removeAttributeNode(attr);
                    }
                }
            }
            return doc.body.innerHTML;
        } catch (e) {
            // Fallback to strict text encoding if parser fails
            return String(dirty).replace(/[&<>'"]/g, 
                tag => ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    "'": '&#39;',
                    '"': '&quot;'
                }[tag] || tag)
            );
        }
    }

    /**
     * Generates the appropriate HTML structure based on layout preference
     */
    generateBannerHtml() {
        const ui = this.config.ui_config || {};
        const layout = ui.layout || 'box_modal';
        const position = ui.position || 'center';
        
        let showAcceptAll = ui.buttons?.show_accept_all ?? true;
        let showAcceptEss = ui.buttons?.show_accept_essential ?? false;
        let showSettings = ui.buttons?.show_settings ?? true;
        let showSaveConsent = ui.buttons?.show_save_consent ?? false;
        let showAcceptEssOnly = ui.buttons?.show_accept_essential_only ?? false;
        
        const trans = this.config.translations || {};
        const bannerTrans = trans.banner || {};
        const linksTrans = trans.links || {};
        
        let groups = (this.config.cookie_groups || []).filter(g => g.services && g.services.length > 0);

        const titleText = this.t(bannerTrans.title) || 'Privacy Preferences';
        let descText = this.t(bannerTrans.description) || '';
        let acceptAllText = this.t(bannerTrans.accept_all_btn) || 'Accept All';
        let acceptEssText = this.t(bannerTrans.accept_essential_btn) || 'Manage / Essential';
        let settingsText = this.t(bannerTrans.individual_settings_btn) || 'Preferences';
        let saveConsentText = this.t(bannerTrans.save_consent_btn) || 'Save Consent';
        let acceptEssOnlyText = this.t(bannerTrans.accept_essential_only_btn) || 'Essential Cookies Only';
        
        // Define known old generic defaults to detect untouched customization
        const oldDefaults = [
            "We use cookies to personalise content and ads, to provide social media features and to analyse our traffic. We also share information about your use of our site with our social media, advertising and analytics partners who may combine it with other information that you've provided to them or that they've collected from your use of their services.",
            "Wir verwenden Cookies, um Inhalte und Anzeigen zu personalisieren, Funktionen für soziale Medien anbieten zu können und die Zugriffe auf unsere Website zu analysieren. Außerdem geben wir Informationen zu Ihrer Verwendung unserer Website an unsere Partner für soziale Medien, Werbung und Analysen weiter.",
            "نحن نستخدم ملفات تعريف الارتباط لتخصيص المحتوى والإعلانات، ولتوفير ميزات وسائل التواصل الاجتماعي ولتحليل حركة المرور لدينا. كما نشارك معلومات حول استخدامك لموقعنا مع شركائنا في وسائل التواصل الاجتماعي والإعلان والتحليلات.",
            "Utilizamos cookies para personalizar contenido y anuncios, ofrecer funciones de redes sociales y analizar nuestro tráfico. También compartimos información sobre el uso de nuestro sitio con nuestros socios de redes sociales, publicidad y análisis.",
            "We use cookies and similar technologies to ensure the proper functioning of our website, analyze our traffic, and personalize your experience. Please select your preferences below.",
            "Wir verwenden Cookies und ähnliche Technologien, um das ordnungsgemäße Funktionieren unserer Website sicherzustellen, unsere Zugriffe zu analysieren und Ihre Erfahrung zu personalisieren. Bitte wählen Sie unten Ihre Präferenzen aus.",
            "نحن نستخدم ملفات تعريف الارتباط وتقنيات مشابهة لضمان العمل السليم لموقعنا، وتحليل حركة المرور، وتخصيص تجربتك. يرجى تحديد تفضيلاتك أدناه.",
            "Utilizamos cookies y tecnologías similares para garantizar el correcto funcionamiento de nuestro sitio web, analizar nuestro tráfico y personalizar su experiencia. Por favor, seleccione sus preferencias a continuación."
        ];
        
        const isDefault = !descText || oldDefaults.includes(descText.trim());
        const lang = this.config.localization?.locale || 'en';

        if (groups.length === 0) {
            // Notice mode
            showSettings = false;
            showAcceptEss = false;
            showAcceptEssOnly = false;
            showSaveConsent = false;
            
            acceptAllText = { en: "OK", de: "OK", ar: "موافق", es: "OK" }[lang] || "OK";
            
            if (isDefault) {
                const noticeTexts = {
                    en: "This website only uses essential cookies necessary for its proper operation.",
                    de: "Diese Website verwendet nur essentielle Cookies, die für ihren ordnungsgemäßen Betrieb erforderlich sind.",
                    ar: "يستخدم هذا الموقع فقط ملفات تعريف الارتباط الأساسية الضرورية لتشغيله السليم.",
                    es: "Este sitio web solo utiliza cookies esenciales necesarias para su correcto funcionamiento."
                };
                descText = noticeTexts[lang] || noticeTexts['en'];
            }
        } else if (isDefault) {
            let hasAnalytics = groups.some(g => {
                const key = g.key.toLowerCase();
                return key.includes('analytic') || key.includes('statistic');
            });
            let hasMarketing = groups.some(g => {
                const key = g.key.toLowerCase();
                return key.includes('marketing') || key.includes('social') || key.includes('ads') || key.includes('video');
            });

            let purposesEN = ["essential operations"];
            let purposesDE = ["die grundlegenden Funktionen"];
            let purposesAR = ["العمليات الأساسية"];
            let purposesES = ["funciones básicas"];
            
            if (hasAnalytics) {
                purposesEN.push("traffic analysis");
                purposesDE.push("die Analyse der Zugriffe");
                purposesAR.push("تحليل حركة المرور");
                purposesES.push("análisis de tráfico");
            }
            if (hasMarketing) {
                purposesEN.push("personalized advertising and social features");
                purposesDE.push("personalisierte Werbung und Social-Media-Funktionen");
                purposesAR.push("الإعلانات المخصصة وميزات وسائل التواصل الاجتماعي");
                purposesES.push("publicidad personalizada y funciones sociales");
            }
            
            const formatList = (arr, andWord) => arr.length === 1 ? arr[0] : arr.slice(0, -1).join(', ') + ` ${andWord} ` + arr[arr.length - 1];
            
            const dynamicTexts = {
                en: `We use cookies and similar technologies for ${formatList(purposesEN, 'and')}. Please select your preferences below.`,
                de: `Wir verwenden Cookies und ähnliche Technologien für ${formatList(purposesDE, 'und')}. Bitte wählen Sie unten Ihre Präferenzen aus.`,
                ar: `نحن نستخدم ملفات تعريف الارتباط وتقنيات مشابهة من أجل ${formatList(purposesAR, 'و')}. يرجى تحديد تفضيلاتك أدناه.`,
                es: `Utilizamos cookies y tecnologías similares para ${formatList(purposesES, 'y')}. Por favor, seleccione sus preferencias a continuación.`
            };
            descText = dynamicTexts[lang] || dynamicTexts['en'];
        }

        const imprintText = this.t(linksTrans.imprint_text) || 'Imprint';
        const imprintUrl = this.t(linksTrans.imprint_url) || '#';
        const privacyText = this.t(linksTrans.privacy_text) || 'Privacy';
        const privacyUrl = this.t(linksTrans.privacy_url) || '#';

        // Hide accordion list completely on ultraslim
        const showList = layout !== 'bar_ultraslim';

        let groupsHtml = '';
        if (showList && groups.length > 0) {
            groupsHtml = groups.map(group => {
                const servicesHtml = group.services.map(svc => {
                    let providerDetailsHtml = '';
                    if (svc.provider_details) {
                        providerDetailsHtml = `
                            <div class="yc-svc-details-row">
                                <strong>Provider:</strong> ${this.t(svc.provider) || ''} <br>
                                ${svc.provider_details.address ? `<span>${this.sanitizeHtml(svc.provider_details.address)}</span><br>` : ''}
                                ${svc.provider_details.privacy_policy_url && !svc.provider_details.privacy_policy_url.toLowerCase().trim().startsWith('javascript:') 
                                    ? `<a href="${svc.provider_details.privacy_policy_url.replace(/"/g, '&quot;')}" target="_blank" rel="noopener noreferrer">Privacy Policy</a>` 
                                    : ''}
                            </div>
                        `;
                    }
                    
                    let cookiesTableHtml = '';
                    if (svc.cookies && svc.cookies.length > 0) {
                        const rows = svc.cookies.map(c => `
                            <tr>
                                <td>${c.name}</td>
                                <td>${c.hostname || ''}</td>
                                <td>${c.lifetime || ''}</td>
                            </tr>
                        `).join('');
                        cookiesTableHtml = `
                            <table class="yc-cookies-table">
                                <thead><tr><th>Name</th><th>Host</th><th>Duration</th></tr></thead>
                                <tbody>${rows}</tbody>
                            </table>
                        `;
                    }

                    return `
                    <div class="yc-svc-item">
                        <div class="yc-svc-item-header">
                            <input type="checkbox" class="yc-checkbox yc-svc-chk" id="yc-chk-svc-${svc.key}" data-group="${group.key}" ${group.is_required ? 'checked disabled' : (this.gpcActive ? 'disabled' : (group.is_preselected ? 'checked' : ''))}>
                            <label class="yc-svc-title" for="yc-chk-svc-${svc.key}">${this.t(svc.name)}</label>
                            <span class="yc-svc-provider">${this.t(svc.provider) || ''}</span>
                        </div>
                        <div class="yc-svc-expanded-area">
                            ${providerDetailsHtml}
                            ${this.t(svc.purpose) ? `<div class="yc-svc-purpose">${this.t(svc.purpose)}</div>` : ''}
                            ${cookiesTableHtml}
                        </div>
                    </div>
                    `;
                }).join('');
                // Render virtual services (discovered resources) under this group
                let virtualServicesHtml = '';
                if (group.virtual_services && group.virtual_services.length > 0) {
                    virtualServicesHtml = group.virtual_services.map(vs => `
                    <div class="yc-svc-item">
                        <div class="yc-svc-item-header">
                            <input type="checkbox" class="yc-checkbox yc-svc-chk" id="yc-chk-svc-${vs.key}" data-group="${group.key}" ${this.gpcActive ? 'disabled' : ''}>
                            <label class="yc-svc-title" for="yc-chk-svc-${vs.key}">${this.sanitizeHtml(vs.name)}</label>
                            <span class="yc-svc-provider" style="font-size:0.8em;opacity:0.7;">${vs.resource_types ? vs.resource_types.join(', ') : ''}</span>
                        </div>
                        <div class="yc-svc-expanded-area">
                            ${vs.purpose ? `<div class="yc-svc-purpose">${this.sanitizeHtml(vs.purpose)}</div>` : ''}
                        </div>
                    </div>
                    `).join('');
                }

                const totalProviders = group.services.length + (group.virtual_services ? group.virtual_services.length : 0);
                // Skip uncategorized group entirely when it has no services or virtual services
                if (group.key === 'uncategorized' && totalProviders === 0) return '';

                return `
                <li class="yc-item" id="yc-item-${group.key}">
                    <div class="yc-item-header">
                        <input type="checkbox" class="yc-checkbox yc-group-chk" id="yc-chk-${group.key}" data-group="${group.key}" ${group.is_required ? 'checked disabled' : (this.gpcActive ? 'disabled' : (group.is_preselected ? 'checked' : ''))}>
                        <label class="yc-item-title" for="yc-chk-${group.key}">
                            <span>${this.t(group.name)}</span>
                            <span class="yc-badge">(${totalProviders} Provider)</span>
                        </label>
                        <button type="button" class="yc-toggle-btn toggle-desc" data-target="yc-item-${group.key}" aria-expanded="false" aria-controls="yc-item-desc-${group.key}" aria-label="Toggle details" style="background:transparent;border:none;padding:0;cursor:pointer;display:inline-flex;align-items:center;">
                            <svg class="yc-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:24px;height:24px;"><path d="M19 9l-7 7-7-7"/></svg>
                        </button>
                    </div>
                    <div class="yc-item-desc" id="yc-item-desc-${group.key}">
                        ${this.t(group.description) ? `<p style="margin-top:0;">${this.t(group.description)}</p>` : ''}
                        ${totalProviders > 0 ? `<div class="yc-services">${servicesHtml}${virtualServicesHtml}</div>` : ''}
                    </div>
                </li>
                `;
            }).join('');
        }
        
        const isRtl = this.config?.localization?.current_is_rtl || false;
        
        let languageSwitcherHtml = '';
        if (this.config?.localization?.show_switcher && this.config?.languages && Object.keys(this.config.languages).length > 1) {
            const urlParams = new URLSearchParams(window.location.search);
            const activeLang = urlParams.get('lang') || document.cookie.match(/(^| )ycookies_lang=([^;]+)/)?.[2] || document.documentElement.lang || navigator.language.split('-')[0] || this.config.localization?.default_language || 'en';
            
            const options = Object.values(this.config.languages).map(l => {
                return `<option value="${l.code}" ${activeLang.startsWith(l.code) ? 'selected' : ''}>${l.name}</option>`;
            }).join('');
            
            languageSwitcherHtml = `
                <div class="yc-lang-switcher" style="padding: 12px 32px 0;">
                    <select class="yc-lang-select" style="background: transparent; color: inherit; border: 1px solid rgba(128,128,128,0.3); padding: 4px 8px; border-radius: 4px; font-size: 0.85rem; cursor: pointer; border-radius: 10px; width: 100%;">
                        ${options}
                    </select>
                </div>
            `;
            
            if (layout === 'bar_modern') {
                 languageSwitcherHtml = languageSwitcherHtml.replace('padding: 12px 32px 0;', 'padding: 12px 40px 0;');
            } else if (layout === 'bar_ultraslim') {
                 languageSwitcherHtml = languageSwitcherHtml.replace('padding: 12px 32px 0;', 'padding: 0; margin-left: 12px; margin-right: 12px;');
                 languageSwitcherHtml = languageSwitcherHtml.replace('width: 100%;', 'width: auto;');
            }
        }

        const bannerHtml = `
            <div class="yc-banner yc-layout-${layout}" role="dialog" aria-modal="true" aria-labelledby="yc-title" ${isRtl ? 'dir="rtl"' : ''}>
                
                <div class="yc-header">
                    <h2 class="yc-title" id="yc-title">${titleText}</h2>
                </div>
                ${languageSwitcherHtml && layout !== 'bar_ultraslim' ? languageSwitcherHtml : ''}
                
                <div class="yc-body-wrapper">
                    <div class="yc-body">
                        <p class="yc-desc">${descText}</p>
                        ${showList ? `<ul class="yc-list">${groupsHtml}</ul>` : ''}
                    </div>
                </div>
                <div class="yc-footer">
                    ${languageSwitcherHtml && layout === 'bar_ultraslim' ? languageSwitcherHtml : ''}
                    ${showAcceptAll ? `<button class="yc-btn yc-btn-primary" id="yc-btn-accept">${acceptAllText}</button>` : ''}
                    ${showSaveConsent ? `<button class="yc-btn yc-btn-secondary" id="yc-btn-save-consent">${saveConsentText}</button>` : ''}
                    ${layout !== 'bar_ultraslim' && showSettings ? `<button class="yc-btn yc-btn-secondary" id="yc-btn-save">${settingsText}</button>` : ''}
                    ${showAcceptEssOnly ? `<button class="yc-btn yc-btn-outline" id="yc-btn-essential-only">${acceptEssOnlyText}</button>` : ''}
                    ${showAcceptEss ? `<button class="yc-btn yc-btn-outline" id="yc-btn-essential">${acceptEssText}</button>` : ''}
                    <div class="yc-links">
                        <a href="${privacyUrl}" target="_blank" rel="noopener noreferrer">${privacyText}</a>
                        <a href="${imprintUrl}" target="_blank" rel="noopener noreferrer">${imprintText}</a>
                    </div>
                </div>
            </div>
        `;

        return { positionClass: position, bannerHtml };
    }

    hexToRgb(hex) {
        if (!hex) return '0,0,0';
        // Expand shorthand form (e.g. "03F") to full form (e.g. "0033FF")
        var shorthandRegex = /^#?([a-f\d])([a-f\d])([a-f\d])$/i;
        hex = hex.replace(shorthandRegex, function (m, r, g, b) {
            return r + r + g + g + b + b;
        });

        var result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
        return result ?
            `${parseInt(result[1], 16)}, ${parseInt(result[2], 16)}, ${parseInt(result[3], 16)}`
            : '0,0,0';
    }

    /**
     * Animates the banner out and removes it from the DOM
     */
    closeBanner(overlayElement) {
        overlayElement.classList.remove('yc-visible'); // Trigger CSS fade out
        
        // Show the floating widget again if it exists
        if (this.reopenWidgetNode) {
            this.reopenWidgetNode.style.display = 'block';
        }
        
        setTimeout(() => {
            if (this.uiContainer && this.uiContainer.parentNode) {
                this.uiContainer.parentNode.removeChild(this.uiContainer);
                this.uiContainer = null;
            }
        }, 300); // Matches CSS transition duration
    }

    /**
     * Injects the floating Fingerprint "Reopen" widget if enabled
     */
    injectReopenWidget() {
        const ui = this.config?.ui_config || {};
        const showWidget = ui.show_reopen_widget ?? true;
        if (!showWidget) return;

        // Prevent duplicates
        if (document.getElementById('ycookies-reopen-widget')) return;

        const widgetContainer = document.createElement('div');
        widgetContainer.id = 'ycookies-reopen-widget';
        document.body.appendChild(widgetContainer);

        const shadow = widgetContainer.attachShadow({ mode: 'closed' });
        
        const colors = ui.colors || { primary: '#3b82f6', background: '#111827', text: '#f3f4f6' };

        shadow.innerHTML = `
            <style>
                .yc-reopen-btn {
                    position: fixed;
                    bottom: 24px;
                    left: 24px;
                    width: 48px;
                    height: 48px;
                    border-radius: 50%;
                    background: ${colors.primary};
                    color: white;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    cursor: pointer;
                    box-shadow: 0 4px 14px rgba(0,0,0,0.25);
                    z-index: 2147483646; /* One below the modal */
                    transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.2s;
                    border: 1px solid rgba(255,255,255,0.1);
                }
                .yc-reopen-btn:hover {
                    transform: scale(1.08) translateY(-2px);
                    box-shadow: 0 8px 20px rgba(0,0,0,0.3);
                }
                .yc-reopen-btn svg {
                    width: 24px;
                    height: 24px;
                }
            </style>
            <button type="button" class="yc-reopen-btn" id="yc-reopen-btn" aria-label="Cookie Preferences" title="Cookie Preferences">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-fingerprint"><path d="M12 12v.01"/><path d="M8 16v.01"/><path d="M16 16v.01"/><path d="M12 20v.01"/><path d="M22 12a10 10 0 1 0-20 0"/><path d="M9 8a3 3 0 0 1 6 0"/><path d="M6 10a6 6 0 0 1 12 0"/><path d="M3 14a9 9 0 0 1 18 0"/></svg>
            </button>
        `;

        shadow.getElementById('yc-reopen-btn').addEventListener('click', () => {
             this.showDialog();
             widgetContainer.style.display = 'none';
        });
        
        this.reopenWidgetNode = widgetContainer;
    }

    /**
     * Renders a dynamically generated table of accepted cookies into a target div, 
     * typically placed on the client's Privacy Policy page.
     */
    renderPrivacyPolicyTable() {
        const targetDiv = document.getElementById('ycookies-accepted-list');
        if (!targetDiv) return;
        
        if (!this.config || !this.config.cookie_groups) {
            targetDiv.innerHTML = '<p>Cookie preferences could not be loaded.</p>';
            return;
        }

        const ui = this.config.ui_config || {};
        const colors = ui.colors || { primary: '#3b82f6', text: '#2d3748', link: '#3b82f6' };
        
        let html = `
            <style>
                .yc-policy-table { width: 100%; border-collapse: collapse; margin-top: 10px; margin-bottom: 20px; font-family: inherit; font-size: 0.9em; }
                .yc-policy-table th, .yc-policy-table td { text-align: left; padding: 10px; border-bottom: 1px solid rgba(125,125,125,0.2); }
                .yc-policy-table th { font-weight: 600; opacity: 0.9; }
                .yc-policy-table td { opacity: 0.8; }
                .yc-policy-group { font-weight: bold; font-size: 1.1em; margin-top: 20px; margin-bottom: 10px; color: ${colors.primary}; }
                .yc-policy-btn { background-color: ${colors.primary}; color: white; border: none; padding: 10px 15px; border-radius: 6px; cursor: pointer; font-weight: bold; transition: opacity 0.2s; margin-top:15px; }
                .yc-policy-btn:hover { opacity: 0.9; }
                .yc-policy-empty { font-style: italic; opacity: 0.7; font-size: 0.9em; margin-bottom: 15px; }
                .yc-policy-service-title { font-weight: 600; font-size: 1em; margin-top: 15px; margin-bottom: 5px; }
            </style>
        `;

        let hasAnyConsent = false;

        this.config.cookie_groups.forEach(group => {
            const hasGroupConsent = this.consentState[group.key] || group.is_required;
            if (!hasGroupConsent) return; // Skip groups user didn't consent to (and aren't required)

            let groupHasServices = false;
            let servicesHtml = '';

            group.services.forEach(service => {
                groupHasServices = true;
                hasAnyConsent = true;

                servicesHtml += `<div class="yc-policy-service-title">${this.t(service.name)} (${this.t(service.provider) || 'Unknown Provider'})</div>`;
                
                if (service.cookies && service.cookies.length > 0) {
                    const rows = service.cookies.map(c => `
                        <tr>
                            <td>${c.name}</td>
                            <td>${c.hostname || ''}</td>
                            <td>${c.lifetime || ''}</td>
                        </tr>
                    `).join('');
                    
                    servicesHtml += `
                        <table class="yc-policy-table">
                            <thead><tr><th>Name</th><th>Host</th><th>Duration</th></tr></thead>
                            <tbody>${rows}</tbody>
                        </table>
                    `;
                } else {
                     servicesHtml += `<div class="yc-policy-empty">No specific cookies listed for this service.</div>`;
                }
            });

            if (groupHasServices) {
                html += `<div class="yc-policy-group">${this.t(group.name)}</div>`;
                html += servicesHtml;
            }
        });

        if (!hasAnyConsent) {
            html += `<p>You have not accepted any optional cookies.</p>`;
        }

        html += `<div><button class="yc-policy-btn" id="yc-policy-change-btn">Change your consent</button></div>`;

        targetDiv.innerHTML = html;

        const btn = document.getElementById('yc-policy-change-btn');
        if (btn) {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                this.showDialog();
            });
        }
    }

    /**
     * Dynamically injects the Opt-In HTML payloads for services the user has consented to.
     */
    injectConsentedServices() {
        if (!this.config || !this.config.cookie_groups) {
            // Consent changes can still unblock server-rendered placeholders
            // even when no runtime cookie_groups are available.
            this.unblockServerBlockedScripts();
            this.unblockServerBlockedStyles();
            this.unblockServerBlockedContent();
            return;
        }

        console.log('[YCookies] Injecting Consented Services...');
        this.config.cookie_groups.forEach(group => {
            // Check if user consented to this group (or if it's strictly required)
            if (this.consentState[group.key] || group.is_required) {

                group.services.forEach(service => {
                    // Prevent double-injection if state updates multiple times
                    if (window[`ycookies_injected_${service.key}`]) return;

                    if (service.payloads && service.payloads.opt_in) {
                        this.executeHtmlPayload(service.payloads.opt_in);
                    }
                    if (service.integrations) {
                        this.injectIntegrations(service.integrations);
                    }
                    // Mark as injected
                    window[`ycookies_injected_${service.key}`] = true;
                });

            }
        });

        // Also unblock any server-side blocked scripts and content
        this.unblockServerBlockedScripts();
        this.unblockServerBlockedStyles();
        this.unblockServerBlockedContent();
    }

    /**
     * Unblocks scripts blocked server-side by ScriptBlockerMiddleware.
     * Restores type=text/template scripts to executable when consent is granted.
     */
    unblockServerBlockedScripts() {
        const blockedScripts = document.querySelectorAll('script[data-ycookies-blocked="true"]');
        if (blockedScripts.length === 0) return;
        console.log(`[YCookies] Unblocking ${blockedScripts.length} server-blocked script(s)`);
        blockedScripts.forEach(script => {
            const serviceKey = script.getAttribute('data-ycookies-service');
            const requireGroup = script.getAttribute('data-ycookies-require-group');
            const host = script.getAttribute('data-ycookies-host');
            let mayUnblock = this.shouldAllowByServiceOrGroup(serviceKey, requireGroup, 'marketing');
            // Per-provider discovered resource consent
            if (!mayUnblock && host) {
                const discKey = 'disc-' + host.replace(/\./g, '-');
                if (this.consentState[discKey]) mayUnblock = true;
            }
            if (!mayUnblock && host) {
                this.reportDiscoveredResource(script.src || '', 'script');
            }

            if (mayUnblock) {
                const newScript = document.createElement('script');
                Array.from(script.attributes).forEach(attr => {
                    if (!attr.name.startsWith('data-ycookies')) newScript.setAttribute(attr.name, attr.value);
                });
                newScript.removeAttribute('type');
                if (script.src) newScript.src = script.src;
                newScript.textContent = script.textContent;
                newScript.setAttribute('data-ycookies-injected', 'true');
                script.parentNode.replaceChild(newScript, script);
            }
        });
    }

    /**
     * Unblocks stylesheet links blocked server-side by the proxy stream.
     */
    unblockServerBlockedStyles() {
        const blockedStyles = document.querySelectorAll('link[data-ycookies-style-blocked="true"]');
        if (blockedStyles.length === 0) return;
        console.log(`[YCookies] Unblocking ${blockedStyles.length} server-blocked stylesheet(s)`);

        blockedStyles.forEach(link => {
            const href = link.getAttribute('data-ycookies-style-href');
            if (!href) return;

            const serviceKey = link.getAttribute('data-ycookies-service');
            const requireGroup = link.getAttribute('data-ycookies-require-group');
            const host = link.getAttribute('data-ycookies-host');
            let mayUnblock = this.shouldAllowByServiceOrGroup(serviceKey, requireGroup, 'marketing');
            if (!mayUnblock && host) {
                const discKey = 'disc-' + host.replace(/\./g, '-');
                if (this.consentState[discKey]) mayUnblock = true;
            }
            if (!mayUnblock && host) {
                this.reportDiscoveredResource(href, 'style');
            }

            if (mayUnblock) {
                link.setAttribute('href', href);
                link.removeAttribute('data-ycookies-style-blocked');
                link.removeAttribute('data-ycookies-style-href');
                link.removeAttribute('data-ycookies-blocker-id');
                link.removeAttribute('data-ycookies-service');
                link.removeAttribute('data-ycookies-require-group');
                link.removeAttribute('data-ycookies-provider');
            }
        });
    }

    /**
     * Unblocks content blocked server-side by ContentBlockerMiddleware.
     * Decodes base64-encoded original tags from placeholder divs.
     */
    unblockServerBlockedContent() {
        const blockedContent = document.querySelectorAll('.ycookies-content-blocker');
        if (blockedContent.length === 0) return;
        console.log(`[YCookies] Unblocking ${blockedContent.length} server-blocked content element(s)`);
        blockedContent.forEach(placeholder => {
            const serviceKey = placeholder.getAttribute('data-ycookies-service');
            const requireGroup = placeholder.getAttribute('data-ycookies-require-group');
            const isV2EmbedUi = placeholder.classList.contains('ycookies-embed-placeholder');
            // Legacy: placeholders with no metadata auto-unblock. Never apply that to v2 embed UI
            // (buttons + instance unlock) or it strips the placeholder before the user can act.
            const mayUnblock = this.shouldAllowByServiceOrGroup(serviceKey, requireGroup, 'external_media')
                || (!isV2EmbedUi && !serviceKey && !requireGroup);
            if (mayUnblock) {
                const encoded = placeholder.getAttribute('data-ycookies-original');
                if (!encoded) return;
                try {
                    const decoded = atob(encoded);
                    const temp = document.createElement('div');
                    temp.innerHTML = decoded;
                    while (temp.firstChild) placeholder.parentNode.insertBefore(temp.firstChild, placeholder);
                    placeholder.remove();
                } catch (e) {
                    console.warn('[YCookies] Failed to decode blocked content', e);
                }
            }
        });
    }

    /**
     * Cookie-group consent (e.g. external_media for universal content blockers).
     */
    hasConsentForCookieGroup(groupKey) {
        if (!groupKey) return false;
        if (this.consentState[groupKey]) return true;
        if (!this.config || !this.config.cookie_groups) return false;
        const g = this.config.cookie_groups.find(x => x.key === groupKey);
        return !!(g && g.is_required);
    }

    /**
     * Check if user has consented to a specific service (by key or through group membership).
     */
    hasConsentForService(serviceKey) {
        if (this.consentState[serviceKey]) return true;
        if (this.config && this.config.cookie_groups) {
            for (const group of this.config.cookie_groups) {
                if (this.consentState[group.key] || group.is_required) {
                    if (group.services && group.services.some(s => s.key === serviceKey)) return true;
                }
            }
        }
        return false;
    }

    getAutoBlockingConfig() {
        const defaults = { content: true, script: true, style: true, service: true };
        const cfg = (this.config && this.config.auto_blocking && typeof this.config.auto_blocking === 'object')
            ? this.config.auto_blocking
            : {};

        return {
            content: cfg.content !== undefined ? !!cfg.content : defaults.content,
            script: cfg.script !== undefined ? !!cfg.script : defaults.script,
            style: cfg.style !== undefined ? !!cfg.style : defaults.style,
            service: cfg.service !== undefined ? !!cfg.service : defaults.service,
        };
    }

    isAutoBlockingEnabled(type) {
        const cfg = this.getAutoBlockingConfig();
        return cfg[type] !== undefined ? !!cfg[type] : true;
    }

    hasExternalRuntimeConsent() {
        return this.hasConsentForCookieGroup('marketing') || this.hasConsentForCookieGroup('external_media');
    }

    shouldAllowByServiceOrGroup(serviceKey, requireGroup = null, defaultGroup = null) {
        if (serviceKey && this.hasConsentForService(serviceKey)) {
            return true;
        }
        if (requireGroup && this.hasConsentForCookieGroup(requireGroup)) {
            return true;
        }
        if (!serviceKey && !requireGroup && defaultGroup) {
            return this.hasConsentForCookieGroup(defaultGroup);
        }
        // Proxy "universal" embed placeholders use service_key "universal" but map to external_media / marketing
        if (serviceKey === 'universal' && defaultGroup) {
            return this.hasConsentForCookieGroup(defaultGroup)
                || this.hasConsentForCookieGroup('marketing');
        }
        return false;
    }

    /**
     * Safely parses and executes a raw HTML payload string containing <script> tags.
     */
    executeHtmlPayload(htmlString) {
        const parser = new DOMParser();
        const doc = parser.parseFromString(htmlString, 'text/html');

        const injectNode = (node) => {
            if (node.nodeType === Node.ELEMENT_NODE) {
                if (node.tagName.toLowerCase() === 'script') {
                    const newScript = document.createElement('script');
                    Array.from(node.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
                    newScript.textContent = node.textContent;
                    // Add a bypass flag so our MonkeyPatcher knows to let THIS script pass
                    newScript.setAttribute('data-ycookies-injected', 'true');
                    document.head.appendChild(newScript);
                } else {
                    document.body.appendChild(node.cloneNode(true));
                }
            }
        };

        doc.head.childNodes.forEach(injectNode);
        doc.body.childNodes.forEach(injectNode);
    }

    /**
     * Synthesizes tracking scripts from raw IDs to save users from writing code
     */
    injectIntegrations(integrations) {
        // Inject preconnect hint for Google on first integration injection
        if ((integrations.gtm_id || integrations.ga_id) &&
            !document.querySelector('link[href="https://www.googletagmanager.com"]')) {
            const link = document.createElement('link');
            link.rel = 'preconnect';
            link.href = 'https://www.googletagmanager.com';
            link.crossOrigin = 'anonymous';
            document.head.appendChild(link);
        }

        if (integrations.gtm_id) {
            // Push consent_granted event before GTM loads so triggers can fire immediately
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({ event: 'consent_granted' });

            console.log(`[YCookies] Injecting Google Tag Manager: ${integrations.gtm_id}`);
            const script = document.createElement('script');
            script.setAttribute('data-ycookies-injected', 'true');
            script.textContent = `
                    (function (w, d, s, l, i) {
                        w[l] = w[l] || []; w[l].push({
                            'gtm.start':
                                new Date().getTime(), event: 'gtm.js'
                        }); var f = d.getElementsByTagName(s)[0],
                            j = d.createElement(s), dl = l != 'dataLayer' ? '&l=' + l : ''; j.async = true; j.src =
                                'https://www.googletagmanager.com/gtm.js?id=' + i + dl; f.parentNode.insertBefore(j, f);
                    })(window, document, 'script', 'dataLayer', '${integrations.gtm_id}');
                `;
            document.head.appendChild(script);
        }

        if (integrations.ga_id) {
            console.log(`[YCookies] Injecting Google Analytics: ${integrations.ga_id} `);
            const loader = document.createElement('script');
            loader.async = true;
            loader.src = `https://www.googletagmanager.com/gtag/js?id=${integrations.ga_id}`;
            loader.setAttribute('data-ycookies-injected', 'true');
            document.head.appendChild(loader);

            const config = document.createElement('script');
            config.setAttribute('data-ycookies-injected', 'true');
            config.textContent = `
                window.dataLayer = window.dataLayer || [];
                function gtag(){dataLayer.push(arguments);}
                gtag('js', new Date());
                gtag('config', '${integrations.ga_id}');
            `;
            document.head.appendChild(config);
        }

        if (integrations.pixel_id) {
            console.log(`[YCookies] Injecting Meta Pixel: ${integrations.pixel_id}`);
            const script = document.createElement('script');
            script.setAttribute('data-ycookies-injected', 'true');
            script.textContent = `
                !function(f,b,e,v,n,t,s)
                {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
                n.callMethod.apply(n,arguments):n.queue.push(arguments)};
                if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
                n.queue=[];t=b.createElement(e);t.async=!0;
                t.src=v;s=b.getElementsByTagName(e)[0];
                s.parentNode.insertBefore(t,s)}(window, document,'script',
                'https://connect.facebook.net/en_US/fbevents.js');
                fbq('init', '${integrations.pixel_id}');
                fbq('track', 'PageView');
            `;
            document.head.appendChild(script);
        }
    }

    /**
     * Monkey patches DOM insertion methods and standard networking APIs to pause scripts/iframes
     * BEFORE they can execute, ensuring GDPR compliance.
     */
    applyInterceptors() {
        console.log('[YCookies] Applying strict network interceptors...');

        // 1. Monkey Patch DOM insertions to catch dynamically added trackers
        const originalAppendChild = Element.prototype.appendChild;
        const originalInsertBefore = Element.prototype.insertBefore;
        const self = this;

        function interceptNode(node) {
            if (node.nodeType === Node.ELEMENT_NODE) {
                // Bypass interceptor for scripts we intentionally inject after consent
                if (node.hasAttribute('data-ycookies-injected')) return true;

                if (node.tagName === 'IFRAME') return self.handleIframeInterceptor(node);
                if (node.tagName === 'SCRIPT') return self.handleScriptInterceptor(node);
                if (node.tagName === 'LINK') return self.handleStyleInterceptor(node);
            }
            return true; // Allow insertion
        }

        Element.prototype.appendChild = function (node) {
            if (interceptNode(node)) {
                return originalAppendChild.call(this, node);
            }
            return node; // Blocked, but we return the node so the caller script doesn't crash
        };

        Element.prototype.insertBefore = function (node, referenceNode) {
            if (interceptNode(node)) {
                return originalInsertBefore.call(this, node, referenceNode);
            }
            return node;
        };

        // 2. Scan and intercept statically rendered iframes and scripts already present in HTML
        this.scanAndApplyStaticInterceptors();

        // 3. Map PostMessage listener for Iframe Blockers
        window.addEventListener('message', (event) => {
            if (typeof event.data === 'string' && event.data.startsWith('ycookies_accept_')) {
                const blockerKey = event.data.replace('ycookies_accept_', '');
                this.saveConsent({ [blockerKey]: true, marketing: true }); // Also grant marketing as a generic fallback to be safe
                this.scanAndApplyStaticInterceptors(); // Re-scan to unblock immediately without reload
            }
        });

        // 4. Monkey Patch navigator.sendBeacon to block analytical pings
        const originalSendBeacon = navigator.sendBeacon;
        navigator.sendBeacon = function (url, data) {
            if (self.isUrlBlocked(url)) {
                console.warn(`[YCookies] Blocked sendBeacon payload to ${url}`);
                self.reportDiscoveredResource(url, 'service');
                return true; // Pretend it succeeded to prevent errors
            }
            return originalSendBeacon.call(navigator, url, data);
        };

        // 5. Monkey Patch fetch for external service request blocking
        const originalFetch = window.fetch;
        if (typeof originalFetch === 'function') {
            window.fetch = function (input, init) {
                const url = typeof input === 'string' ? input : input?.url;
                if (self.isUrlBlocked(url)) {
                    console.warn(`[YCookies] Blocked fetch request to ${url}`);
                    self.reportDiscoveredResource(url, 'service');
                    return Promise.resolve(new Response('', { status: 204, statusText: 'No Content' }));
                }
                return originalFetch.call(window, input, init);
            };
        }

        // 6. Monkey Patch XMLHttpRequest for external service request blocking
        const originalXhrOpen = XMLHttpRequest.prototype.open;
        XMLHttpRequest.prototype.open = function (method, url, ...rest) {
            this.__ycookiesBlocked = self.isUrlBlocked(url);
            if (this.__ycookiesBlocked) self.reportDiscoveredResource(url, 'service');
            return originalXhrOpen.call(this, method, url, ...rest);
        };

        const originalXhrSend = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.send = function (body) {
            if (this.__ycookiesBlocked) {
                console.warn('[YCookies] Blocked XMLHttpRequest payload.');
                return;
            }
            return originalXhrSend.call(this, body);
        };
    }

    /**
     * Scans the existing DOM on load or consent update to block or unblock static elements
     */
    scanAndApplyStaticInterceptors() {
        // Handle Iframes (both src and data-ycookies-src)
        document.querySelectorAll('iframe').forEach(iframe => {
            this.handleIframeInterceptor(iframe);
        });

        // Handle stylesheets
        document.querySelectorAll('link[rel*="stylesheet"]').forEach(link => {
            this.handleStyleInterceptor(link);
        });

        // Handle Scripts (find plain text scripts marked for consent)
        document.querySelectorAll('script[type="text/plain"][data-category]').forEach(script => {
            const category = script.getAttribute('data-category');
            if (this.hasConsentForCookieGroup(category) || this.hasConsentForCookieGroup('marketing')) {
                // User consented, execute the script!
                const newScript = document.createElement('script');
                Array.from(script.attributes).forEach(attr => {
                    if (attr.name !== 'type' && attr.name !== 'data-category') {
                        newScript.setAttribute(attr.name, attr.value);
                    }
                });
                newScript.type = 'text/javascript';
                newScript.textContent = script.textContent;
                newScript.setAttribute('data-ycookies-injected', 'true');
                
                // Replace the plain text one with the executable one
                script.parentNode.replaceChild(newScript, script);
            }
        });

        // Handle Script Blockers specifically
        document.querySelectorAll('script[type="text/plain"][data-ycookies-script-blocker]').forEach(script => {
            const blockerKey = script.getAttribute('data-ycookies-script-blocker');
            // Check if the service this blocker is tied to is accepted
            const serviceId = script.getAttribute('data-ycookies-script-service');
            const requireGroup = script.getAttribute('data-ycookies-require-group');

            if (this.shouldAllowByServiceOrGroup(serviceId, requireGroup, 'marketing')) {
                // User consented, execute the script!
                const newScript = document.createElement('script');
                Array.from(script.attributes).forEach(attr => {
                    if (attr.name !== 'type' && attr.name !== 'data-ycookies-script-blocker' && attr.name !== 'data-ycookies-script-service' && attr.name !== 'data-ycookies-on-exist') {
                        newScript.setAttribute(attr.name, attr.value);
                    }
                });
                newScript.type = 'text/javascript';
                newScript.textContent = script.textContent;
                newScript.setAttribute('data-ycookies-injected', 'true');
                
                // Replace the plain text one with the executable one
                script.parentNode.replaceChild(newScript, script);
                
                // Execute on_exist code if available
                const onExistCode = script.getAttribute('data-ycookies-on-exist');
                if (onExistCode) {
                    try {
                        const onExistFunc = new Function(onExistCode);
                        onExistFunc();
                    } catch (e) {
                        console.error('[YCookies] Error executing on_exist code for Script Blocker:', blockerKey, e);
                    }
                }
            }
        });

        // Handle stylesheet blockers
        this.unblockServerBlockedStyles();
    }

    /**
     * Halts iframes matching the tenant's configuration rules 
     * and substitutes them with a visual unblocker overlay.
     */
    handleIframeInterceptor(iframe) {
        if (!this.config) return true;

        this.sanitizeIframeAllowAttribute(iframe);

        const src = iframe.src || iframe.getAttribute('data-ycookies-src');
        if (!src) return true;

        // "Accept once" and provider overrides should survive re-interception.
        if (this.isIframeTemporarilyAllowed(iframe)) {
            if (iframe.getAttribute('data-ycookies-src') && !iframe.src) {
                iframe.src = iframe.getAttribute('data-ycookies-src');
            }
            if (iframe.hasAttribute('srcdoc')) {
                iframe.removeAttribute('srcdoc');
            }
            return true;
        }

        const siteHost = (typeof window !== 'undefined' && window.location && window.location.hostname)
            ? window.location.hostname.replace(/^www\./i, '').toLowerCase()
            : '';

        const blockers = this.config.content_blockers || [];

        for (const blocker of blockers) {
            const isMatch = blocker.hosts.some(host => src.includes(host));
            if (isMatch) {
                const mayLoad = this.shouldAllowByServiceOrGroup(blocker.service || blocker.key, null, 'external_media')
                    || this.hasConsentForCookieGroup('marketing');
                if (!mayLoad) {
                    // BLOCK: Needs consent
                    if (!iframe.getAttribute('data-ycookies-src')) {
                         iframe.setAttribute('data-ycookies-src', src);
                    }
                    if (iframe.hasAttribute('src')) {
                         iframe.removeAttribute('src');
                    }
                    console.warn(`[YCookies] Blocked Content Iframe: ${src}`);

                    // Create the custom preview HTML
                    let htmlCode = blocker.html_code || `
                        <div style="text-align:center; padding: 20px;">
                            <p style="font-weight:bold;color:#f3f4f6;font-size:18px;">Content Blocked: {{name}}</p>
                            <p style="font-size:14px;color:#9ca3af;margin-bottom:15px">Please accept external media cookies to view this embedded content.</p>
                            <button onclick="window.parent.postMessage('ycookies_accept_${blocker.key}', '*')" style="background:#3b82f6;color:white;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-weight:600;">Load {{name}}</button>
                        </div>
                    `;
                    let cssCode = blocker.css_code || '';
                    
                    // Replace placeholders
                    htmlCode = htmlCode.replace(/{{name}}/g, blocker.name || '');
                    htmlCode = htmlCode.replace(/{{privacy_policy_url}}/g, blocker.privacy_policy_url || '#');
                    
                    if (blocker.text_placeholders) {
                        for (const [key, value] of Object.entries(blocker.text_placeholders)) {
                            htmlCode = htmlCode.replace(new RegExp(`{{${key}}}`, 'g'), value);
                        }
                    }
                    
                    // Allow triggering unblock from the HTML via the button class "yc-unblock-btn" (Borlabs style)
                    // If no such button class exists but we have the fallback, the fallback has onclick already.
                    // If custom HTML is provided we need to inject script to listen for unblock clicks.
                    const interactiveJs = `
                        <script>
                            document.addEventListener('click', function(e) {
                                if (e.target.closest('.yc-unblock-btn') || e.target.closest('a[href="#unblock"]')) {
                                    e.preventDefault();
                                    window.parent.postMessage('ycookies_accept_${blocker.key}', '*');
                                }
                            });
                        </script>
                    `;

                    iframe.srcdoc = `
                        <html>
                        <head>
                            <style>body{margin:0;padding:0;background:#1f2937;font-family:sans-serif;} ${cssCode}</style>
                        </head>
                        <body style="display:flex;align-items:center;justify-content:center;height:100vh;">
                            ${htmlCode}
                            ${interactiveJs}
                        </body>
                        </html>
                    `;
                    return true;
                } else {
                    // GRANTED: Restore src if it was blocked
                    if (iframe.hasAttribute('srcdoc')) {
                        iframe.removeAttribute('srcdoc');
                        
                        // Execute JS code if any when unblocked
                        if (blocker.js_code && !window['ycookies_js_executed_' + blocker.key]) {
                            try {
                                const jsFunc = new Function(blocker.js_code);
                                jsFunc();
                            } catch(e) {
                                console.error('[YCookies] Error executing js_code for Content Blocker:', blocker.key, e);
                            }
                            window['ycookies_js_executed_' + blocker.key] = true;
                        }
                    }
                    if (iframe.getAttribute('data-ycookies-src') && !iframe.src) {
                        iframe.src = iframe.getAttribute('data-ycookies-src');
                    }
                }
            }
        }

        // Universal fallback: third-party iframe not covered by a configured content blocker
        if (this.isAutoBlockingEnabled('content') && siteHost && this.isThirdPartyEmbedUrl(src, siteHost)) {
            const groupOk = this.hasConsentForCookieGroup('external_media') || this.hasConsentForCookieGroup('marketing');
            if (!groupOk) {
                if (!iframe.getAttribute('data-ycookies-src')) {
                    iframe.setAttribute('data-ycookies-src', src);
                }
                if (iframe.hasAttribute('src')) {
                    iframe.removeAttribute('src');
                }
                const label = this.embedProviderLabelFromSrc(src);
                iframe.srcdoc = `
                        <html>
                        <head>
                            <style>body{margin:0;padding:0;background:#1f2937;font-family:sans-serif;}</style>
                        </head>
                        <body style="display:flex;align-items:center;justify-content:center;height:100vh;">
                            <div style="text-align:center; padding: 20px;">
                                <p style="font-weight:bold;color:#f3f4f6;font-size:18px;">External content</p>
                                <p style="font-size:14px;color:#9ca3af;margin-bottom:15px">${label}</p>
                                <button type="button" onclick="window.parent.postMessage('ycookies_accept_external_media', '*')" style="background:#3b82f6;color:white;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-weight:600;">Accept external media</button>
                            </div>
                        </body>
                        </html>
                    `;
                console.warn('[YCookies] Blocked external iframe (universal):', src);
                this.reportDiscoveredResource(src, 'content');
                return true;
            }
            if (iframe.getAttribute('data-ycookies-src') && !iframe.src) {
                iframe.src = iframe.getAttribute('data-ycookies-src');
            }
            if (iframe.hasAttribute('srcdoc')) {
                iframe.removeAttribute('srcdoc');
            }
        }

        return true;
    }

    isIframeTemporarilyAllowed(iframe) {
        if (!iframe) return false;

        const instanceId = iframe.getAttribute('data-ycookies-instance-id');
        if (instanceId && this._instanceUnlocks?.has(instanceId)) {
            return true;
        }

        const providerKey = iframe.getAttribute('data-ycookies-provider');
        if (providerKey && this._providerOverrides?.has(providerKey)) {
            return true;
        }

        return false;
    }

    handleStyleInterceptor(link) {
        if (!link || link.tagName !== 'LINK') return true;
        const rel = (link.getAttribute('rel') || '').toLowerCase();
        if (!rel.includes('stylesheet')) return true;

        const href = link.getAttribute('href') || link.getAttribute('data-ycookies-style-href');
        if (!href) return true;

        const siteHost = (typeof window !== 'undefined' && window.location && window.location.hostname)
            ? window.location.hostname.replace(/^www\./i, '').toLowerCase()
            : '';

        const styleBlockers = this.config?.style_blockers || [];
        for (const blocker of styleBlockers) {
            const handles = blocker.handles || [];
            const phrases = blocker.phrases || [];
            const attrsText = link.outerHTML || '';
            const matchedByHandle = handles.some(handle => href.includes(handle) || attrsText.includes(handle));
            const matchedByPhrase = phrases.some(phrase => attrsText.includes(phrase));
            if (!matchedByHandle && !matchedByPhrase) continue;

            const serviceKey = blocker.service || '';
            const mayLoad = this.shouldAllowByServiceOrGroup(serviceKey, null, 'marketing');
            if (!mayLoad) {
                if (!link.getAttribute('data-ycookies-style-href')) {
                    link.setAttribute('data-ycookies-style-href', href);
                }
                link.removeAttribute('href');
                link.setAttribute('data-ycookies-style-blocked', 'true');
                link.setAttribute('data-ycookies-blocker-id', blocker.key || '');
                link.setAttribute('data-ycookies-service', serviceKey);
            } else if (link.getAttribute('data-ycookies-style-href') && !link.getAttribute('href')) {
                link.setAttribute('href', link.getAttribute('data-ycookies-style-href'));
                link.removeAttribute('data-ycookies-style-blocked');
                link.removeAttribute('data-ycookies-style-href');
            }
            return true;
        }

        if (this.isAutoBlockingEnabled('style') && siteHost && this.isThirdPartyEmbedUrl(href, siteHost)) {
            const mayLoad = this.hasExternalRuntimeConsent();
            if (!mayLoad) {
                if (!link.getAttribute('data-ycookies-style-href')) {
                    link.setAttribute('data-ycookies-style-href', href);
                }
                link.removeAttribute('href');
                link.setAttribute('data-ycookies-style-blocked', 'true');
                link.setAttribute('data-ycookies-require-group', 'marketing');
                link.setAttribute('data-ycookies-service', '');
                link.setAttribute('data-ycookies-provider', this.embedProviderLabelFromSrc(href).replace(/^Content from /, ''));
            } else if (link.getAttribute('data-ycookies-style-href') && !link.getAttribute('href')) {
                link.setAttribute('href', link.getAttribute('data-ycookies-style-href'));
                link.removeAttribute('data-ycookies-style-blocked');
                link.removeAttribute('data-ycookies-style-href');
                link.removeAttribute('data-ycookies-require-group');
                link.removeAttribute('data-ycookies-provider');
            }
        }

        return true;
    }

    isThirdPartyEmbedUrl(src, siteHost) {
        try {
            let u = src;
            if (u.startsWith('//')) u = 'https:' + u;
            const url = new URL(u);
            const sch = url.protocol.replace(':', '').toLowerCase();
            if (['data', 'javascript', 'about', 'blob'].includes(sch)) return false;
            const h = url.hostname.replace(/^www\./i, '').toLowerCase();
            const site = (siteHost || '').replace(/^www\./i, '').toLowerCase();
            if (!h || h === site || h.endsWith('.' + site)) return false;
            if (src.toLowerCase().includes('ycookies')) return false;
            return true;
        } catch (e) {
            return false;
        }
    }

    embedProviderLabelFromSrc(src) {
        try {
            let u = src.startsWith('//') ? 'https:' + src : src;
            const host = new URL(u).hostname.replace(/^www\./i, '').toLowerCase();
            const parts = host.split('.');
            const name = parts.length >= 2 ? parts.slice(-2).join('.') : host;

            return 'Content from ' + name;
        } catch (e) {
            return 'Please accept external media to load this embed.';
        }
    }

    /**
     * Prevents dynamically injected scripts from executing by casting their type
     */
    handleScriptInterceptor(script) {
        // Handle generic categorical blocking (Phase 2 legacy)
        if (script.hasAttribute('data-category')) {
            const category = script.getAttribute('data-category');
            if (!this.consentState[category] && !this.consentState.marketing) {
                script.type = 'text/plain'; // Cast to plain text
                console.warn(`[YCookies] Intercepted dynamically injected ${category} script.`);
                return true;
            }
        }

        // Handle Advanced Script Blockers (Phase 4 matching)
        if (this.config && this.config.script_blockers) {
            const src = script.src;
            const content = script.innerHTML || script.textContent;

            for (const blocker of this.config.script_blockers) {
                let matched = false;

                // Match handles (src URL parts)
                if (src && blocker.handles && blocker.handles.length > 0) {
                    if (blocker.handles.some(handle => src.includes(handle))) {
                        matched = true;
                    }
                }

                // Match phrases (inline JS content)
                if (!matched && content && blocker.phrases && blocker.phrases.length > 0) {
                    if (blocker.phrases.some(phrase => content.includes(phrase))) {
                        matched = true;
                    }
                }

                if (matched) {
                    const serviceKey = blocker.service || blocker.service_id || '';
                    const mayLoad = this.shouldAllowByServiceOrGroup(serviceKey, null, 'marketing');

                    if (!mayLoad) {
                        script.type = 'text/plain'; // Cast to plain text
                        script.setAttribute('data-ycookies-script-blocker', blocker.key);
                        script.setAttribute('data-ycookies-script-service', serviceKey);
                        if (blocker.on_exist) {
                            script.setAttribute('data-ycookies-on-exist', blocker.on_exist);
                        }
                        
                        console.warn(`[YCookies] Intercepted Script Blocker match for: ${blocker.name}`);
                        return true;
                    } else {
                         // We are consented, but if this is the first time running it and on_exist is defined, execute it
                         if (blocker.on_exist && !window['ycookies_script_on_exist_executed_' + blocker.key]) {
                             try {
                                 const onExistFunc = new Function(blocker.on_exist);
                                 onExistFunc();
                             } catch(e) {
                                 console.error('[YCookies] Error executing on_exist code for Script Blocker:', blocker.key, e);
                             }
                             window['ycookies_script_on_exist_executed_' + blocker.key] = true;
                         }
                    }
                }
            }
        }

        // Universal script auto-blocking for unknown third-party scripts
        if (this.isAutoBlockingEnabled('script')) {
            const siteHost = (typeof window !== 'undefined' && window.location && window.location.hostname)
                ? window.location.hostname.replace(/^www\./i, '').toLowerCase()
                : '';
            const src = script.getAttribute('src') || script.src;
            if (src && siteHost && this.isThirdPartyEmbedUrl(src, siteHost) && !this.hasExternalRuntimeConsent()) {
                script.type = 'text/plain';
                script.setAttribute('data-ycookies-script-blocker', 'universal-external-script');
                script.setAttribute('data-ycookies-script-service', '');
                script.setAttribute('data-ycookies-require-group', 'marketing');
                console.warn(`[YCookies] Intercepted universal external script: ${src}`);
            }
        }

        return true;
    }

    /**
     * API origin/base for this embed (data-ycookies-api or derived from script src).
     * Used for consent logging, TCF, RUM — must bypass strict third-party blocking.
     */
    getCmpApiBase() {
        let apiBase = '';
        if (this.scriptTag) {
            apiBase = this.scriptTag.getAttribute('data-ycookies-api')
                || this.scriptTag.getAttribute('data-ycookies-base')
                || '';
        }
        if (!apiBase && this.scriptTag) {
            const src = this.scriptTag.getAttribute('src') || '';
            if (src.includes('/build/')) {
                apiBase = src.split('/build/')[0];
            } else if (src.includes('/api/')) {
                apiBase = src.split('/api/')[0];
            } else {
                try {
                    const u = new URL(src, typeof window !== 'undefined' ? window.location.href : undefined);
                    apiBase = u.origin;
                } catch {
                    apiBase = '';
                }
            }
        }
        return apiBase;
    }

    /**
     * First-party CMP traffic to our backend (cross-origin from the site) must not be
     * treated as blocked marketing/analytics beacons.
     */
    isTrustedCmpBackendRequestUrl(url) {
        const base = this.getCmpApiBase();
        if (!base || !url) return false;
        let parsed;
        let baseParsed;
        try {
            parsed = new URL(String(url), typeof window !== 'undefined' ? window.location.href : undefined);
            baseParsed = new URL(base, typeof window !== 'undefined' ? window.location.href : undefined);
        } catch {
            return false;
        }
        if (parsed.origin !== baseParsed.origin) return false;
        const path = (parsed.pathname || '').replace(/\/+$/, '') || '/';
        return path === '/api/rum/beacon'
            || path === '/api/log-consent'
            || path === '/api/discovery/beacon'
            || path.startsWith('/api/tcf/');
    }

    /**
     * Chromium logs "Unrecognized feature: 'web-share'" for iframe allow="... web-share"
     * on many versions; strip it so embeds stay functional without console noise.
     */
    sanitizeIframeAllowAttribute(iframe) {
        if (!iframe || typeof iframe.getAttribute !== 'function') return;
        const allow = iframe.getAttribute('allow');
        if (!allow || !/\bweb-share\b/i.test(allow)) return;
        const parts = allow.split(/[;,]/).map((s) => s.trim()).filter(Boolean);
        const filtered = parts.filter((f) => !/^web-share$/i.test(f));
        if (filtered.length) {
            iframe.setAttribute('allow', filtered.join('; '));
        } else {
            iframe.removeAttribute('allow');
        }
    }

    /**
     * Extract registrable domain from URL — must match providerFromUrl() in html-blocker.js exactly.
     */
    _providerFromUrl(src) {
        try {
            const url = new URL(String(src).startsWith('//') ? 'https:' + src : String(src));
            const host = url.hostname.replace(/^www\./i, '').toLowerCase();
            const parts = host.split('.');
            return parts.length > 2 ? parts.slice(-2).join('.') : host;
        } catch { return null; }
    }

    /**
     * Buffer a blocked resource URL for batched discovery reporting.
     */
    reportDiscoveredResource(url, type) {
        if (!url || !this.siteId) return;
        if (!this.isAutoBlockingEnabled('service') && !this.isAutoBlockingEnabled('script') && !this.isAutoBlockingEnabled('style') && !this.isAutoBlockingEnabled('content')) return;
        const urlStr = String(url).substring(0, 2000);
        if (this._discoveryBuffer.some(r => r.url === urlStr)) return;
        this._discoveryBuffer.push({ url: urlStr, type });
        clearTimeout(this._discoveryFlushTimer);
        if (this._discoveryBuffer.length >= 20) {
            this.flushDiscoveryBuffer();
        } else {
            this._discoveryFlushTimer = setTimeout(() => this.flushDiscoveryBuffer(), 2000);
        }
    }

    /**
     * Send buffered discovered resources to the server via beacon.
     */
    flushDiscoveryBuffer() {
        if (this._discoveryBuffer.length === 0) return;
        const payload = JSON.stringify({
            site_id: this.siteId,
            resources: this._discoveryBuffer.splice(0),
        });
        
        let apiBase = this.scriptTag ? this.scriptTag.getAttribute('data-ycookies-api') : null;
        if (!apiBase) {
            apiBase = this.config?._api_base || this.config?.bootstrapper?.api_base || '';
        }
        // Normalize trailing slash
        if (apiBase && apiBase.endsWith('/')) {
            apiBase = apiBase.slice(0, -1);
        }

        const url = `${apiBase}/api/discovery/beacon`;
        try {
            if (window.fetch) {
                fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'text/plain', 'Accept': 'application/json' },
                    body: payload,
                    keepalive: true,
                    credentials: 'omit'
                });
            } else if (navigator.sendBeacon) {
                navigator.sendBeacon(url, new Blob([payload], { type: 'text/plain' }));
            }
        } catch { /* fire-and-forget */ }
    }

    /**
     * Scan for proxy-injected content blocker placeholders and report them
     * as discovered resources so they appear in the Visitor Discovery section.
     * @private
     */
    _reportProxyBlockedContent() {
        try {
            const placeholders = document.querySelectorAll('.ycookies-embed-placeholder[data-ycookies-original]');
            placeholders.forEach(el => {
                const encoded = el.getAttribute('data-ycookies-original');
                if (!encoded) return;
                // Decode the base64-encoded original iframe tag to extract src
                try {
                    const originalTag = atob(encoded);
                    const srcMatch = originalTag.match(/src=["']([^"']+)["']/i);
                    if (srcMatch && srcMatch[1]) {
                        this.reportDiscoveredResource(srcMatch[1], 'content');
                    }
                } catch { /* ignore decode failures */ }
            });
        } catch { /* ignore if DOM not ready */ }
    }

    /**
     * Scan for ALL proxy-blocked scripts and styles on page load and report them
     * to Visitor Discovery. This runs on every page load regardless of consent state,
     * so first-time visitors still trigger discovery.
     * @private
     */
    _reportProxyBlockedScriptsAndStyles() {
        try {
            // Proxy-blocked scripts (type="text/template" with data-ycookies-blocked)
            document.querySelectorAll('script[data-ycookies-blocked="true"]').forEach(script => {
                const src = script.src || script.getAttribute('src') || '';
                if (src) {
                    this.reportDiscoveredResource(src, 'script');
                }
            });

            // Proxy-blocked stylesheets
            document.querySelectorAll('link[data-ycookies-style-blocked="true"]').forEach(link => {
                const href = link.getAttribute('data-ycookies-style-href') || '';
                if (href) {
                    this.reportDiscoveredResource(href, 'style');
                }
            });
        } catch { /* ignore */ }
    }

    isUrlBlocked(url) {
        if (!this.isAutoBlockingEnabled('service')) return false;
        if (!url) return false;
        if (this.isTrustedCmpBackendRequestUrl(url)) return false;

        const siteHost = (typeof window !== 'undefined' && window.location && window.location.hostname)
            ? window.location.hostname.replace(/^www\./i, '').toLowerCase()
            : '';

        if (!siteHost || !this.isThirdPartyEmbedUrl(String(url), siteHost)) {
            return false;
        }

        if (this.hasExternalRuntimeConsent()) {
            return false;
        }

        // Per-provider discovered resource consent
        const provider = this._providerFromUrl(url);
        if (provider) {
            const discKey = 'disc-' + provider.replace(/\./g, '-');
            if (this.consentState[discKey]) return false;
        }

        return true;
    }

    /**
     * Initializes the IAB TCF v2.2 stub and listens for ad-vendor checks.
     */
    initTCF() {
        if (!this.config.tcf_config?.enabled) return;

        console.log('[YCookies] Initializing IAB TCF v2.2 Stub...');

        // Universal IAB Stub
        window.__tcfapi = window.__tcfapi || function () {
            const queue = [];
            queue.push(arguments);
            window.__tcfapiCmdQueue = window.__tcfapiCmdQueue || [];
            window.__tcfapiCmdQueue.push(queue);
        };

        // If we already have consent locally, we'd normally decode the TC String.
        // For this implementation, we will simulate the tcloaded event based on our DB overriding.
        window.__tcfapi('addEventListener', 2, (tcData, success) => {
            if (success && tcData && (tcData.eventStatus === 'tcloaded' || tcData.eventStatus === 'useractioncomplete')) {
                this.applyTCFConsent(tcData.tcString);
            }
        });
    }

    /**
     * Maps the TC String's granted purposes over to our internal consentState,
     * ensuring that programmatic networks respect the same toggles as Google Tags.
     */
    applyTCFConsent(tcString) {
        console.log(`[YCookies] Processing TCF String: ${tcString}`);

        // This is a naive simulation for Phase 6 since full decoding requires an IAB GVL parser.
        // In a true production environment, we decode the base64 string and map IAB Purpose IDs.
        // For demonstration, we assume TCF granted signals activate both statistics and marketing.
        if (tcString) {
            this.consentState.statistics = true;
            this.consentState.marketing = true;

            // Sync back to Google tags so AdSense behaves
            this.updateGoogleConsentMode();

            // Optionally trigger UI redraws or reinject queued tags
            this.injectConsentedServices();
        }
    }

    // ══════════════════════════════════════════════════════════════
    // Embed Placeholder Consent Runtime (Deliverable 3)
    // ══════════════════════════════════════════════════════════════

    /**
     * Initialize embed placeholder handlers.
     * Binds click events on "Load this content" and "Always allow [Provider]" buttons
     * that the proxy injected via buildContentPlaceholder().
     * Also sets up MutationObserver for dynamically injected iframes.
     */
    initEmbedPlaceholders() {
        // Session-scoped instance unlocks (not persisted)
        this._instanceUnlocks = this._instanceUnlocks || new Set();
        // Provider overrides: loaded from consent state or initialized empty
        this._providerOverrides = this._providerOverrides || new Set();

        // Load saved provider overrides from consent cookie
        this.loadProviderOverrides();

        // Capture-phase delegation: survives re-renders / frameworks that clone nodes
        this._embedClickDelegation = (e) => {
            const once = e.target && e.target.closest && e.target.closest('[data-action="accept-once"]');
            const prov = e.target && e.target.closest && e.target.closest('[data-action="accept-provider"]');
            const root = (once || prov) && e.target.closest('.ycookies-embed-placeholder');
            if (!root) return;
            e.preventDefault();
            e.stopPropagation();
            if (once) {
                const instanceId = root.getAttribute('data-ycookies-instance-id')
                    || once.getAttribute('data-instance-id');
                if (instanceId) {
                    this._instanceUnlocks.add(instanceId);
                }
                this.restoreEmbed(root);
                console.log(`[YCookies] Instance unlock (delegated): ${instanceId || '(no id)'}`);
            } else if (prov) {
                const providerKey = prov.getAttribute('data-provider-key');
                if (providerKey) {
                    this.acceptEmbedProvider(providerKey);
                }
            }
        };
        document.addEventListener('click', this._embedClickDelegation, true);

        // Bind existing placeholders
        this.bindEmbedPlaceholderButtons();

        // Report proxy-blocked iframes to Visitor Discovery
        this._reportProxyBlockedContent();

        // Auto-restore embeds where category or provider is already consented
        this.autoRestoreEmbeds();

        // MutationObserver for dynamically injected placeholders
        this._embedObserver = new MutationObserver((mutations) => {
            let hasNewPlaceholders = false;
            for (const mutation of mutations) {
                for (const node of mutation.addedNodes) {
                    if (node.nodeType === 1) {
                        if (node.classList?.contains('ycookies-embed-placeholder') ||
                            node.querySelector?.('.ycookies-embed-placeholder')) {
                            hasNewPlaceholders = true;
                            break;
                        }
                    }
                }
                if (hasNewPlaceholders) break;
            }
            if (hasNewPlaceholders) {
                this.bindEmbedPlaceholderButtons();
                this.autoRestoreEmbeds();
            }
        });

        if (document.body) {
            this._embedObserver.observe(document.body, { childList: true, subtree: true });
        } else {
            document.addEventListener('DOMContentLoaded', () => {
                this._embedObserver.observe(document.body, { childList: true, subtree: true });
            });
        }

        this._embedPlaceholdersReady = true;

        console.log('[YCookies] Embed placeholder runtime initialized.');
    }

    /**
     * Renders floating consent widgets for content blockers with display_mode === 'floating'.
     * These are used when a script blocker blocks a widget script (e.g. live chat) and
     * a matching content blocker provides a floating placeholder UI.
     */
    initFloatingBlockers() {
        if (!this.config) return;
        const blockers = this.config.content_blockers || [];
        const floatingBlockers = blockers.filter(b => b.display_mode === 'floating');
        if (floatingBlockers.length === 0) return;

        this._floatingContainers = this._floatingContainers || [];

        floatingBlockers.forEach(blocker => {
            const serviceKey = blocker.service || blocker.key;
            const providerKey = blocker.provider_key || serviceKey;

            // Skip if already consented
            if (this.shouldAllowByServiceOrGroup(serviceKey, null, 'marketing')
                || this.shouldAllowByServiceOrGroup(serviceKey, null, 'external_media')
                || this._providerOverrides?.has(providerKey)) {
                return;
            }

            // Check if there's actually a blocked script for this service
            const hasBlockedScript = document.querySelector(
                `script[data-ycookies-blocked="true"][data-ycookies-service="${serviceKey}"]`
            );
            // Also render if hosts are defined (for iframe-based floating blockers)
            if (!hasBlockedScript && (!blocker.hosts || blocker.hosts.length === 0)) {
                return;
            }

            this._renderFloatingWidget(blocker, serviceKey, providerKey);
        });

        if (this._floatingContainers.length > 0) {
            console.log(`[YCookies] Rendered ${this._floatingContainers.length} floating blocker widget(s).`);
        }
    }

    /** @private */
    _renderFloatingWidget(blocker, serviceKey, providerKey) {
        const position = blocker.floating_position || 'bottom-right';
        const name = blocker.name || blocker.key || 'External Service';
        const label = blocker.floating_label || name;
        const privacyUrl = blocker.privacy_policy_url || '#';
        const iconUrl = blocker.floating_icon_url || null;

        // Build the container
        const container = document.createElement('div');
        container.className = 'ycookies-floating-blocker';
        container.setAttribute('data-ycookies-floating-service', serviceKey);
        container.setAttribute('data-ycookies-floating-provider', providerKey);

        // Position styles
        const posStyle = position === 'bottom-left'
            ? 'left:20px;right:auto;'
            : 'right:20px;left:auto;';
        container.setAttribute('style',
            `position:fixed;bottom:20px;${posStyle}z-index:2147483646;font-family:system-ui,-apple-system,sans-serif;`
        );

        // Get HTML + CSS from the blocker config, or use default
        let htmlCode = blocker.html_code || this._defaultFloatingHtml(iconUrl);
        let cssCode = blocker.css_code || this._defaultFloatingCss(position);

        // Replace template placeholders
        htmlCode = htmlCode.replace(/\{\{name\}\}/g, name);
        htmlCode = htmlCode.replace(/\{\{privacy_policy_url\}\}/g, privacyUrl);
        htmlCode = htmlCode.replace(/\{\{label\}\}/g, label);

        // Inject scoped CSS
        const styleEl = document.createElement('style');
        styleEl.textContent = cssCode;
        container.appendChild(styleEl);

        // Inject HTML
        const wrapper = document.createElement('div');
        wrapper.innerHTML = htmlCode;
        while (wrapper.firstChild) {
            container.appendChild(wrapper.firstChild);
        }

        // Wire up toggle buttons (data-yc-float-toggle)
        container.querySelectorAll('[data-yc-float-toggle]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const popup = container.querySelector('.yc-float-popup');
                if (popup) popup.classList.toggle('yc-float-hidden');
            });
        });

        // Wire up unblock buttons (.yc-unblock-btn)
        container.querySelectorAll('.yc-unblock-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this._acceptFloatingBlocker(serviceKey, providerKey, container);
            });
        });

        document.body.appendChild(container);
        this._floatingContainers.push(container);
    }

    /** @private */
    _acceptFloatingBlocker(serviceKey, providerKey, container) {
        // Use the provider override system for persistence
        this._providerOverrides.add(providerKey);
        this.saveProviderOverrides();

        // Unblock matching scripts
        document.querySelectorAll(
            `script[data-ycookies-blocked="true"][data-ycookies-service="${serviceKey}"]`
        ).forEach(script => {
            const newScript = document.createElement('script');
            Array.from(script.attributes).forEach(attr => {
                if (!attr.name.startsWith('data-ycookies')) newScript.setAttribute(attr.name, attr.value);
            });
            newScript.removeAttribute('type');
            if (script.src) newScript.src = script.src;
            newScript.textContent = script.textContent;
            newScript.setAttribute('data-ycookies-injected', 'true');
            script.parentNode.replaceChild(newScript, script);
        });

        // Restore any matching embed placeholders
        this.restoreProviderEmbeds(providerKey);

        // Remove the floating widget
        if (container && container.parentNode) {
            container.parentNode.removeChild(container);
        }
        this._floatingContainers = this._floatingContainers.filter(c => c !== container);

        this.updateGoogleConsentMode();
        console.log(`[YCookies] Floating blocker accepted: "${providerKey}"`);
    }

    /** @private — default floating trigger + popup when no html_code is configured */
    _defaultFloatingHtml(iconUrl) {
        const iconContent = iconUrl
            ? `<img src="${iconUrl}" alt="" style="width:24px;height:24px;object-fit:contain;">`
            : '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';

        return `<div class="yc-float-trigger" data-yc-float-toggle title="{{label}}">${iconContent}</div>
<div class="yc-float-popup yc-float-hidden">
    <div class="yc-float-popup-header">
        <span class="yc-float-popup-title">{{name}}</span>
        <button class="yc-float-popup-close" data-yc-float-toggle>&times;</button>
    </div>
    <div class="yc-float-popup-body">
        <p>This feature uses <strong>{{name}}</strong> and may share data with the provider.</p>
    </div>
    <div class="yc-float-popup-actions">
        <button class="yc-unblock-btn yc-float-btn-allow">Allow {{name}}</button>
    </div>
</div>`;
    }

    /** @private — default CSS for floating widget */
    _defaultFloatingCss(position) {
        const popupAlign = position === 'bottom-left' ? 'left:0' : 'right:0';
        return `.yc-float-trigger{width:56px;height:56px;border-radius:50%;background:#3b82f6;color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,.25);transition:transform .2s,background .2s}.yc-float-trigger:hover{transform:scale(1.08);background:#2563eb}.yc-float-popup{position:absolute;bottom:68px;${popupAlign};width:320px;background:#fff;border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.18);overflow:hidden;font-family:system-ui,-apple-system,sans-serif;animation:ycFloatIn .2s ease}.yc-float-hidden{display:none!important}.yc-float-popup-header{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0}.yc-float-popup-title{font-weight:600;font-size:15px;color:#1e293b}.yc-float-popup-close{background:none;border:none;font-size:20px;cursor:pointer;color:#64748b;padding:0 4px}.yc-float-popup-close:hover{color:#1e293b}.yc-float-popup-body{padding:16px;font-size:14px;color:#475569;line-height:1.5}.yc-float-popup-body p{margin:0 0 8px}.yc-float-popup-actions{padding:12px 16px;background:#f8fafc;border-top:1px solid #e2e8f0}.yc-float-btn-allow{width:100%;padding:10px;background:#3b82f6;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;transition:background .2s}.yc-float-btn-allow:hover{background:#2563eb}@keyframes ycFloatIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}`;
    }

    /**
     * Bind click handlers on all unbound embed placeholder buttons.
     */
    bindEmbedPlaceholderButtons() {
        const placeholders = document.querySelectorAll('.ycookies-embed-placeholder:not([data-yc-bound])');

        placeholders.forEach(placeholder => {
            placeholder.setAttribute('data-yc-bound', 'true');

            // "Load this content" button
            const onceBtn = placeholder.querySelector('[data-action="accept-once"]');
            if (onceBtn) {
                onceBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    const instanceId = placeholder.getAttribute('data-ycookies-instance-id');
                    this.acceptEmbedInstance(instanceId, placeholder);
                });
            }

            // "Always allow [Provider]" button
            const providerBtn = placeholder.querySelector('[data-action="accept-provider"]');
            if (providerBtn) {
                providerBtn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    const providerKey = providerBtn.getAttribute('data-provider-key');
                    this.acceptEmbedProvider(providerKey);
                });
            }
        });
    }

    /**
     * Accept a single embed instance (session-scoped, not persisted).
     * Restores the original iframe and removes the placeholder.
     */
    acceptEmbedInstance(instanceId, placeholder) {
        if (instanceId) {
            this._instanceUnlocks.add(instanceId);
        }
        this.restoreEmbed(placeholder);
        console.log(`[YCookies] Instance unlock: ${instanceId || '(no id)'}`);
    }

    /**
     * Accept all embeds from a provider (persisted in consent record).
     * Restores all embeds matching the provider key on the page.
     */
    acceptEmbedProvider(providerKey) {
        this._providerOverrides.add(providerKey);

        // Persist provider overrides through the consent system
        this.saveProviderOverrides();

        // Restore all embeds from this provider
        this.restoreProviderEmbeds(providerKey);

        // Update Google Consent Mode if this provider has consent_mode_mapping
        this.updateGoogleConsentMode();

        console.log(`[YCookies] Provider override: always allow "${providerKey}"`);
    }

    /**
     * Restore a single embed placeholder back to its original iframe.
     */
    restoreEmbed(placeholder) {
        const encoded = placeholder.getAttribute('data-ycookies-original');
        if (!encoded) return;

        try {
            const originalHtml = atob(encoded);
            const container = document.createElement('div');
            container.innerHTML = originalHtml;

            const instanceId = placeholder.getAttribute('data-ycookies-instance-id');
            const providerKey = placeholder.getAttribute('data-ycookies-provider');
            const serviceKey = placeholder.getAttribute('data-ycookies-service');
            const requireGroup = placeholder.getAttribute('data-ycookies-require-group');
            const attachRuntimeAttrs = (node) => {
                if (!node || node.nodeType !== 1) return;
                if (instanceId) node.setAttribute('data-ycookies-instance-id', instanceId);
                if (providerKey) node.setAttribute('data-ycookies-provider', providerKey);
                if (serviceKey) node.setAttribute('data-ycookies-service', serviceKey);
                if (requireGroup) node.setAttribute('data-ycookies-require-group', requireGroup);
                node.setAttribute('data-ycookies-restored', 'true');
            };
            
            // Replace placeholder with restored content
            const restoredElement = container.firstElementChild || container.firstChild;
            if (restoredElement && placeholder.parentNode) {
                if (restoredElement.tagName === 'IFRAME') {
                    attachRuntimeAttrs(restoredElement);
                } else if (typeof restoredElement.querySelectorAll === 'function') {
                    restoredElement.querySelectorAll('iframe').forEach(attachRuntimeAttrs);
                }
                placeholder.parentNode.replaceChild(restoredElement, placeholder);
                const finalizeIframe = (iframe) => {
                    if (iframe && iframe.tagName === 'IFRAME') {
                        this.handleIframeInterceptor(iframe);
                    }
                };
                if (restoredElement.tagName === 'IFRAME') {
                    finalizeIframe(restoredElement);
                } else if (typeof restoredElement.querySelectorAll === 'function') {
                    restoredElement.querySelectorAll('iframe').forEach(finalizeIframe, this);
                }
            }
        } catch (e) {
            console.error('[YCookies] Failed to restore embed:', e);
        }
    }

    /**
     * Restore all embeds from a specific provider.
     */
    restoreProviderEmbeds(providerKey) {
        const placeholders = document.querySelectorAll(
            `.ycookies-embed-placeholder[data-ycookies-provider="${providerKey}"]`
        );
        placeholders.forEach(p => this.restoreEmbed(p));
    }

    /**
     * Auto-restore embeds where the parent category or provider is already consented.
     * Called on init and after saveConsent.
     */
    autoRestoreEmbeds() {
        const placeholders = document.querySelectorAll('.ycookies-embed-placeholder');
        if (placeholders.length === 0) return;

        placeholders.forEach(placeholder => {
            const serviceKey = placeholder.getAttribute('data-ycookies-service');
            const providerKey = placeholder.getAttribute('data-ycookies-provider');
            const instanceId = placeholder.getAttribute('data-ycookies-instance-id');
            const requireGroup = placeholder.getAttribute('data-ycookies-require-group');

            // 1. Instance unlock (session-scoped)
            if (instanceId && this._instanceUnlocks?.has(instanceId)) {
                this.restoreEmbed(placeholder);
                return;
            }

            // 2. Provider override (persisted)
            if (providerKey && this._providerOverrides?.has(providerKey)) {
                this.restoreEmbed(placeholder);
                return;
            }

            // 3. Explicit require_group on placeholder (e.g. external_media for universal embeds)
            if (requireGroup && this.hasConsentForCookieGroup(requireGroup)) {
                this.restoreEmbed(placeholder);
                return;
            }

            // 4. Category consent — find which group owns this service
            if (serviceKey && this.config?.cookie_groups) {
                for (const group of this.config.cookie_groups) {
                    if (!this.consentState[group.key]) continue;
                    const services = group.services || [];
                    for (const service of services) {
                        if (service.key === serviceKey || service.provider_key === providerKey) {
                            this.restoreEmbed(placeholder);
                            return;
                        }
                    }
                }
            }
        });
    }

    /**
     * Load provider overrides from the consent cookie.
     */
    loadProviderOverrides() {
        this._providerOverrides = new Set();
        try {
            const match = document.cookie.match(/(^| )ycookies_providers=([^;]+)/);
            if (match) {
                const providers = JSON.parse(decodeURIComponent(match[2]));
                if (Array.isArray(providers)) {
                    providers.forEach(p => this._providerOverrides.add(p));
                }
            }
        } catch (e) {
            // Ignore parse errors
        }
    }

    /**
     * Persist provider overrides to a separate first-party cookie.
     * This flows through the consent audit trail via sendConsentBeacon.
     */
    saveProviderOverrides() {
        const providers = Array.from(this._providerOverrides);
        const cookieStr = encodeURIComponent(JSON.stringify(providers));
        const expires = new Date();
        expires.setTime(expires.getTime() + (365 * 24 * 60 * 60 * 1000));
        document.cookie = `ycookies_providers=${cookieStr};expires=${expires.toUTCString()};path=/;SameSite=Lax`;
    }

    dispatchHook(eventName, detailData) {
        const event = new CustomEvent(`ycookies:consent:${eventName}`, { detail: detailData });
        window.dispatchEvent(event);
    }
}
// Expose globally for inline scripts (e.g., Preview Iframe)
window.YCookiesManager = YCookiesManager;
export { YCookiesManager };

// Bootstrap
document.addEventListener('DOMContentLoaded', () => {
    // Preview mode is ONLY set explicitly by the preview-iframe.blade.php
    // Do NOT derive it from window.YCookies existence (server now injects config)
    if (window.YCookiesPreviewMode) {
        // In preview mode, the manager is initialized separately by the preview page
        return;
    }

    // Normal initialization: create manager which will use server-injected config
    const manager = new YCookiesManager();
    
    // Preserve server-injected config reference on window.YCookies
    window.YCookies = window.YCookies || {};
    window.YCookies.manager = manager;

    // Service worker: opt-in disabled to avoid 404 noise on customer sites.
    // If needed in the future, ensure it only runs on the main platform domain or is explicitly provided by the customer.
});
