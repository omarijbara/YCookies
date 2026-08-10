<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YCookies Bootstrapper v4 — Browser Test Harness</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- ═══════════════════════════════════════════════════════════════
         1. BOOTSTRAPPER (synchronous, must be first)
         ═══════════════════════════════════════════════════════════════ -->
    <script src="/api/boot/{{ $siteId }}.js"></script>

    <!-- ═══════════════════════════════════════════════════════════════
         2. KNOWN LIMITATION: Static <script src> after bootstrapper
         The HTML parser processes this BEFORE any JS patches can run.
         This WILL execute — it proves Method 1's honest limitation.
         ═══════════════════════════════════════════════════════════════ -->
    <script src="https://www.googletagmanager.com/gtag/js?id=GT-TEST-STATIC" id="static-gtm-test"></script>
    <script>
        // Record whether the static script above actually loaded
        window.__harness = window.__harness || {};
        window.__harness.staticGtm = typeof window.gtag === 'function' || document.getElementById('static-gtm-test') !== null;
    </script>

    <!-- ═══════════════════════════════════════════════════════════════
         3. MANAGER (defer — loads consent UI)
         ═══════════════════════════════════════════════════════════════ -->
    <script src="/api/script/{{ $siteId }}.js" id="ycookies-manager" defer></script>

    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', system-ui, sans-serif; background: #0d1117; color: #c9d1d9; line-height: 1.6; }

        .header { background: linear-gradient(135deg, #161b22 0%, #0d1117 100%); border-bottom: 1px solid #30363d; padding: 40px 20px; text-align: center; }
        .header h1 { font-size: 1.8rem; font-weight: 700; color: #f0f6fc; margin-bottom: 4px; }
        .header p { color: #8b949e; font-size: 0.95rem; max-width: 700px; margin: 0 auto; }
        .header .v-badge { display: inline-block; background: #1f6feb; color: white; padding: 2px 10px; border-radius: 999px; font-size: 0.7rem; font-weight: 700; margin-bottom: 12px; }

        .container { max-width: 1100px; margin: 0 auto; padding: 32px 20px; }

        /* Test Section */
        .test-section { background: #161b22; border: 1px solid #30363d; border-radius: 12px; margin-bottom: 20px; overflow: hidden; }
        .test-header { padding: 16px 20px; border-bottom: 1px solid #21262d; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .test-header h3 { font-size: 0.95rem; font-weight: 600; color: #f0f6fc; display: flex; align-items: center; gap: 8px; }
        .test-body { padding: 20px; }
        .test-footer { padding: 12px 20px; background: #0d1117; border-top: 1px solid #21262d; font-size: 0.78rem; color: #8b949e; display: flex; gap: 20px; flex-wrap: wrap; }

        /* Status badges */
        .badge { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; white-space: nowrap; }
        .badge-blocked { background: #da3633; color: #fff; }
        .badge-passed { background: #238636; color: #fff; }
        .badge-warning { background: #d29922; color: #fff; }
        .badge-waiting { background: #30363d; color: #8b949e; }

        /* Code blocks */
        .code-block { background: #0d1117; border: 1px solid #30363d; border-radius: 8px; padding: 12px 16px; font-family: 'Courier New', monospace; font-size: 0.78rem; color: #e6edf3; overflow-x: auto; white-space: pre; margin: 10px 0; }
        .code-block .comment { color: #8b949e; }
        .code-block .keyword { color: #ff7b72; }
        .code-block .string { color: #a5d6ff; }

        /* Rows */
        .info-row { display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }
        .info-label { font-size: 0.78rem; color: #8b949e; min-width: 120px; }
        .info-value { font-size: 0.85rem; color: #c9d1d9; }

        /* Buttons */
        .btn { border: none; padding: 8px 18px; border-radius: 8px; font-size: 0.8rem; font-weight: 600; cursor: pointer; transition: all 0.15s; }
        .btn-primary { background: #1f6feb; color: white; }
        .btn-primary:hover { background: #388bfd; }
        .btn-danger { background: #da3633; color: white; }
        .btn-danger:hover { background: #f85149; }
        .btn-success { background: #238636; color: white; }
        .btn-success:hover { background: #2ea043; }

        /* DOM Inspector */
        .dom-status { background: #0d1117; border: 1px solid #30363d; border-radius: 8px; padding: 10px 14px; font-family: 'Courier New', monospace; font-size: 0.75rem; color: #58a6ff; margin-top: 10px; min-height: 40px; }

        /* Summary */
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 10px; }
        .summary-item { display: flex; align-items: center; gap: 10px; padding: 10px 14px; background: #0d1117; border: 1px solid #30363d; border-radius: 8px; }
        .summary-item .name { font-size: 0.85rem; color: #c9d1d9; }

        /* Limitation callout */
        .limitation { background: #d299221a; border: 1px solid #d29922; border-radius: 10px; padding: 16px 20px; margin-bottom: 20px; }
        .limitation h3 { color: #d29922; font-size: 0.95rem; margin-bottom: 6px; }
        .limitation p { color: #e6edf3; font-size: 0.85rem; }

        .section-divider { margin: 32px 0 24px; padding: 0 0 8px; border: none; border-bottom: 1px solid #30363d; }
        .section-divider-title { font-size: 1.1rem; font-weight: 700; color: #f0f6fc; margin-bottom: 4px; }
        .section-divider-desc { font-size: 0.82rem; color: #8b949e; }
    </style>
</head>
<body>

    <header class="header">
        <div class="v-badge">Bootstrapper v4 Test Harness</div>
        <h1>🧪 Browser Interception Matrix</h1>
        <p>Each section tests one specific injection path. The bootstrapper is loaded synchronously above — check which scripts get blocked before consent.</p>
    </header>

    <div class="container">

        <!-- ═══════════════════════════════════════════════════════════════
             TEST 0: KNOWN LIMITATION — Static <script> in raw HTML
             ═══════════════════════════════════════════════════════════════ -->
        <div class="limitation">
            <h3>⚠️ Known Limitation: Static Parser-Time Scripts</h3>
            <p>The <code>&lt;script src="googletagmanager.com/gtag/js"&gt;</code> tag after the bootstrapper is in raw HTML. The HTML parser executes it synchronously — no client-side JS patch can intercept it. This is expected behavior. For full static-markup control, use <strong>Proxy Mode (Method 2)</strong>.</p>
        </div>

        <div class="test-section" id="test-static">
            <div class="test-header">
                <h3>🚫 Test 0 — Static &lt;script src&gt; (Known Limitation)</h3>
                <span class="badge badge-warning" id="status-static">⚠️ EXPECTED LEAK</span>
            </div>
            <div class="test-body">
                <div class="code-block"><span class="comment">// In &lt;head&gt;, after bootstrapper:</span>
<span class="keyword">&lt;script</span> <span class="string">src="https://www.googletagmanager.com/gtag/js?id=GT-TEST-STATIC"</span><span class="keyword">&gt;&lt;/script&gt;</span></div>
                <div class="info-row">
                    <span class="info-label">Expected:</span>
                    <span class="info-value">Script <strong>executes</strong> — bootstrapper cannot block parser-time static tags</span>
                </div>
                <div class="dom-status" id="dom-static">Checking...</div>
            </div>
            <div class="test-footer">
                <span>🔍 Network: gtag/js request <strong>WILL</strong> appear in DevTools</span>
                <span>📌 This proves Method 1's honest limitation</span>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="section-divider">
            <div class="section-divider-title">Dynamic Injection Tests</div>
            <div class="section-divider-desc">Each button below triggers a specific DOM API to inject a blocked script. All should be caught by the bootstrapper.</div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════
             TEST 1: createElement + appendChild
             ═══════════════════════════════════════════════════════════════ -->
        <div class="test-section" id="test-createelement">
            <div class="test-header">
                <h3>🧩 Test 1 — createElement + appendChild</h3>
                <span class="badge badge-waiting" id="status-ce">⏳ WAITING</span>
            </div>
            <div class="test-body">
                <div class="code-block"><span class="keyword">var</span> s = document.createElement(<span class="string">'script'</span>);
s.src = <span class="string">'https://www.googletagmanager.com/gtag/js?id=GT-CE-TEST'</span>;
document.head.appendChild(s);</div>
                <button class="btn btn-primary" onclick="runTest1()">▶ Run Test</button>
                <div class="dom-status" id="dom-ce">Not run yet</div>
            </div>
            <div class="test-footer">
                <span>🔍 Network: gtag/js <strong>should NOT</strong> appear after clicking</span>
                <span>✅ Caught by: src setter + appendChild patch</span>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════
             TEST 2: setAttribute('src', ...)
             ═══════════════════════════════════════════════════════════════ -->
        <div class="test-section" id="test-setattr">
            <div class="test-header">
                <h3>🔧 Test 2 — setAttribute('src', ...)</h3>
                <span class="badge badge-waiting" id="status-sa">⏳ WAITING</span>
            </div>
            <div class="test-body">
                <div class="code-block"><span class="keyword">var</span> s = document.createElement(<span class="string">'script'</span>);
s.setAttribute(<span class="string">'src'</span>, <span class="string">'https://connect.facebook.net/en_US/fbevents.js'</span>);
document.head.appendChild(s);</div>
                <button class="btn btn-primary" onclick="runTest2()">▶ Run Test</button>
                <div class="dom-status" id="dom-sa">Not run yet</div>
            </div>
            <div class="test-footer">
                <span>🔍 Network: fbevents.js <strong>should NOT</strong> appear</span>
                <span>✅ Caught by: setAttribute patch</span>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════
             TEST 3: REAL document.write() via same-origin iframe
             ═══════════════════════════════════════════════════════════════ -->
        <div class="test-section" id="test-docwrite">
            <div class="test-header">
                <h3>📝 Test 3 — document.write() <small style="color:#8b949e;font-weight:400;">(real iframe sandbox)</small></h3>
                <span class="badge badge-waiting" id="status-dw">⏳ WAITING</span>
            </div>
            <div class="test-body">
                <div class="code-block"><span class="comment">// Creates a same-origin iframe, injects the bootstrapper,</span>
<span class="comment">// then calls real document.write() with a blocked script tag.</span>
<span class="keyword">var</span> iframe = document.createElement(<span class="string">'iframe'</span>);
<span class="keyword">var</span> doc = iframe.contentDocument;
doc.open();
doc.write(<span class="string">'&lt;script src="/api/boot/..."&gt;&lt;/scr'</span> + <span class="string">'ipt&gt;'</span>);
doc.write(<span class="string">'&lt;script src="https://www.googletagmanager.com/gtag/js?id=GT-DW"&gt;&lt;/scr'</span> + <span class="string">'ipt&gt;'</span>);
doc.close();
<span class="comment">// Inspect iframe DOM for blocked elements</span></div>
                <button class="btn btn-primary" onclick="runTest3()">▶ Run Test</button>
                <div id="dw-sandbox" style="margin-top:10px;min-height:40px;background:#0d1117;border:1px solid #30363d;border-radius:8px;overflow:hidden;"></div>
                <div class="dom-status" id="dom-dw">Not run yet</div>
            </div>
            <div class="test-footer">
                <span>🔍 Network: gtag/js <strong>should NOT</strong> appear</span>
                <span>✅ Caught by: real document.write patch + filterMarkup</span>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════
             TEST 4: insertAdjacentHTML
             ═══════════════════════════════════════════════════════════════ -->
        <div class="test-section" id="test-insertadj">
            <div class="test-header">
                <h3>📎 Test 4 — insertAdjacentHTML</h3>
                <span class="badge badge-waiting" id="status-iah">⏳ WAITING</span>
            </div>
            <div class="test-body">
                <div class="code-block">document.body.insertAdjacentHTML(<span class="string">'beforeend'</span>,
  <span class="string">'&lt;script src="https://analytics.tiktok.com/i18n/pixel/events.js"&gt;&lt;/script&gt;'</span>
);</div>
                <button class="btn btn-primary" onclick="runTest4()">▶ Run Test</button>
                <div class="dom-status" id="dom-iah">Not run yet</div>
            </div>
            <div class="test-footer">
                <span>🔍 Network: tiktok events.js <strong>should NOT</strong> appear</span>
                <span>✅ Caught by: insertAdjacentHTML patch</span>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════
             TEST 5: Element.append() (modern API)
             ═══════════════════════════════════════════════════════════════ -->
        <div class="test-section" id="test-append">
            <div class="test-header">
                <h3>➕ Test 5 — Element.append()</h3>
                <span class="badge badge-waiting" id="status-ap">⏳ WAITING</span>
            </div>
            <div class="test-body">
                <div class="code-block"><span class="keyword">var</span> s = document.createElement(<span class="string">'script'</span>);
s.src = <span class="string">'https://connect.facebook.net/en_US/fbevents.js'</span>;
document.body.append(s);</div>
                <button class="btn btn-primary" onclick="runTest5()">▶ Run Test</button>
                <div class="dom-status" id="dom-ap">Not run yet</div>
            </div>
            <div class="test-footer">
                <span>🔍 Network: fbevents.js <strong>should NOT</strong> appear</span>
                <span>✅ Caught by: Element.append patch + src setter</span>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════
             TEST 6: DocumentFragment with nested script
             ═══════════════════════════════════════════════════════════════ -->
        <div class="test-section" id="test-fragment">
            <div class="test-header">
                <h3>📦 Test 6 — DocumentFragment (nested)</h3>
                <span class="badge badge-waiting" id="status-frag">⏳ WAITING</span>
            </div>
            <div class="test-body">
                <div class="code-block"><span class="keyword">var</span> frag = document.createDocumentFragment();
<span class="keyword">var</span> div = document.createElement(<span class="string">'div'</span>);
<span class="keyword">var</span> s = document.createElement(<span class="string">'script'</span>);
s.src = <span class="string">'https://analytics.tiktok.com/i18n/pixel/events.js'</span>;
div.appendChild(s);
frag.appendChild(div);
document.body.appendChild(frag);</div>
                <button class="btn btn-primary" onclick="runTest6()">▶ Run Test</button>
                <div class="dom-status" id="dom-frag">Not run yet</div>
            </div>
            <div class="test-footer">
                <span>🔍 Network: tiktok events.js <strong>should NOT</strong> appear</span>
                <span>✅ Caught by: appendChild + scanTree recursive</span>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════
             TEST 7: YouTube iframe injection
             ═══════════════════════════════════════════════════════════════ -->
        <div class="test-section" id="test-iframe">
            <div class="test-header">
                <h3>🎬 Test 7 — YouTube iframe injection</h3>
                <span class="badge badge-waiting" id="status-yt">⏳ WAITING</span>
            </div>
            <div class="test-body">
                <div class="code-block"><span class="keyword">var</span> iframe = document.createElement(<span class="string">'iframe'</span>);
iframe.src = <span class="string">'https://www.youtube.com/embed/dQw4w9WgXcQ'</span>;
document.getElementById(<span class="string">'yt-target'</span>).appendChild(iframe);</div>
                <button class="btn btn-primary" onclick="runTest7()">▶ Run Test</button>
                <div id="yt-target" style="margin-top:10px;min-height:40px;background:#0d1117;border:1px solid #30363d;border-radius:8px;overflow:hidden;"></div>
                <div class="dom-status" id="dom-yt">Not run yet</div>
            </div>
            <div class="test-footer">
                <span>🔍 Network: youtube.com/embed <strong>should NOT</strong> appear</span>
                <span>✅ Caught by: iframe src setter + appendChild</span>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════
             TEST 8: REAL Variadic document.write(a, b, c) via iframe
             ═══════════════════════════════════════════════════════════════ -->
        <div class="test-section" id="test-variadic">
            <div class="test-header">
                <h3>📝 Test 8 — Variadic document.write(a, b, c) <small style="color:#8b949e;font-weight:400;">(real iframe sandbox)</small></h3>
                <span class="badge badge-waiting" id="status-var">⏳ WAITING</span>
            </div>
            <div class="test-body">
                <div class="code-block"><span class="comment">// Creates a same-origin iframe, injects the bootstrapper,</span>
<span class="comment">// then calls real document.write() with multiple args.</span>
<span class="comment">// The blocked script is in argument #2.</span>
<span class="keyword">var</span> doc = iframe.contentDocument;
doc.open();
doc.write(
  <span class="string">'&lt;script src="/api/boot/..."&gt;&lt;/scr'</span> + <span class="string">'ipt&gt;'</span>,
  <span class="string">'&lt;div&gt;safe&lt;/div&gt;'</span>,
  <span class="string">'&lt;script src="https://connect.facebook.net/en_US/fbevents.js"&gt;&lt;/scr'</span> + <span class="string">'ipt&gt;'</span>,
  <span class="string">'&lt;div&gt;also safe&lt;/div&gt;'</span>
);
doc.close();</div>
                <button class="btn btn-primary" onclick="runTest8()">▶ Run Test</button>
                <div id="var-sandbox" style="margin-top:10px;min-height:40px;background:#0d1117;border:1px solid #30363d;border-radius:8px;overflow:hidden;"></div>
                <div class="dom-status" id="dom-var">Not run yet</div>
            </div>
            <div class="test-footer">
                <span>🔍 Network: fbevents.js <strong>should NOT</strong> appear</span>
                <span>✅ Caught by: real variadic document.write patch + filterMarkup</span>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="section-divider">
            <div class="section-divider-title">Restore / Unblock Flow</div>
            <div class="section-divider-desc">After running the tests above, this section lets you verify that blocked elements can be reactivated from their stored metadata.</div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════
             TEST 9: Restore blocked elements
             ═══════════════════════════════════════════════════════════════ -->
        <div class="test-section" id="test-restore">
            <div class="test-header">
                <h3>🔓 Test 9 — Restore After Consent</h3>
                <span class="badge badge-waiting" id="status-restore">⏳ WAITING</span>
            </div>
            <div class="test-body">
                <p style="font-size:0.85rem;color:#8b949e;margin-bottom:12px;">This scans the DOM for all <code>[data-ycookies-blocked]</code> elements, then actually restores them. After restore, blocked scripts should fire network requests and iframes should load.</p>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <button class="btn btn-success" onclick="scanBlocked()">🔍 Scan DOM for Blocked Elements</button>
                    <button class="btn btn-danger" onclick="restoreAll()" id="btn-restore-all" disabled>🔓 Restore All Blocked Elements</button>
                </div>
                <div class="dom-status" id="dom-restore" style="min-height: 80px;">Click "Scan DOM" to see blocked elements...</div>
                <div class="dom-status" id="dom-restore-result" style="min-height: 40px; margin-top: 8px; display: none;"></div>
            </div>
            <div class="test-footer">
                <span>Step 1: Scan → shows metadata. Step 2: Restore → fires real requests</span>
                <span>🔍 Network: blocked URLs <strong>should appear ONLY after</strong> Restore</span>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════ -->
        <div class="section-divider">
            <div class="section-divider-title">📊 Results Summary</div>
        </div>

        <div class="test-section" id="test-summary-section">
            <div class="test-header">
                <h3>📊 Test Results</h3>
                <button class="btn btn-primary" onclick="refreshSummary()">🔄 Refresh</button>
            </div>
            <div class="test-body">
                <div class="summary-grid" id="summary-grid">
                    <p style="color:#8b949e;">Run tests above, then click Refresh.</p>
                </div>
            </div>
        </div>

    </div>

    <!-- ═══════════════════════════════════════════════════════════════
         TEST RUNNER SCRIPTS
         ═══════════════════════════════════════════════════════════════ -->
    <script>
        window.__harness = window.__harness || {};
        var BOOT_URL = '/api/boot/{{ $siteId }}.js';

        // Check static test result
        (function(){
            var el = document.getElementById('static-gtm-test');
            var s = document.getElementById('dom-static');
            if (el) {
                var type = el.getAttribute('type');
                var blocked = el.hasAttribute('data-ycookies-blocked');
                if (blocked) {
                    s.textContent = '🛡️ Bootstrapper DID neutralize (type="' + type + '") — but parser may have already fetched. Check Network tab.';
                    document.getElementById('status-static').className = 'badge badge-warning';
                    document.getElementById('status-static').textContent = '⚠️ NEUTRALIZED LATE';
                } else {
                    s.textContent = '⚠️ Script element exists in DOM, type="' + (type || 'text/javascript') + '" — parser executed it before bootstrapper patches ran. This is the expected limitation.';
                }
            } else {
                s.textContent = '❓ Static script element not found in DOM.';
            }
        })();

        // ── Test 1: createElement + appendChild ──
        function runTest1() {
            var s = document.createElement('script');
            s.src = 'https://www.googletagmanager.com/gtag/js?id=GT-CE-TEST';
            document.head.appendChild(s);
            setTimeout(function() { checkElement(s, 'ce', 'Test 1'); }, 100);
        }

        // ── Test 2: setAttribute ──
        function runTest2() {
            var s = document.createElement('script');
            s.setAttribute('src', 'https://connect.facebook.net/en_US/fbevents.js');
            document.head.appendChild(s);
            setTimeout(function() { checkElement(s, 'sa', 'Test 2'); }, 100);
        }

        // ── Test 3: REAL document.write via same-origin iframe ──
        function runTest3() {
            var dom = document.getElementById('dom-dw');
            var status = document.getElementById('status-dw');
            dom.textContent = '⏳ Creating iframe sandbox...';

            var sandbox = document.getElementById('dw-sandbox');
            var iframe = document.createElement('iframe');
            iframe.style.cssText = 'width:100%;height:60px;border:1px solid #30363d;border-radius:4px;background:#0d1117;';
            sandbox.innerHTML = '';
            sandbox.appendChild(iframe);

            // Wait for iframe to be ready
            setTimeout(function() {
                try {
                    var idoc = iframe.contentDocument || iframe.contentWindow.document;
                    idoc.open();
                    // First: inject the bootstrapper so it patches document.write in this iframe context
                    idoc.write('<scr' + 'ipt src="' + BOOT_URL + '"></scr' + 'ipt>');
                    // Then: write a blocked script — this exercises the REAL patched document.write
                    idoc.write('<scr' + 'ipt src="https://www.googletagmanager.com/gtag/js?id=GT-DW-REAL"></scr' + 'ipt>');
                    idoc.write('<p style="color:#c9d1d9;font-family:monospace;font-size:12px;padding:8px;">Iframe sandbox for document.write test</p>');
                    idoc.close();

                    // Inspect the iframe DOM after a delay for bootstrapper to process
                    setTimeout(function() {
                        var scripts = idoc.querySelectorAll('script[src*="googletagmanager"]');
                        if (scripts.length === 0) {
                            // Script was filtered out entirely by filterMarkup
                            dom.textContent = '✅ BLOCKED — document.write filterMarkup stripped the script tag entirely (no element in iframe DOM)';
                            status.className = 'badge badge-passed';
                            status.textContent = '✅ BLOCKED';
                            window.__harness.dw = true;
                        } else {
                            var s = scripts[scripts.length - 1];
                            checkIframeElement(s, 'dw', dom, status);
                        }
                    }, 500);
                } catch (e) {
                    dom.textContent = '❌ ERROR — ' + e.message;
                    status.className = 'badge badge-blocked';
                    status.textContent = '❌ ERROR';
                    window.__harness.dw = false;
                }
            }, 100);
        }

        // ── Test 4: insertAdjacentHTML ──
        function runTest4() {
            document.body.insertAdjacentHTML('beforeend', '<scr' + 'ipt src="https://analytics.tiktok.com/i18n/pixel/events.js"></scr' + 'ipt>');
            setTimeout(function() {
                var all = document.querySelectorAll('script[src*="tiktok"]');
                var s = all[all.length - 1];
                checkElement(s, 'iah', 'Test 4');
            }, 100);
        }

        // ── Test 5: Element.append() ──
        function runTest5() {
            var s = document.createElement('script');
            s.src = 'https://connect.facebook.net/en_US/fbevents.js';
            document.body.append(s);
            setTimeout(function() { checkElement(s, 'ap', 'Test 5'); }, 100);
        }

        // ── Test 6: DocumentFragment ──
        function runTest6() {
            var frag = document.createDocumentFragment();
            var div = document.createElement('div');
            div.id = 'frag-wrapper-' + Date.now();
            var s = document.createElement('script');
            s.src = 'https://analytics.tiktok.com/i18n/pixel/events.js';
            div.appendChild(s);
            frag.appendChild(div);
            document.body.appendChild(frag);
            setTimeout(function() { checkElement(s, 'frag', 'Test 6'); }, 100);
        }

        // ── Test 7: YouTube iframe ──
        function runTest7() {
            var iframe = document.createElement('iframe');
            iframe.width = '100%';
            iframe.height = '200';
            iframe.src = 'https://www.youtube.com/embed/dQw4w9WgXcQ';
            document.getElementById('yt-target').appendChild(iframe);
            setTimeout(function() {
                var blocked = iframe.hasAttribute('data-ycookies-blocked');
                var src = iframe.src;
                var dom = document.getElementById('dom-yt');
                var status = document.getElementById('status-yt');
                if (blocked && (src === 'about:blank' || src === '')) {
                    dom.textContent = '✅ BLOCKED — src="' + src + '", data-ycookies-blocked-src="' + (iframe.getAttribute('data-ycookies-blocked-src') || 'N/A') + '"';
                    status.className = 'badge badge-passed';
                    status.textContent = '✅ BLOCKED';
                    window.__harness.iframe = true;
                } else {
                    dom.textContent = '❌ NOT BLOCKED — src="' + src + '", blocked attr: ' + blocked;
                    status.className = 'badge badge-blocked';
                    status.textContent = '❌ LEAKED';
                    window.__harness.iframe = false;
                }
            }, 200);
        }

        // ── Test 8: REAL Variadic document.write via same-origin iframe ──
        function runTest8() {
            var dom = document.getElementById('dom-var');
            var status = document.getElementById('status-var');
            dom.textContent = '⏳ Creating iframe sandbox...';

            var sandbox = document.getElementById('var-sandbox');
            var iframe = document.createElement('iframe');
            iframe.style.cssText = 'width:100%;height:60px;border:1px solid #30363d;border-radius:4px;background:#0d1117;';
            sandbox.innerHTML = '';
            sandbox.appendChild(iframe);

            setTimeout(function() {
                try {
                    var idoc = iframe.contentDocument || iframe.contentWindow.document;
                    idoc.open();
                    // Inject bootstrapper first
                    idoc.write('<scr' + 'ipt src="' + BOOT_URL + '"></scr' + 'ipt>');
                    // REAL variadic write: multiple args, blocked script in arg #3
                    idoc.write(
                        '<div style="color:#c9d1d9;font-family:monospace;font-size:12px;padding:8px;">safe content</div>',
                        '<scr' + 'ipt src="https://connect.facebook.net/en_US/fbevents.js"></scr' + 'ipt>',
                        '<div style="color:#238636;font-family:monospace;font-size:12px;padding:0 8px;">also safe</div>'
                    );
                    idoc.close();

                    setTimeout(function() {
                        var scripts = idoc.querySelectorAll('script[src*="facebook"]');
                        if (scripts.length === 0) {
                            dom.textContent = '✅ BLOCKED — variadic write filterMarkup stripped the script tag entirely';
                            status.className = 'badge badge-passed';
                            status.textContent = '✅ BLOCKED';
                            window.__harness['var'] = true;
                        } else {
                            var s = scripts[scripts.length - 1];
                            checkIframeElement(s, 'var', dom, status);
                        }

                        // Also verify safe content survived
                        var divs = idoc.querySelectorAll('div');
                        var safeCount = 0;
                        divs.forEach(function(d) { if (d.textContent.indexOf('safe') >= 0) safeCount++; });
                        if (safeCount >= 2) {
                            dom.textContent += ' | ✅ Safe divs preserved (' + safeCount + ' found)';
                        }
                    }, 500);
                } catch (e) {
                    dom.textContent = '❌ ERROR — ' + e.message;
                    status.className = 'badge badge-blocked';
                    status.textContent = '❌ ERROR';
                    window.__harness['var'] = false;
                }
            }, 100);
        }

        // ── Generic checker for main-page elements ──
        function checkElement(el, prefix, label) {
            var dom = document.getElementById('dom-' + prefix);
            var status = document.getElementById('status-' + prefix);

            if (!el) {
                dom.textContent = '❓ Element not found in DOM';
                status.className = 'badge badge-warning';
                status.textContent = '❓ NOT FOUND';
                return;
            }

            var type = el.getAttribute('type') || '';
            var blocked = el.hasAttribute('data-ycookies-blocked');
            var blockedSrc = el.getAttribute('data-ycookies-blocked-src') || '';
            var origType = el.getAttribute('data-ycookies-original-type') || '';

            if (blocked && type === 'application/ycookies-blocked') {
                dom.textContent = '✅ BLOCKED — type="' + type + '"'
                    + (blockedSrc ? ', blocked-src="' + blockedSrc + '"' : '')
                    + (origType ? ', original-type="' + origType + '"' : '')
                    + ', src="' + (el.src || 'none') + '"';
                status.className = 'badge badge-passed';
                status.textContent = '✅ BLOCKED';
                window.__harness[prefix] = true;
            } else if (blocked) {
                dom.textContent = '⚠️ PARTIALLY BLOCKED — data-ycookies-blocked="true" but type="' + type + '"';
                status.className = 'badge badge-warning';
                status.textContent = '⚠️ PARTIAL';
                window.__harness[prefix] = 'partial';
            } else {
                dom.textContent = '❌ NOT BLOCKED — type="' + (type || 'text/javascript') + '", no data-ycookies-blocked attr. Check Network tab for leaked request.';
                status.className = 'badge badge-blocked';
                status.textContent = '❌ LEAKED';
                window.__harness[prefix] = false;
            }
        }

        // ── Checker for iframe-hosted elements ──
        function checkIframeElement(el, prefix, dom, status) {
            if (!el) {
                dom.textContent = '❓ Element not found in iframe DOM';
                status.className = 'badge badge-warning';
                status.textContent = '❓ NOT FOUND';
                return;
            }

            var type = el.getAttribute('type') || '';
            var blocked = el.hasAttribute('data-ycookies-blocked');
            var blockedSrc = el.getAttribute('data-ycookies-blocked-src') || '';

            if (blocked && type === 'application/ycookies-blocked') {
                dom.textContent = '✅ BLOCKED — type="' + type + '", blocked-src="' + blockedSrc + '"';
                status.className = 'badge badge-passed';
                status.textContent = '✅ BLOCKED';
                window.__harness[prefix] = true;
            } else if (blocked) {
                dom.textContent = '⚠️ PARTIALLY BLOCKED — blocked attr present but type="' + type + '"';
                status.className = 'badge badge-warning';
                status.textContent = '⚠️ PARTIAL';
                window.__harness[prefix] = 'partial';
            } else if (type === '' || type === 'text/javascript') {
                dom.textContent = '❌ NOT BLOCKED — script exists in iframe with type="' + (type || 'text/javascript') + '", src="' + (el.src || '') + '"';
                status.className = 'badge badge-blocked';
                status.textContent = '❌ LEAKED';
                window.__harness[prefix] = false;
            } else {
                dom.textContent = '⚠️ Script has type="' + type + '" — blocked by unknown means?';
                status.className = 'badge badge-warning';
                status.textContent = '⚠️ UNKNOWN';
                window.__harness[prefix] = 'partial';
            }
        }

        // ── Scan DOM for blocked elements ──
        function scanBlocked() {
            var blocked = document.querySelectorAll('[data-ycookies-blocked]');
            var dom = document.getElementById('dom-restore');
            var status = document.getElementById('status-restore');

            if (blocked.length === 0) {
                dom.textContent = 'No blocked elements found. Run some tests first.';
                status.className = 'badge badge-waiting';
                status.textContent = '⏳ NO DATA';
                document.getElementById('btn-restore-all').disabled = true;
                return;
            }

            var lines = [];
            lines.push('Found ' + blocked.length + ' blocked element(s):\n');
            blocked.forEach(function(el, i) {
                var tag = el.tagName;
                var type = el.getAttribute('type') || '';
                var bs = el.getAttribute('data-ycookies-blocked-src') || '';
                var ot = el.getAttribute('data-ycookies-original-type') || '';
                lines.push('#' + (i+1) + ' <' + tag + '>'
                    + (type ? ' type="' + type + '"' : '')
                    + (bs ? '\n    blocked-src="' + bs + '"' : '')
                    + (ot ? '\n    original-type="' + ot + '"' : '')
                    + '\n    ➜ restorable: ' + (bs || ot ? '✅ YES' : '⚠️ missing metadata')
                );
            });

            dom.textContent = lines.join('\n');
            status.className = 'badge badge-passed';
            status.textContent = '✅ ' + blocked.length + ' FOUND';

            // Enable restore button
            document.getElementById('btn-restore-all').disabled = false;
        }

        // ── REAL Restore All blocked elements ──
        function restoreAll() {
            var blocked = document.querySelectorAll('[data-ycookies-blocked]');
            var resultDom = document.getElementById('dom-restore-result');
            resultDom.style.display = 'block';

            if (blocked.length === 0) {
                resultDom.textContent = 'No blocked elements to restore.';
                return;
            }

            var restored = 0;
            var errors = 0;
            var lines = [];

            blocked.forEach(function(el, i) {
                var tag = el.tagName;
                try {
                    if (tag === 'SCRIPT') {
                        // For scripts: clone and swap type + src back
                        var blockedSrc = el.getAttribute('data-ycookies-blocked-src') || '';
                        var origType = el.getAttribute('data-ycookies-original-type');

                        // Create a fresh script (existing scripts with changed type won't re-execute)
                        var newScript = document.createElement('script');
                        // Restore the original type (or default to text/javascript)
                        if (origType) {
                            newScript.type = origType;
                        }
                        // Set the src — this will fire a real network request
                        if (blockedSrc) {
                            newScript.src = blockedSrc;
                        }
                        newScript.setAttribute('data-ycookies-restored', 'true');

                        // Replace the blocked element
                        if (el.parentNode) {
                            el.parentNode.replaceChild(newScript, el);
                        } else {
                            document.head.appendChild(newScript);
                        }

                        lines.push('#' + (i+1) + ' <SCRIPT> restored → src="' + blockedSrc + '" (check Network tab for request)');
                        restored++;
                    } else if (tag === 'IFRAME') {
                        // For iframes: restore the original src
                        var blockedSrc = el.getAttribute('data-ycookies-blocked-src') || '';
                        if (blockedSrc) {
                            el.removeAttribute('data-ycookies-blocked');
                            el.src = blockedSrc;
                            el.setAttribute('data-ycookies-restored', 'true');
                            lines.push('#' + (i+1) + ' <IFRAME> restored → src="' + blockedSrc + '"');
                            restored++;
                        } else {
                            lines.push('#' + (i+1) + ' <IFRAME> ⚠️ no blocked-src to restore');
                            errors++;
                        }
                    } else {
                        lines.push('#' + (i+1) + ' <' + tag + '> ⚠️ unknown element type, skipped');
                        errors++;
                    }
                } catch (e) {
                    lines.push('#' + (i+1) + ' <' + tag + '> ❌ ERROR: ' + e.message);
                    errors++;
                }
            });

            lines.unshift('🔓 Restored ' + restored + ' element(s)' + (errors ? ', ' + errors + ' error(s)' : '') + ':\n');
            lines.push('\n👀 Check the Network tab — you should now see requests for the restored URLs.');
            lines.push('🎬 YouTube iframe should now render if it was blocked.');

            resultDom.textContent = lines.join('\n');

            // Update status
            var status = document.getElementById('status-restore');
            if (restored > 0 && errors === 0) {
                status.className = 'badge badge-passed';
                status.textContent = '✅ ' + restored + ' RESTORED';
            } else if (restored > 0) {
                status.className = 'badge badge-warning';
                status.textContent = '⚠️ ' + restored + '/' + (restored + errors) + ' RESTORED';
            } else {
                status.className = 'badge badge-blocked';
                status.textContent = '❌ RESTORE FAILED';
            }

            // Disable button after use
            document.getElementById('btn-restore-all').disabled = true;
        }

        // ── Summary ──
        function refreshSummary() {
            var tests = [
                { id: 'static', name: 'Static <script> (Limitation)', expected: 'leak' },
                { id: 'ce',     name: 'createElement + appendChild' },
                { id: 'sa',     name: 'setAttribute("src", ...)' },
                { id: 'dw',     name: 'document.write() (real iframe)' },
                { id: 'iah',    name: 'insertAdjacentHTML' },
                { id: 'ap',     name: 'Element.append()' },
                { id: 'frag',   name: 'DocumentFragment (nested)' },
                { id: 'iframe', name: 'YouTube iframe' },
                { id: 'var',    name: 'Variadic write (real iframe)' }
            ];

            var grid = document.getElementById('summary-grid');
            var html = '';
            var passed = 0, total = 0;

            tests.forEach(function(t) {
                var result = window.__harness[t.id];
                var icon, cls;

                if (t.expected === 'leak') {
                    icon = '⚠️'; cls = 'badge-warning';
                } else if (result === true) {
                    icon = '✅'; cls = 'badge-passed'; passed++;
                } else if (result === false) {
                    icon = '❌'; cls = 'badge-blocked';
                } else {
                    icon = '⏳'; cls = 'badge-waiting';
                }
                if (t.expected !== 'leak') total++;

                html += '<div class="summary-item">'
                    + '<span class="badge ' + cls + '">' + icon + '</span>'
                    + '<span class="name">' + t.name + '</span>'
                    + '</div>';
            });

            grid.innerHTML = '<div style="margin-bottom:12px;font-size:1rem;font-weight:700;color:#f0f6fc;grid-column:1/-1;">'
                + passed + '/' + total + ' dynamic tests passed</div>' + html;
        }
    </script>
</body>
</html>
