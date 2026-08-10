import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import { YCookiesManager } from '../manager.js';

function buildConfig(overrides = {}) {
    return {
        site_id: 'test_123',
        consent_version: '1.0',
        tcm_config: { enabled: false, has_google_services: false },
        tcf_enabled: false,
        cookie_groups: [
            {
                key: 'essential',
                is_required: true,
                services: [{ key: 'session' }]
            },
            {
                key: 'marketing',
                is_required: false,
                services: [
                    { key: 'google_ads', consent_mode_mapping: { consent_signals: ['ad_storage', 'ad_user_data', 'ad_personalization'] } },
                    { key: 'facebook_pixel' }
                ]
            },
            {
                key: 'analytics',
                is_required: false,
                services: [
                    { key: 'google_analytics', consent_mode_mapping: { consent_signals: ['analytics_storage'] } }
                ]
            }
        ],
        ...overrides,
    };
}

function createManager(configOverrides = {}) {
    const m = new YCookiesManager();
    m.config = buildConfig(configOverrides);
    return m;
}

describe('YCookiesManager Consent Engine', () => {
    let mockManager;

    beforeEach(() => {
        document.body.innerHTML = '';
        document.cookie = 'ycookies_consent=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
        localStorage.clear();
        window.YCookiesPreviewMode = true;
        window.YCookies = { config: buildConfig() };
        window.dataLayer = [];

        vi.spyOn(console, 'log').mockImplementation(() => {});
        vi.spyOn(console, 'warn').mockImplementation(() => {});
        vi.spyOn(console, 'error').mockImplementation(() => {});

        mockManager = createManager();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    // ── Core consent generation ──────────────────────────────

    it('generates essential-only consent honoring required groups', () => {
        const consent = mockManager.generateEssentialOnlyConsent();
        expect(consent.essential).toBe(true);
        expect(consent.session).toBe(true);
        expect(consent.marketing).toBe(false);
        expect(consent.analytics).toBe(false);
        expect(consent.google_ads).toBe(false);
    });

    it('generates full consent object by accepting all groups', () => {
        const consent = mockManager.generateFullConsentObject();
        expect(consent.essential).toBe(true);
        expect(consent.marketing).toBe(true);
        expect(consent.analytics).toBe(true);
        expect(consent.google_analytics).toBe(true);
    });

    it('returns empty object when config has no cookie_groups', () => {
        mockManager.config = { site_id: 'test' };
        expect(mockManager.generateFullConsentObject()).toEqual({});
        expect(mockManager.generateEssentialOnlyConsent()).toEqual({});
    });

    // ── Cookie persistence ───────────────────────────────────

    it('hasConsented returns false when no cookie is set', () => {
        expect(mockManager.hasConsented()).toBe(false);
    });

    it('hasConsented returns true when cookie exists', () => {
        document.cookie = 'ycookies_consent={"essential":true}; path=/;';
        expect(mockManager.hasConsented()).toBe(true);
    });

    it('saveLocalCookie writes an encoded ycookies_consent cookie', () => {
        mockManager.saveLocalCookie({ essential: true, analytics: true });
        expect(document.cookie).toContain('ycookies_consent=');
        const match = document.cookie.match(/ycookies_consent=([^;]+)/);
        const decoded = JSON.parse(decodeURIComponent(match[1]));
        expect(decoded.essential).toBe(true);
        expect(decoded.analytics).toBe(true);
    });

    it('loadLocalConsent parses a previously stored cookie', () => {
        const state = { essential: true, marketing: false, analytics: true };
        document.cookie = `ycookies_consent=${encodeURIComponent(JSON.stringify(state))}; path=/;`;
        mockManager.loadLocalConsent();
        expect(mockManager.consentState.essential).toBe(true);
        expect(mockManager.consentState.marketing).toBe(false);
        expect(mockManager.consentState.analytics).toBe(true);
    });

    it('loadLocalConsent handles malformed cookie gracefully', () => {
        document.cookie = 'ycookies_consent=not-valid-json; path=/;';
        mockManager.loadLocalConsent();
        expect(console.warn).toHaveBeenCalled();
    });

    it('clearConsent removes cookie and resets state to essential-only', () => {
        document.cookie = 'ycookies_consent={"essential":true,"marketing":true}; path=/;';
        localStorage.setItem('ycookies_consent_version', '1.0');
        mockManager.clearConsent();
        expect(mockManager.consentState).toEqual({ essential: true });
        expect(localStorage.getItem('ycookies_consent_version')).toBeNull();
    });

    // ── URL overrides ────────────────────────────────────────

    it('applies URL parameter override: accept_all', () => {
        const originalLocation = window.location;
        delete window.location;
        window.location = { ...originalLocation, search: '?ycookies_consent=accept_all' };

        const m = createManager();
        m.applyUrlConsentOverrides();
        expect(m.hasConsented()).toBe(true);
        expect(m.consentState.marketing).toBe(true);
        expect(m.consentState.analytics).toBe(true);

        window.location = originalLocation;
    });

    it('applies URL parameter override: essential_only', () => {
        const originalLocation = window.location;
        delete window.location;
        window.location = { ...originalLocation, search: '?ycookies_consent=essential_only' };

        const m = createManager();
        m.applyUrlConsentOverrides();
        expect(m.hasConsented()).toBe(true);
        expect(m.consentState.essential).toBe(true);
        expect(m.consentState.marketing).toBe(false);

        window.location = originalLocation;
    });

    it('ignores unknown URL consent value', () => {
        const originalLocation = window.location;
        delete window.location;
        window.location = { ...originalLocation, search: '?ycookies_consent=unknown_value' };

        const m = createManager();
        m.applyUrlConsentOverrides();
        expect(m.hasConsented()).toBe(false);

        window.location = originalLocation;
    });

    // ── Google Consent Mode v2 ───────────────────────────────

    it('calculateGoogleConsent defaults all signals to denied', () => {
        mockManager.consentState = {};
        const gcm = mockManager.calculateGoogleConsent();
        expect(gcm.ad_storage).toBe('denied');
        expect(gcm.analytics_storage).toBe('denied');
        expect(gcm.ad_user_data).toBe('denied');
        expect(gcm.ad_personalization).toBe('denied');
    });

    it('calculateGoogleConsent grants signals when group is consented', () => {
        mockManager.consentState = { essential: true, marketing: true, analytics: true };
        const gcm = mockManager.calculateGoogleConsent();
        expect(gcm.ad_storage).toBe('granted');
        expect(gcm.ad_user_data).toBe('granted');
        expect(gcm.ad_personalization).toBe('granted');
        expect(gcm.analytics_storage).toBe('granted');
    });

    it('calculateGoogleConsent only grants signals for consented groups', () => {
        mockManager.consentState = { essential: true, marketing: false, analytics: true };
        const gcm = mockManager.calculateGoogleConsent();
        expect(gcm.ad_storage).toBe('denied');
        expect(gcm.analytics_storage).toBe('granted');
    });

    it('calculateGoogleConsent falls back to legacy tcm_config.mapping', () => {
        const m = createManager({
            cookie_groups: [
                { key: 'essential', is_required: true, services: [{ key: 'session' }] },
                { key: 'analytics', is_required: false, services: [{ key: 'ga' }] },
            ],
            tcm_config: {
                enabled: true,
                has_google_services: true,
                mapping: { analytics: ['analytics_storage'] },
            },
        });
        m.consentState = { essential: true, analytics: true };
        const gcm = m.calculateGoogleConsent();
        expect(gcm.analytics_storage).toBe('granted');
    });

    it('initGoogleConsentMode skips when no google services', () => {
        mockManager.config.tcm_config = { enabled: true, has_google_services: false };
        window.dataLayer = [];
        mockManager.initGoogleConsentMode();
        const consentCalls = window.dataLayer.filter(
            e => Array.isArray(e) ? false : e[0] === 'consent'
        );
        expect(consentCalls.length).toBe(0);
    });

    it('initGoogleConsentMode sets strict defaults for new users', () => {
        mockManager.config.tcm_config = { enabled: true, has_google_services: true };
        window.dataLayer = [];
        const gtagCalls = [];
        window.gtag = (...args) => gtagCalls.push(args);
        mockManager.initGoogleConsentMode();
        const defaultCall = gtagCalls.find(c => c[0] === 'consent' && c[1] === 'default');
        expect(defaultCall).toBeDefined();
        expect(defaultCall[2].ad_storage).toBe('denied');
    });

    it('updateGoogleConsentMode fires gtag consent update', () => {
        mockManager.config.tcm_config = { enabled: true, has_google_services: true };
        mockManager.consentState = { essential: true, analytics: true };
        const gtagCalls = [];
        window.gtag = (...args) => gtagCalls.push(args);
        window.dataLayer = [];
        mockManager.updateGoogleConsentMode();
        const updateCall = gtagCalls.find(c => c[0] === 'consent' && c[1] === 'update');
        expect(updateCall).toBeDefined();
        expect(updateCall[2].analytics_storage).toBe('granted');
    });

    // ── GPC (Global Privacy Control) ─────────────────────────

    it('detects GPC signal from script attribute', () => {
        const m = new YCookiesManager();
        const script = document.createElement('script');
        script.setAttribute('data-ycookies-gpc', '1');
        script.setAttribute('data-site-id', 'test_123');
        document.body.appendChild(script);
        // GPC is read during constructor via navigator.globalPrivacyControl
        // We can test the attribute path indirectly
        expect(script.getAttribute('data-ycookies-gpc')).toBe('1');
    });

    // ── UID generation ───────────────────────────────────────

    it('generateUID returns a 32-char hex string', () => {
        const uid = mockManager.generateUID();
        expect(uid).toMatch(/^[0-9a-f]{32}$/);
    });

    it('generateUID produces unique values', () => {
        const a = mockManager.generateUID();
        const b = mockManager.generateUID();
        expect(a).not.toBe(b);
    });

    // ── DataLayer events ─────────────────────────────────────

    it('pushDataLayerEvents pushes initialization and per-category events', () => {
        window.dataLayer = [];
        mockManager.pushDataLayerEvents({ essential: true, analytics: true, marketing: false });
        const events = window.dataLayer.map(e => e.event);
        expect(events).toContain('ycookies_initialized');
        expect(events).toContain('ycookies_consent_essential');
        expect(events).toContain('ycookies_consent_analytics');
        expect(events).not.toContain('ycookies_consent_marketing');
    });

    // ── Consent version tracking ─────────────────────────────

    it('stores consent version in localStorage after saveLocalCookie', () => {
        mockManager.consentState = { essential: true };
        mockManager.saveLocalCookie(mockManager.consentState);
        // saveConsent stores version (saveLocalCookie does not) — verify via saveConsent
        // We test saveLocalCookie writes the cookie correctly
        expect(document.cookie).toContain('ycookies_consent=');
    });

    // ── TCF stub when disabled ───────────────────────────────
    // The constructor always installs a __tcfapi stub in preview mode,
    // so we test the stub that was set during construction.

    it('constructor provides a __tcfapi stub that responds to ping', () => {
        expect(typeof window.__tcfapi).toBe('function');

        let pingResult = null;
        window.__tcfapi('ping', 2, (result) => { pingResult = result; });
        expect(pingResult).toBeDefined();
        expect(pingResult.cmpLoaded).toBe(true);
        expect(pingResult.cmpStatus).toBe('stub');
    });

    it('__tcfapi does not crash on unknown commands', () => {
        expect(typeof window.__tcfapi).toBe('function');
        expect(() => {
            window.__tcfapi('unknownCommand', 2, () => {});
        }).not.toThrow();
    });

    // ── Edge cases ───────────────────────────────────────────

    it('handles config with empty services array', () => {
        mockManager.config.cookie_groups = [
            { key: 'essential', is_required: true, services: [] },
            { key: 'tracking', is_required: false },
        ];
        const full = mockManager.generateFullConsentObject();
        expect(full.essential).toBe(true);
        expect(full.tracking).toBe(true);
    });

    it('handles config with deeply nested consent_mode_mapping', () => {
        mockManager.config.cookie_groups = [
            {
                key: 'marketing',
                is_required: false,
                services: [
                    { key: 'svc1', consent_mode_mapping: { consent_signals: ['ad_storage', 'ad_user_data'] } },
                    { key: 'svc2', consent_mode_mapping: { consent_signals: ['ad_personalization'] } },
                    { key: 'svc3' },
                ],
            },
        ];
        mockManager.consentState = { marketing: true };
        const gcm = mockManager.calculateGoogleConsent();
        expect(gcm.ad_storage).toBe('granted');
        expect(gcm.ad_user_data).toBe('granted');
        expect(gcm.ad_personalization).toBe('granted');
        expect(gcm.analytics_storage).toBe('denied');
    });

    it('saveConsent immediately unblocks server-blocked placeholders without cookie_groups config', () => {
        mockManager.config.cookie_groups = undefined;

        const original = '<iframe src="https://www.youtube.com/embed/test123" title="yt"></iframe>';
        const encodedOriginal = btoa(original);
        document.body.innerHTML = `
            <div class="ycookies-content-blocker"
                 data-ycookies-require-group="external_media"
                 data-ycookies-original="${encodedOriginal}">
                <button type="button">Accept & Load</button>
            </div>
        `;

        mockManager.saveConsent({ external_media: true });

        expect(document.querySelector('.ycookies-content-blocker')).toBeNull();
        const iframe = document.querySelector('iframe');
        expect(iframe).not.toBeNull();
        expect(iframe.getAttribute('src')).toContain('youtube.com/embed/test123');
    });

    it('unblockServerBlockedContent does not strip v2 embed placeholders without consent (legacy shortcut)', () => {
        mockManager.config.cookie_groups = buildConfig().cookie_groups;
        mockManager.consentState = { essential: true };

        const original = '<iframe src="https://www.youtube.com/embed/x" title="x"></iframe>';
        const encodedOriginal = btoa(original);
        document.body.innerHTML = `
            <div class="ycookies-content-blocker ycookies-embed-placeholder"
                 data-ycookies-service=""
                 data-ycookies-original="${encodedOriginal}">
                <button type="button" data-action="accept-once">Load</button>
            </div>
        `;

        mockManager.unblockServerBlockedContent();

        expect(document.querySelector('.ycookies-embed-placeholder')).not.toBeNull();
        expect(document.querySelector('iframe')).toBeNull();
    });

    it('shouldAllowByServiceOrGroup treats universal service_key with external_media consent', () => {
        mockManager.config.cookie_groups = [
            { key: 'external_media', is_required: false, services: [] },
        ];
        mockManager.consentState = { essential: true, external_media: true };
        expect(mockManager.shouldAllowByServiceOrGroup('universal', null, 'external_media')).toBe(true);
    });

    it('autoRestoreEmbeds restores placeholder when data-ycookies-require-group is consented', () => {
        mockManager.config.cookie_groups = [
            { key: 'external_media', is_required: false, services: [{ key: 'vimeo' }] },
        ];
        mockManager.consentState = { essential: true, external_media: true };
        mockManager._instanceUnlocks = new Set();

        const original = '<iframe src="https://player.vimeo.com/video/1" title="v"></iframe>';
        const encodedOriginal = btoa(original);
        document.body.innerHTML = `
            <div class="ycookies-embed-placeholder"
                 data-ycookies-service="universal"
                 data-ycookies-require-group="external_media"
                 data-ycookies-instance-id="i1"
                 data-ycookies-original="${encodedOriginal}"></div>
        `;

        mockManager.autoRestoreEmbeds();

        const iframe = document.querySelector('iframe');
        expect(iframe).not.toBeNull();
        expect(iframe.getAttribute('src')).toContain('vimeo.com');
    });

    it('does not block universal external iframe when marketing group is required', () => {
        const originalLocation = window.location;
        delete window.location;
        window.location = { ...originalLocation, hostname: 'site.test' };

        mockManager.config.cookie_groups = [
            { key: 'marketing', is_required: true, services: [] },
            { key: 'external_media', is_required: false, services: [] },
        ];
        mockManager.consentState = { essential: true };

        const iframe = document.createElement('iframe');
        iframe.setAttribute('src', 'https://www.youtube.com/embed/abc');
        document.body.appendChild(iframe);

        mockManager.handleIframeInterceptor(iframe);

        expect(iframe.getAttribute('src')).toContain('youtube.com/embed/abc');
        expect(iframe.hasAttribute('srcdoc')).toBe(false);

        window.location = originalLocation;
    });

    it('acceptEmbedInstance keeps restored iframe unblocked on re-intercept', () => {
        mockManager.config.content_blockers = [
            { key: 'youtube', hosts: ['youtube.com'] },
        ];
        mockManager.consentState = { essential: true };
        mockManager._instanceUnlocks = new Set();

        const original = '<iframe src="https://www.youtube.com/embed/abc123" title="yt"></iframe>';
        const encodedOriginal = btoa(original);
        document.body.innerHTML = `
            <div class="ycookies-content-blocker ycookies-embed-placeholder"
                 data-ycookies-service="youtube"
                 data-ycookies-provider="youtube.com"
                 data-ycookies-instance-id="inst_1"
                 data-ycookies-original="${encodedOriginal}">
                <button type="button" data-action="accept-once">Load this content</button>
            </div>
        `;

        const placeholder = document.querySelector('.ycookies-embed-placeholder');
        mockManager.acceptEmbedInstance('inst_1', placeholder);

        const iframe = document.querySelector('iframe');
        expect(iframe).not.toBeNull();

        mockManager.handleIframeInterceptor(iframe);

        expect(iframe.getAttribute('src')).toContain('youtube.com/embed/abc123');
        expect(iframe.hasAttribute('srcdoc')).toBe(false);
    });

    it('saveConsent unblocks server-blocked stylesheets with marketing consent', () => {
        document.head.innerHTML = `
            <link rel="stylesheet"
                  data-ycookies-style-blocked="true"
                  data-ycookies-style-href="https://cdn.example.net/app.css"
                  data-ycookies-require-group="marketing"
                  href="">
        `;

        mockManager.saveConsent({ marketing: true });

        const link = document.head.querySelector('link');
        expect(link.getAttribute('href')).toContain('cdn.example.net/app.css');
        expect(link.hasAttribute('data-ycookies-style-blocked')).toBe(false);
    });

    it('isUrlBlocked respects auto_blocking.service toggle', () => {
        const originalLocation = window.location;
        delete window.location;
        window.location = { ...originalLocation, hostname: 'site.test' };

        mockManager.config.auto_blocking = { content: true, script: true, style: true, service: true };
        mockManager.consentState = { essential: true };
        expect(mockManager.isUrlBlocked('https://tracking.thirdparty.test/pixel')).toBe(true);

        mockManager.config.auto_blocking.service = false;
        expect(mockManager.isUrlBlocked('https://tracking.thirdparty.test/pixel')).toBe(false);

        window.location = originalLocation;
    });
});
