<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YCookies — Full Integration Test Page</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- ═══════════════════════════════════════════════════════════════
         YCookies Consent Manager Embed Tag (just like a real client)
         ═══════════════════════════════════════════════════════════════ -->
    <script src="/api/script/{{ $siteId }}.js" data-ycookies-id="{{ $siteId }}" id="ycookies-manager" defer></script>

    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; background: #f0f2f5; color: #1a1a2e; line-height: 1.6; }
        .page-header { background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%); color: white; padding: 60px 20px 40px; text-align: center; }
        .page-header h1 { font-size: 2.5rem; font-weight: 700; margin-bottom: 8px; }
        .page-header p { opacity: 0.8; font-size: 1.05rem; max-width: 600px; margin: 0 auto; }
        .badge { display: inline-flex; align-items: center; gap: 6px; background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.2); backdrop-filter: blur(8px); padding: 4px 14px; border-radius: 999px; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 16px; }
        .badge .dot { width: 8px; height: 8px; border-radius: 50%; background: #3fb950; animation: pulse 2s infinite; }
        @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.5} }

        .container { max-width: 1200px; margin: 0 auto; padding: 40px 20px; }
        .section-title { font-size: 1.3rem; font-weight: 700; margin-bottom: 6px; display: flex; align-items: center; gap: 10px; }
        .section-desc { color: #666; font-size: 0.9rem; margin-bottom: 20px; }
        .grid { display: grid; gap: 24px; }
        .grid-2 { grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); }
        .grid-3 { grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); }

        .card { background: white; border-radius: 16px; border: 1px solid #e4e7ec; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
        .card-header { padding: 16px 20px; border-bottom: 1px solid #f0f2f5; display: flex; align-items: center; justify-content: space-between; }
        .card-header h3 { font-size: 0.95rem; font-weight: 600; }
        .card-body { padding: 20px; }
        .card-footer { padding: 12px 20px; background: #f8f9fb; border-top: 1px solid #f0f2f5; font-size: 0.8rem; color: #888; }

        .status-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; }
        .status-blocked { background: #fee2e2; color: #dc2626; }
        .status-loaded { background: #dcfce7; color: #16a34a; }
        .status-waiting { background: #fef3c7; color: #d97706; }

        .category-tag { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
        .tag-statistics { background: #dbeafe; color: #1d4ed8; }
        .tag-marketing { background: #fce7f3; color: #be185d; }
        .tag-external { background: #ede9fe; color: #6d28d9; }
        .tag-essential { background: #d1fae5; color: #059669; }

        .section-divider { margin: 48px 0; border: none; border-top: 2px solid #e4e7ec; }
        .iframe-container { position: relative; border-radius: 8px; overflow: hidden; background: #f0f2f5; border: 1px solid #e4e7ec; }
        .iframe-container iframe { display: block; width: 100%; border: none; }
        .info-box { padding: 12px 16px; background: #f0f6ff; border: 1px solid #bfdbfe; border-radius: 10px; font-size: 0.85rem; color: #1e40af; margin-bottom: 16px; }
        .code { font-family: 'Courier New', monospace; background: #1a1a2e; color: #e2e8f0; padding: 12px 16px; border-radius: 8px; font-size: 0.8rem; overflow-x: auto; white-space: pre; margin-top: 10px; }

        /* DataLayer Log */
        .log-terminal { background: #0d1117; color: #3fb950; font-family: 'Courier New', monospace; font-size: 0.75rem; padding: 16px; border-radius: 10px; height: 200px; overflow-y: auto; margin-top: 12px; border: 1px solid #30363d; }
        .log-terminal .timestamp { color: #8b949e; }
        .log-terminal .event-name { color: #58a6ff; font-weight: bold; }

        /* Privacy Policy Section */
        .privacy-section { background: white; border-radius: 16px; border: 1px solid #e4e7ec; padding: 30px; }
        .privacy-section h2 { font-size: 1.3rem; margin-bottom: 12px; }
    </style>
</head>
<body>

    <!-- ═══════════════════════════════════════════
         PAGE HEADER
         ═══════════════════════════════════════════ -->
    <header class="page-header">
        <div class="badge"><span class="dot"></span> Test Environment</div>
        <h1>YCookies Integration Test Page</h1>
        <p>This page simulates a real-world client website with tracking scripts, embedded content, and analytics — all gated by the YCookies consent manager.</p>
    </header>

    <div class="container">

        <!-- ═══════════════════════════════════════════════════════════════
             SECTION 1: SCRIPT BLOCKING (type="text/plain" data-category)
             ═══════════════════════════════════════════════════════════════ -->
        <h2 class="section-title">🛡️ Script Blocking Tests</h2>
        <p class="section-desc">These scripts use <code>type="text/plain" data-category="..."</code> and should be blocked until the user grants consent for the matching cookie group.</p>

        <div class="grid grid-3">
            <!-- Google Analytics Mock -->
            <div class="card">
                <div class="card-header">
                    <h3>📊 Google Analytics 4</h3>
                    <span class="category-tag tag-statistics">Statistics</span>
                </div>
                <div class="card-body">
                    <p style="font-size:0.85rem;color:#666;margin-bottom:12px;">Simulates GA4 tracking. Should only fire after <strong>statistics</strong> consent.</p>
                    <div id="ga4-status" class="status-badge status-blocked">⛔ Blocked</div>
                    <div class="code" id="ga4-log">// Waiting for consent...</div>
                </div>
                <div class="card-footer">Expects: <code>data-category="statistics"</code></div>
            </div>
            <script type="text/plain" data-category="statistics">
                console.log('[TEST] ✅ GA4 script loaded — statistics consent granted');
                document.getElementById('ga4-status').className = 'status-badge status-loaded';
                document.getElementById('ga4-status').innerHTML = '✅ Loaded';
                document.getElementById('ga4-log').textContent = 'gtag("event", "page_view", {...});\n// GA4 tracking code active!';
                window.__testResults = window.__testResults || {};
                window.__testResults.ga4 = true;
            </script>

            <!-- Google Tag Manager Mock -->
            <div class="card">
                <div class="card-header">
                    <h3>🏷️ Google Tag Manager</h3>
                    <span class="category-tag tag-marketing">Marketing</span>
                </div>
                <div class="card-body">
                    <p style="font-size:0.85rem;color:#666;margin-bottom:12px;">Simulates GTM. Should only fire after <strong>marketing</strong> consent.</p>
                    <div id="gtm-status" class="status-badge status-blocked">⛔ Blocked</div>
                    <div class="code" id="gtm-log">// Waiting for consent...</div>
                </div>
                <div class="card-footer">Expects: <code>data-category="marketing"</code></div>
            </div>
            <script type="text/plain" data-category="marketing">
                console.log('[TEST] ✅ GTM script loaded — marketing consent granted');
                document.getElementById('gtm-status').className = 'status-badge status-loaded';
                document.getElementById('gtm-status').innerHTML = '✅ Loaded';
                document.getElementById('gtm-log').textContent = '(function(w,d,s,l,i){...})\n// GTM container active!';
                window.__testResults = window.__testResults || {};
                window.__testResults.gtm = true;
            </script>

            <!-- Meta Pixel Mock -->
            <div class="card">
                <div class="card-header">
                    <h3>📱 Meta Pixel</h3>
                    <span class="category-tag tag-marketing">Marketing</span>
                </div>
                <div class="card-body">
                    <p style="font-size:0.85rem;color:#666;margin-bottom:12px;">Simulates Facebook/Meta Pixel. Should only fire after <strong>marketing</strong> consent.</p>
                    <div id="meta-status" class="status-badge status-blocked">⛔ Blocked</div>
                    <div class="code" id="meta-log">// Waiting for consent...</div>
                </div>
                <div class="card-footer">Expects: <code>data-category="marketing"</code></div>
            </div>
            <script type="text/plain" data-category="marketing">
                console.log('[TEST] ✅ Meta Pixel loaded — marketing consent granted');
                document.getElementById('meta-status').className = 'status-badge status-loaded';
                document.getElementById('meta-status').innerHTML = '✅ Loaded';
                document.getElementById('meta-log').textContent = 'fbq("init", "123456789");\nfbq("track", "PageView");';
                window.__testResults = window.__testResults || {};
                window.__testResults.metaPixel = true;
            </script>
        </div>

        <hr class="section-divider">

        <!-- ═══════════════════════════════════════════════════════════════
             SECTION 2: CONTENT BLOCKERS (iframes with data-ycookies-src)
             ═══════════════════════════════════════════════════════════════ -->
        <h2 class="section-title">🎬 Content Blocker Tests</h2>
        <p class="section-desc">These iframes use <code>data-ycookies-src</code> instead of <code>src</code>. They should show a placeholder until consent is given, then load the real content.</p>

        <div class="grid grid-2">
            <!-- YouTube Embed -->
            <div class="card">
                <div class="card-header">
                    <h3>▶️ YouTube Video</h3>
                    <span id="yt-status" class="status-badge status-blocked">⛔ Blocked</span>
                </div>
                <div class="card-body">
                    <div class="iframe-container">
                        <iframe width="100%" height="280"
                            data-ycookies-src="https://www.youtube.com/embed/dQw4w9WgXcQ"
                            title="YouTube test video"
                            frameborder="0"
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                            allowfullscreen></iframe>
                    </div>
                </div>
                <div class="card-footer">Content Blocker: youtube.com host matching</div>
            </div>

            <!-- Google Maps Embed -->
            <div class="card">
                <div class="card-header">
                    <h3>🗺️ Google Maps</h3>
                    <span id="maps-status" class="status-badge status-blocked">⛔ Blocked</span>
                </div>
                <div class="card-body">
                    <div class="iframe-container">
                        <iframe width="100%" height="280"
                            data-ycookies-src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2428.654073788!2d13.377704315937!3d52.516275279811!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x47a851c655f20989%3A0x26bbfb4e84674c63!2sBrandenburger%20Tor!5e0!3m2!1sde!2sde!4v1609459200000!5m2!1sde!2sde"
                            title="Google Maps test embed"
                            style="border:0;"
                            allowfullscreen=""
                            loading="lazy"
                            referrerpolicy="no-referrer-when-downgrade"></iframe>
                    </div>
                </div>
                <div class="card-footer">Content Blocker: google.com/maps host matching</div>
            </div>
        </div>

        <hr class="section-divider">

        <!-- ═══════════════════════════════════════════════════════════════
             SECTION 3: GOOGLE CONSENT MODE & DATALAYER MONITOR
             ═══════════════════════════════════════════════════════════════ -->
        <h2 class="section-title">🔬 Google Consent Mode v2 Monitor</h2>
        <p class="section-desc">Live tracking of <code>window.dataLayer</code> events. Watch for <code>consent default</code>, <code>consent update</code>, and custom events.</p>

        <div class="grid grid-2">
            <!-- Consent Mode State -->
            <div class="card">
                <div class="card-header">
                    <h3>🎛️ Current Consent State</h3>
                </div>
                <div class="card-body">
                    <div id="consent-state-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                        <div id="cs-ad_storage" class="status-badge status-blocked" style="justify-content:center;">ad_storage: denied</div>
                        <div id="cs-analytics_storage" class="status-badge status-blocked" style="justify-content:center;">analytics_storage: denied</div>
                        <div id="cs-ad_user_data" class="status-badge status-blocked" style="justify-content:center;">ad_user_data: denied</div>
                        <div id="cs-ad_personalization" class="status-badge status-blocked" style="justify-content:center;">ad_personalization: denied</div>
                        <div id="cs-personalization_storage" class="status-badge status-blocked" style="justify-content:center;">personalization_storage: denied</div>
                        <div id="cs-functionality_storage" class="status-badge status-loaded" style="justify-content:center;">functionality_storage: granted</div>
                        <div id="cs-security_storage" class="status-badge status-loaded" style="justify-content:center;">security_storage: granted</div>
                    </div>
                </div>
            </div>

            <!-- DataLayer Log -->
            <div class="card">
                <div class="card-header">
                    <h3>📋 dataLayer Event Log</h3>
                    <button onclick="document.getElementById('dl-log').innerHTML=''" style="border:none;background:#f0f2f5;padding:4px 10px;border-radius:6px;font-size:0.75rem;cursor:pointer;">Clear</button>
                </div>
                <div class="card-body">
                    <div class="log-terminal" id="dl-log"></div>
                </div>
            </div>
        </div>

        <hr class="section-divider">

        <!-- ═══════════════════════════════════════════════════════════════
             SECTION 4: MORE THIRD-PARTY EMBEDS
             ═══════════════════════════════════════════════════════════════ -->
        <h2 class="section-title">🔌 Additional Embed Tests</h2>
        <p class="section-desc">Testing various external services that require consent management.</p>

        <div class="grid grid-3">
            <!-- Hotjar Mock -->
            <div class="card">
                <div class="card-header">
                    <h3>🔥 Hotjar</h3>
                    <span class="category-tag tag-statistics">Statistics</span>
                </div>
                <div class="card-body">
                    <p style="font-size:0.85rem;color:#666;margin-bottom:12px;">Heatmap and session recording tool.</p>
                    <div id="hotjar-status" class="status-badge status-blocked">⛔ Blocked</div>
                </div>
            </div>
            <script type="text/plain" data-category="statistics">
                console.log('[TEST] ✅ Hotjar loaded');
                document.getElementById('hotjar-status').className = 'status-badge status-loaded';
                document.getElementById('hotjar-status').innerHTML = '✅ Loaded';
                window.__testResults = window.__testResults || {};
                window.__testResults.hotjar = true;
            </script>

            <!-- LinkedIn Insight Mock -->
            <div class="card">
                <div class="card-header">
                    <h3>💼 LinkedIn Insight Tag</h3>
                    <span class="category-tag tag-marketing">Marketing</span>
                </div>
                <div class="card-body">
                    <p style="font-size:0.85rem;color:#666;margin-bottom:12px;">LinkedIn conversion tracking.</p>
                    <div id="linkedin-status" class="status-badge status-blocked">⛔ Blocked</div>
                </div>
            </div>
            <script type="text/plain" data-category="marketing">
                console.log('[TEST] ✅ LinkedIn Insight loaded');
                document.getElementById('linkedin-status').className = 'status-badge status-loaded';
                document.getElementById('linkedin-status').innerHTML = '✅ Loaded';
                window.__testResults = window.__testResults || {};
                window.__testResults.linkedin = true;
            </script>

            <!-- Essential Script (should always run) -->
            <div class="card">
                <div class="card-header">
                    <h3>🔒 Essential Script</h3>
                    <span class="category-tag tag-essential">Essential</span>
                </div>
                <div class="card-body">
                    <p style="font-size:0.85rem;color:#666;margin-bottom:12px;">This script is essential and should <strong>always</strong> load immediately.</p>
                    <div id="essential-status" class="status-badge status-waiting">⏳ Checking...</div>
                </div>
            </div>
            <script type="text/plain" data-category="essential">
                console.log('[TEST] ✅ Essential script loaded immediately');
                document.getElementById('essential-status').className = 'status-badge status-loaded';
                document.getElementById('essential-status').innerHTML = '✅ Always Active';
                window.__testResults = window.__testResults || {};
                window.__testResults.essential = true;
            </script>
        </div>

        <hr class="section-divider">

        <!-- ═══════════════════════════════════════════════════════════════
             SECTION 5: PRIVACY POLICY SECTION (ycookies-accepted-list hook)
             ═══════════════════════════════════════════════════════════════ -->
        <div class="privacy-section">
            <h2>📜 Privacy Policy — Your Cookie Preferences</h2>
            <p style="color:#666;font-size:0.9rem;margin-bottom:20px;">This section uses the <code>&lt;div id="ycookies-accepted-list"&gt;</code> hook. The consent manager auto-renders a detailed cookie table here.</p>
            <div id="ycookies-accepted-list">
                <p style="color:#999;font-style:italic;">Loading your cookie preferences...</p>
            </div>
        </div>

        <hr class="section-divider">

        <!-- ═══════════════════════════════════════════════════════════════
             SECTION 6: TEST RESULTS SUMMARY
             ═══════════════════════════════════════════════════════════════ -->
        <div class="card" id="test-summary-card">
            <div class="card-header">
                <h3>📊 Test Results Summary</h3>
                <button onclick="refreshTestSummary()" style="border:none;background:#3b82f6;color:white;padding:6px 16px;border-radius:8px;font-size:0.8rem;cursor:pointer;font-weight:600;">Refresh</button>
            </div>
            <div class="card-body" id="test-summary">
                <p style="color:#888;font-size:0.85rem;">Click "Accept All" on the consent banner, then click "Refresh" to see test results.</p>
            </div>
        </div>

    </div>

    <!-- ═══════════════════════════════════════════
         DATALAYER INTERCEPTOR & TEST FRAMEWORK
         ═══════════════════════════════════════════ -->
    <script>
        // Initialize test results
        window.__testResults = window.__testResults || {};

        // Intercept dataLayer pushes for the log
        window.dataLayer = window.dataLayer || [];
        const _origPush = Array.prototype.push;
        const logBox = document.getElementById('dl-log');

        window.dataLayer.push = function() {
            _origPush.apply(this, arguments);
            const now = new Date();
            const ts = `${now.getHours().toString().padStart(2,'0')}:${now.getMinutes().toString().padStart(2,'0')}:${now.getSeconds().toString().padStart(2,'0')}.${now.getMilliseconds().toString().padStart(3,'0')}`;

            Array.from(arguments).forEach(arg => {
                // Update consent state display
                if (Array.isArray(arg) && arg[0] === 'consent' && arg.length > 2) {
                    const params = arg[2];
                    for (const key in params) {
                        const el = document.getElementById('cs-' + key);
                        if (el) {
                            el.className = 'status-badge ' + (params[key] === 'granted' ? 'status-loaded' : 'status-blocked');
                            el.textContent = key + ': ' + params[key];
                            el.style.justifyContent = 'center';
                        }
                    }
                }

                // Log it
                if (logBox) {
                    let label = 'push';
                    let color = '#8b949e';
                    if (Array.isArray(arg) && arg[0] === 'consent') {
                        label = 'consent.' + (arg[1] || 'unknown');
                        color = arg[1] === 'update' ? '#3fb950' : '#d29922';
                    } else if (arg && arg.event) {
                        label = 'event: ' + arg.event;
                        color = '#58a6ff';
                    }

                    const line = document.createElement('div');
                    line.innerHTML = `<span class="timestamp">[${ts}]</span> <span class="event-name" style="color:${color}">${label}</span>\n${JSON.stringify(arg, null, 2)}\n`;
                    logBox.appendChild(line);
                    logBox.scrollTop = logBox.scrollHeight;
                }
            });
        };

        // Observe iframe src changes for content blocker status
        const ytObserver = new MutationObserver(() => {
            document.querySelectorAll('iframe').forEach(iframe => {
                if (iframe.src && iframe.src.includes('youtube.com')) {
                    const s = document.getElementById('yt-status');
                    if (s) { s.className = 'status-badge status-loaded'; s.innerHTML = '✅ Loaded'; }
                    window.__testResults.youtube = true;
                }
                if (iframe.src && iframe.src.includes('google.com/maps')) {
                    const s = document.getElementById('maps-status');
                    if (s) { s.className = 'status-badge status-loaded'; s.innerHTML = '✅ Loaded'; }
                    window.__testResults.googleMaps = true;
                }
            });
        });
        ytObserver.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['src'] });

        // Test summary
        function refreshTestSummary() {
            const r = window.__testResults || {};
            const tests = [
                { name: 'GA4 (statistics)', key: 'ga4', category: 'statistics' },
                { name: 'GTM (marketing)', key: 'gtm', category: 'marketing' },
                { name: 'Meta Pixel (marketing)', key: 'metaPixel', category: 'marketing' },
                { name: 'Hotjar (statistics)', key: 'hotjar', category: 'statistics' },
                { name: 'LinkedIn (marketing)', key: 'linkedin', category: 'marketing' },
                { name: 'Essential Script', key: 'essential', category: 'essential' },
                { name: 'YouTube Content Blocker', key: 'youtube', category: 'content' },
                { name: 'Google Maps Content Blocker', key: 'googleMaps', category: 'content' },
            ];

            const passed = tests.filter(t => r[t.key]).length;
            const total = tests.length;

            let html = `<div style="margin-bottom:16px;font-size:1.1rem;font-weight:700;">${passed}/${total} Tests Passed</div>`;
            html += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:8px;">';
            tests.forEach(t => {
                const ok = r[t.key];
                html += `<div class="status-badge ${ok ? 'status-loaded' : 'status-blocked'}" style="justify-content:center;padding:8px 12px;">
                    ${ok ? '✅' : '⛔'} ${t.name}
                </div>`;
            });
            html += '</div>';
            document.getElementById('test-summary').innerHTML = html;
        }

        // Auto-refresh on consent changes
        document.addEventListener('ycookies:consent-updated', () => {
            setTimeout(refreshTestSummary, 1000);
        });
    </script>
</body>
</html>
