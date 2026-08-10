<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Google Signals Consent Test</title>
    <style>
        #network-log {
            position: fixed;
            top: 20px;
            left: 20px;
            width: 400px;
            height: calc(100vh - 40px);
            background: rgba(0,0,0,0.8);
            color: #0f0;
            font-family: monospace;
            z-index: 999999;
            padding: 15px;
            overflow-y: auto;
            border-radius: 8px;
            pointer-events: none;
        }
        .log-entry { margin-bottom: 10px; border-bottom: 1px solid #333; padding-bottom: 5px; }
        .gcs-value { font-weight: bold; color: yellow; font-size: 1.2em; }
    </style>
    
    <script>
        window.networkLogs = [];
        
        // Intercept XMLHttpRequest
        var origOpen = XMLHttpRequest.prototype.open;
        XMLHttpRequest.prototype.open = function(method, url) {
            this.addEventListener('load', function() {
                checkUrl(url);
            });
            origOpen.apply(this, arguments);
        };

        // Intercept fetch
        var origFetch = window.fetch;
        window.fetch = async function() {
            var url = arguments[0];
            checkUrl(url);
            return origFetch.apply(this, arguments);
        };
        
        // Intercept Image Beacons
        const observer = new PerformanceObserver((list) => {
            for (const entry of list.getEntries()) {
                if (entry.name.includes('collect?v=')) checkUrl(entry.name);
            }
        });
        observer.observe({entryTypes: ['resource']});

        function checkUrl(url) {
            if (typeof url === 'string' && url.includes('collect?v=')) {
                var match = url.match(/[?&]gcs=(G1[01]{2})/);
                var gcs = match ? match[1] : "NOT_FOUND";
                addLog("GA4 hit 'collect?v=2'", gcs);
            }
        }
        
        window.dataLayer = window.dataLayer || [];
        var oldPush = window.dataLayer.push;
        window.dataLayer.push = function() {
            var args = [].slice.call(arguments);
            args.forEach(function(arg) {
                if(arg && arg.event === "consent_update") {
                    addLog("dataLayer 'consent_update'", "ad_storage:" + arg.ad_storage + ", analytics_storage:" + arg.analytics_storage);
                }
                if(arg && arg[0] === "consent" && arg[1] === "update") {
                    addLog("gtag('consent', 'update')", "ad_storage:" + arg[2].ad_storage + ", analytics_storage:" + arg[2].analytics_storage);
                }
            });
            return oldPush.apply(this, arguments);
        };

        let counter = 1;
        function addLog(source, gcs) {
            var div = document.getElementById('network-log');
            if(div) {
                var entry = document.createElement('div');
                entry.className = 'log-entry';
                entry.innerHTML = `<strong>#${counter++}</strong> [${new Date().toLocaleTimeString()}]<br/>${source}<br/>Status: <span class="gcs-value">` + gcs + `</span>`;
                div.prepend(entry);
                window.networkLogs.push(gcs);
            }
        }
    </script>
</head>
<body>
    <div id="network-log">
        <h2>Network Consent Log</h2>
        <div id="status">Waiting for GA4 requests...</div>
    </div>
    
    <h1>Test Site for YCookies</h1>
    <p>Script URL: {{ $jsUrl }}</p>
    
    <script src="{{ $jsUrl }}"></script>
    
</body>
</html>
