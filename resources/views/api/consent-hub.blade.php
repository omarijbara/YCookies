<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YCookies Consent Hub</title>
    <meta name="robots" content="noindex, nofollow">
</head>
<body>
    <script>
        (function() {
            var allowedOrigins = {{ Js::from($allowedOrigins) }};
            var storageKey = 'ycookies_consent_hub_{{ $groupId }}';

            window.addEventListener('message', function(event) {
                // Security check: Must be a known domain in the same tenant group
                if (!allowedOrigins.includes(event.origin)) {
                    return;
                }

                var data = event.data;
                if (!data || typeof data !== 'object' || data.type !== 'ycookies_sync') {
                    return;
                }

                if (data.action === 'write') {
                    localStorage.setItem(storageKey, JSON.stringify(data.payload));
                    // Optional acknowledgment
                    event.source.postMessage({ type: 'ycookies_sync_ack', action: 'write' }, event.origin);
                } else if (data.action === 'read') {
                    var stored = localStorage.getItem(storageKey);
                    event.source.postMessage({
                        type: 'ycookies_sync_response',
                        payload: stored ? JSON.parse(stored) : null
                    }, event.origin);
                }
            });

            // Let the parent know the hub is ready to receive messages
            window.parent.postMessage({ type: 'ycookies_hub_ready' }, '*');
        })();
    </script>
</body>
</html>
