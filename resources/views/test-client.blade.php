<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YCookies Test Client</title>
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Simulate the Client Website pasting our Single-Line Embed Tag into their <head> -->
    <script src="/api/script/{{ $siteId }}.js" data-ycookies-id="{{ $siteId }}" id="ycookies-manager" defer></script>
</head>

<body class="bg-gray-100 p-8">
    <div class="max-w-4xl mx-auto bg-white p-6 rounded-lg shadow-sm">
        <h1 class="text-3xl font-bold mb-4">Simulated Client Website</h1>
        <p class="text-gray-600 mb-8">This page simulates a third-party website that has embedded the YCookies script tag.</p>

        <div class="grid grid-cols-2 gap-4">
            <div class="bg-blue-50 p-4 rounded border border-blue-100">
                <h3 class="font-bold text-blue-800">Mock Analytics Script</h3>
                <p class="text-xs text-blue-600 mb-2">Should be blocked until consent</p>
                <div id="analytics-status" class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-red-100 text-red-800">
                    Not Loaded
                </div>

                <!-- This script represents a tracker. It MUST be marked as text/plain and specify its category -->
                <script type="text/plain" data-category="statistics">
                    console.log('Mock Analytics Loaded! Consent was granted.');
                    document.getElementById('analytics-status').classList.replace('bg-red-100', 'bg-green-100');
                    document.getElementById('analytics-status').classList.replace('text-red-800', 'text-green-800');
                    document.getElementById('analytics-status').innerText = 'Loaded';
                </script>
            </div>

            <div class="bg-red-50 p-4 rounded border border-red-100">
                <h3 class="font-bold text-red-800">Mock YouTube Iframe</h3>
                <p class="text-xs text-red-600 mb-2">Should be paused until consent</p>
                <!-- This iframe represents a content blocker target. It MUST use data-ycookies-src instead of src -->
                <iframe width="100%" height="200" data-ycookies-src="https://www.youtube.com/embed/dQw4w9WgXcQ" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
            </div>
        </div>

        <div class="mt-4 bg-indigo-50 p-4 rounded border border-indigo-100">
            <h3 class="font-bold text-indigo-800">Mock Google Tag Manager (GTM)</h3>
            <p class="text-xs text-indigo-600 mb-2">Should be blocked until "Marketing" consent is granted.</p>
            <div id="gtm-status" class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-red-100 text-red-800">
                Not Loaded
            </div>

            <!-- GTM Script Representation MUST be type="text/plain" and specify data-category -->
            <script type="text/plain" data-category="marketing">
                console.log('Mock GTM Loaded! Marketing consent was granted.');
                document.getElementById('gtm-status').classList.replace('bg-red-100', 'bg-green-100');
                document.getElementById('gtm-status').classList.replace('text-red-800', 'text-green-800');
                document.getElementById('gtm-status').innerText = 'Loaded (GTM Initialized)';
                
                // Real GTM code would be here:
                // (function(w,d,s,l,i){w[l]=...
            </script>
        </div>

        <div class="mt-8 bg-gray-50 border border-gray-200 p-4 rounded">
            <h3 class="font-bold text-gray-800 border-b border-gray-200 pb-2 mb-2">Advanced: GTM DataLayer Log</h3>
            <p class="text-xs text-gray-600 mb-2">Watch <code class="bg-gray-200 px-1 rounded">window.dataLayer</code> populate with Borlabs-style events when you accept cookies.</p>
            <pre id="datalayer-log" class="text-xs font-mono bg-black text-green-400 p-3 rounded h-32 overflow-y-auto"></pre>
        </div>
        
        <div class="mt-8 bg-white border border-gray-200 p-6 rounded shadow-sm">
            <h3 class="text-xl font-bold text-gray-800 border-b border-gray-200 pb-2 mb-4">Privacy Policy: Your Privacy Preferences</h3>
            <p class="text-sm text-gray-600 mb-4">This section demonstrates how clients can embed a dynamic list of accepted cookies directly into their Privacy Policy page simply by copying and pasting a single <code>&lt;div id="ycookies-accepted-list"&gt;&lt;/div&gt;</code> tag.</p>
            
            <!-- This is the hook for manager.js to render the cookie table -->
            <div id="ycookies-accepted-list">
                <p class="text-gray-500 italic text-sm">Loading your cookie preferences...</p>
            </div>
        </div>
    </div>

    <!-- UI Helper to visualize DataLayer changes -->
    <script>
        window.dataLayer = window.dataLayer || [];
        const originalPush = window.dataLayer.push.bind(window.dataLayer);
        window.dataLayer.push = function() {
            originalPush.apply(this, arguments);
            const logBox = document.getElementById('datalayer-log');
            if (logBox) {
                Array.from(arguments).forEach(arg => {
                    logBox.innerHTML += JSON.stringify(arg) + '\n';
                });
                logBox.scrollTop = logBox.scrollHeight;
            }
        };
    </script>
</body>

</html>