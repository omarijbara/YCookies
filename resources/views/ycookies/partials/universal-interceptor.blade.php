<script>
/**
 * YCookies Universal Tag Interceptor
 * Monkey-patches ALL common tracking pixel APIs BEFORE the page's own scripts load.
 * Sends intercepted calls to parent debugger via postMessage.
 */
(function() {
    'use strict';

    // 1. Data Clearing for "Clear Cache & Reload" Feature
    const params = new URLSearchParams(window.location.search);
    if (params.get('clear_data') === '1') {
        try {
            console.log('[YCookies] Clearing site data (localStorage, sessionStorage, tracking cookies)...');
            localStorage.clear();
            sessionStorage.clear();
            
            // Clear tracking cookies but preserve Laravel/Filament session cookies
            const keepCookies = ['XSRF-TOKEN', 'ycookies_session', 'laravel_session'];
            document.cookie.split(";").forEach(function(c) {
                let name = c.split("=")[0].trim();
                // If it's not an essential app cookie, clear it
                if (!keepCookies.includes(name) && !name.startsWith('filament_') && !name.startsWith('remember_')) {
                    document.cookie = name + "=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
                    document.cookie = name + "=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/; domain=" + window.location.hostname + ";";
                }
            });
        } catch(e) { console.error('[YCookies] Error clearing data:', e); }
    }
    
    const _send = function(type, title, payload) {
        try {
            window.parent.postMessage({
                type: 'ycookies_debugger_event',
                payload: payload,
                source: type,
                title: title
            }, '*');
        } catch(e) { /* ignore cloning errors */ }
    };

window.ycookiesConnected = false;

    // ---- CORS Proxy Fallback for Dynamic Scripts & Fetches ----
    const targetUrl = '{{ $externalUrl ?? "" }}';
    if (targetUrl) {
        let tOrigin = "";
        try { tOrigin = new URL(targetUrl).origin; } catch(e) {}
        
        if (tOrigin) {
            const proxyPrefix = window.location.origin + '/ycookies/proxy-asset/' + tOrigin.replace('://', '/');
            
            // Override fetch
            const nFetch = window.fetch;
            window.fetch = async function(...args) {
                let reqUrl = args[0];
                if (reqUrl instanceof Request) {
                    let rUrl = String(reqUrl.url);
                    const noScheme = tOrigin.replace(/^https?:\/\//, '//');
                    if (rUrl.startsWith(tOrigin)) {
                        args[0] = new Request(rUrl.replace(tOrigin, proxyPrefix), reqUrl);
                    } else if (rUrl.startsWith(noScheme)) {
                        args[0] = new Request(rUrl.replace(noScheme, proxyPrefix), reqUrl);
                    }
                } else {
                    let sUrl = String(reqUrl);
                    const noScheme = tOrigin.replace(/^https?:\/\//, '//');
                    if (sUrl.startsWith(tOrigin)) {
                        args[0] = sUrl.replace(tOrigin, proxyPrefix);
                    } else if (sUrl.startsWith(noScheme)) {
                        args[0] = sUrl.replace(noScheme, proxyPrefix);
                    }
                }
                return nFetch.apply(this, args);
            };

            // Override XHR
            window.xhrLogs = window.xhrLogs || [];
            const nOpen = XMLHttpRequest.prototype.open;
            XMLHttpRequest.prototype.open = function(method, url, ...rest) {
                let sUrl = String(url);
                const noScheme = tOrigin.replace(/^https?:\/\//, '//');
                let wasRewritten = false;
                let finalUrl = sUrl;
                
                if (sUrl.startsWith(tOrigin)) {
                    finalUrl = sUrl.replace(tOrigin, proxyPrefix);
                    wasRewritten = true;
                } else if (sUrl.startsWith(noScheme)) {
                    finalUrl = sUrl.replace(noScheme, proxyPrefix);
                    wasRewritten = true;
                }
                
                window.xhrLogs.push({
                    tOrigin: tOrigin,
                    sUrl: sUrl,
                    matchedAbsolute: sUrl.startsWith(tOrigin),
                    matchedRelative: sUrl.startsWith(noScheme),
                    wasRewritten: wasRewritten,
                    finalUrl: finalUrl
                });
                
                console.warn("[YCookies XHR REAL]: method=", method, "url=", sUrl, "rewritten=", wasRewritten);
                return nOpen.call(this, method, finalUrl, ...rest);
            };

            // Enhance document.createElement to intercept script and link sources, and native iframe bypasses
            const nCreateElement = document.createElement;
            document.createElement = function(tagName) {
                const el = nCreateElement.call(document, tagName);
                const tagStr = tagName.toLowerCase();
                
                if (tagStr === 'script' || tagStr === 'link') {
                    const attrName = tagStr === 'script' ? 'src' : 'href';
                    const nSetAttribute = el.setAttribute;
                    const noScheme = tOrigin.replace(/^https?:\/\//, '//');
                    
                    el.setAttribute = function(name, value) {
                        if (name === attrName && value && typeof value === 'string') {
                            if (value.startsWith(tOrigin)) value = value.replace(tOrigin, proxyPrefix);
                            else if (value.startsWith(noScheme)) value = value.replace(noScheme, proxyPrefix);
                        }
                        return nSetAttribute.call(this, name, value);
                    };
                    Object.defineProperty(el, attrName, {
                        set: function(value) {
                            if (value && typeof value === 'string') {
                                if (value.startsWith(tOrigin)) value = value.replace(tOrigin, proxyPrefix);
                                else if (value.startsWith(noScheme)) value = value.replace(noScheme, proxyPrefix);
                            }
                            nSetAttribute.call(this, attrName, value);
                        },
                        get: function() { return this.getAttribute(attrName); }
                    });
                } else if (tagStr === 'iframe') {
                    const observer = new MutationObserver(function() {
                        if (el.contentWindow) {
                            try {
                                el.contentWindow.fetch = window.fetch;
                                el.contentWindow.XMLHttpRequest = window.XMLHttpRequest;
                            } catch(e) {}
                            observer.disconnect();
                        }
                    });
                    observer.observe(document.body, { childList: true, subtree: true });
                }
                return el;
            };

            // Enhance insertAdjacentHTML to rewrite raw HTML strings containing absolute origin URLs
            const nInsertAdjacentHTML = Element.prototype.insertAdjacentHTML;
            Element.prototype.insertAdjacentHTML = function(position, text) {
                if (typeof text === 'string') {
                    const noScheme = tOrigin.replace(/^https?:\/\//, '//');
                    // Ghetto global string replacement before HTML parsing
                    text = text.split(tOrigin).join(proxyPrefix);
                    // text = text.split(noScheme).join(proxyPrefix); // can be dangerous but let's just do tOrigin
                }
                return nInsertAdjacentHTML.call(this, position, text);
            };
        }
    }
    // -----------------------------------------------------------

    // ─── dataLayer / gtag ─────────────────────────────────────
    window.dataLayer = window.dataLayer || [];
    const _origPush = Array.prototype.push;
    window.dataLayer.push = function() {
        const result = _origPush.apply(window.dataLayer, arguments);
        for (let i = 0; i < arguments.length; i++) {
            const arg = arguments[i];
            if (typeof arg === 'function') continue;
            
            // Detect gtag consent calls
            if (Array.isArray(arg) && arg[0] === 'consent') {
                _send('consent', "gtag('consent', '" + arg[1] + "')", arg);
            } else {
                _send('datalayer', arg.event ? 'Event: ' + arg.event : 'dataLayer.push()', arg);
            }
        }
        return result;
    };

    // Intercept gtag() — captures ALL gtag calls including config, event, set, consent
    if (!window.gtag) {
        window.gtag = function() { window.dataLayer.push(arguments); };
    }
    const _origGtag = window.gtag;
    window.gtag = function() {
        const args = Array.from(arguments);
        const cmd = args[0];
        
        if (cmd === 'consent') {
            _send('consent', "gtag('consent', '" + args[1] + "')", args.length > 2 ? args[2] : {});
        } else if (cmd === 'event') {
            _send('gtag', "gtag('event', '" + args[1] + "')", { command: cmd, event_name: args[1], params: args[2] || {} });
        } else if (cmd === 'config') {
            _send('gtag', "gtag('config', '" + args[1] + "')", { command: cmd, measurement_id: args[1], params: args[2] || {} });
        } else {
            _send('gtag', "gtag('" + cmd + "')", { command: cmd, args: args.slice(1) });
        }
        
        return _origGtag.apply(this, arguments);
    };

    // ─── Meta Pixel (fbq) ─────────────────────────────────────
    window._fbq_intercepted = [];
    const _origFbq = window.fbq;
    window.fbq = function() {
        const args = Array.from(arguments);
        const cmd = args[0]; // 'init', 'track', 'trackCustom', 'trackSingle'
        
        let title = "fbq('" + cmd + "'";
        if (args[1]) title += ", '" + args[1] + "'";
        title += ')';
        
        _send('meta', title, { command: cmd, args: args.slice(1) });
        window._fbq_intercepted.push(args);
        
        if (_origFbq) return _origFbq.apply(this, arguments);
    };
    window.fbq.callMethod = window.fbq;
    window.fbq.queue = window.fbq.queue || [];
    window.fbq.loaded = true;
    window.fbq.version = '2.0';

    // ─── TikTok Pixel (ttq) ──────────────────────────────────
    const _makeTtqProxy = function() {
        const methods = ['page', 'track', 'identify', 'instances', 'debug', 'on', 'off', 'once', 'ready', 'alias', 'group', 'enableCookie', 'disableCookie'];
        const ttq = { _i: [], _u: [] };
        
        methods.forEach(function(method) {
            ttq[method] = function() {
                const args = Array.from(arguments);
                _send('tiktok', "ttq." + method + "('" + (args[0] || '') + "')", { method: method, args: args });
                ttq._i.push([method, args]);
            };
        });
        
        ttq.load = function(pixelId) {
            _send('tiktok', "ttq.load('" + pixelId + "')", { method: 'load', pixel_id: pixelId });
            ttq._u.push(pixelId);
        };
        
        return ttq;
    };
    if (!window.ttq) window.ttq = _makeTtqProxy();

    // ─── LinkedIn (lintrk) ────────────────────────────────────
    const _origLintrk = window.lintrk;
    window.lintrk = function(action, data) {
        _send('linkedin', "lintrk('" + action + "')", { action: action, data: data || {} });
        if (_origLintrk) return _origLintrk.apply(this, arguments);
    };
    window.lintrk.q = window.lintrk.q || [];

    // ─── Pinterest (pintrk) ──────────────────────────────────
    const _origPintrk = window.pintrk;
    window.pintrk = function() {
        const args = Array.from(arguments);
        const cmd = args[0];
        _send('pinterest', "pintrk('" + cmd + "'" + (args[1] ? ", '" + args[1] + "'" : '') + ")", { command: cmd, args: args.slice(1) });
        if (_origPintrk) return _origPintrk.apply(this, arguments);
    };
    window.pintrk.queue = window.pintrk.queue || [];

    // ─── Twitter/X (twq) ─────────────────────────────────────
    const _origTwq = window.twq;
    window.twq = function() {
        const args = Array.from(arguments);
        const cmd = args[0];
        _send('twitter', "twq('" + cmd + "'" + (args[1] ? ", '" + args[1] + "'" : '') + ")", { command: cmd, args: args.slice(1) });
        if (_origTwq) return _origTwq.apply(this, arguments);
    };
    window.twq.queue = window.twq.queue || [];

    // ─── Snapchat (snaptr) ───────────────────────────────────
    const _origSnaptr = window.snaptr;
    window.snaptr = function() {
        const args = Array.from(arguments);
        const cmd = args[0];
        _send('snapchat', "snaptr('" + cmd + "'" + (args[1] ? ", '" + args[1] + "'" : '') + ")", { command: cmd, args: args.slice(1) });
        if (_origSnaptr) return _origSnaptr.apply(this, arguments);
    };
    window.snaptr.queue = window.snaptr.queue || [];

    // ─── Network Request Monitoring ──────────────────────────
    // Monitor pixel fire requests to known tracking endpoints
    const PIXEL_ENDPOINTS = {
        'facebook.com/tr': 'meta',
        'connect.facebook.net': 'meta',
        'google-analytics.com': 'google-analytics',
        'googletagmanager.com': 'gtm',
        'analytics.tiktok.com': 'tiktok',
        'snap.licdn.com': 'linkedin',
        'px.ads.linkedin.com': 'linkedin',
        'ct.pinterest.com': 'pinterest',
        'analytics.twitter.com': 'twitter',
        't.co/i/adsct': 'twitter',
        'sc-static.net': 'snapchat',
        'tr.snapchat.com': 'snapchat',
        'googleads.g.doubleclick.net': 'google-ads',
        'www.google.com/pagead': 'google-ads',
        'bat.bing.com': 'bing',
        'clarity.ms': 'clarity',
        'hotjar.com': 'hotjar'
    };

    if (window.PerformanceObserver) {
        try {
            const observer = new PerformanceObserver(function(list) {
                for (const entry of list.getEntries()) {
                    const url = entry.name;
                    for (const [endpoint, source] of Object.entries(PIXEL_ENDPOINTS)) {
                        if (url.includes(endpoint)) {
                            _send('network', source + ' request: ' + url.split('?')[0].split('/').pop(), {
                                url: url,
                                source: source,
                                type: entry.initiatorType,
                                duration: Math.round(entry.duration) + 'ms',
                                size: entry.transferSize ? Math.round(entry.transferSize / 1024 * 100) / 100 + 'kb' : 'N/A'
                            });
                            break;
                        }
                    }
                }
            });
            observer.observe({ type: 'resource', buffered: true });
        } catch(e) { /* PerformanceObserver not supported */ }
    }

    // Signal parent that interceptor is ready
    window.parent.postMessage({ type: 'ycookies_preview_ready' }, '*');
})();
</script>
