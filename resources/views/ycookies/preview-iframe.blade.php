<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ in_array(app()->getLocale(), ['ar', 'he', 'fa', 'ur']) ? 'rtl' : 'ltr' }}">

<head>
    <meta name="viewport" content="width=device-width">
    <!-- Signal to SDK that we are in Preview Mode BEFORE it loads -->
    <script>window.YCookiesPreviewMode = true;</script>
    <!-- Universal pixel interception — intercepts ALL tracking pixels -->
    @include('ycookies.partials.universal-interceptor')
    <!-- Load the universal tag scripts directly from Vite -->
    @vite(['resources/css/app.css', 'resources/js/manager.js'])
</head>

<body style="margin:0; height:100vh; overflow:hidden; background:
    radial-gradient(circle at 20px 20px, rgba(148, 163, 184, 0.15) 1px, transparent 1px);
    background-size: 24px 24px;
    background-color: #f1f5f9;">
    <div id="ycookies-preview-canvas" style="position:absolute; inset:0;"></div>

    <script>
        const initPreview = setInterval(() => {
            if (window.YCookiesManager) {
                clearInterval(initPreview);
                window.YCookies = new window.YCookiesManager();
                window.YCookies.config = {{ Js::from($config) }};

                // Check if there's an override config from the admin form (sessionStorage)
                try {
                    const override = sessionStorage.getItem('ycookies_preview_override');
                    if (override) {
                        const data = JSON.parse(override);
                        if (data && data.ui_config) {
                            window.YCookies.config.ui_config = data.ui_config;
                        }
                        if (data && data.translations) {
                            window.YCookies.config.translations = data.translations;
                        }
                        // Don't remove — keep it for reloads within the same session.
                        // sessionStorage is automatically cleared when the window/tab closes.
                    }
                } catch (e) {
                    console.warn('[YCookies] Could not read preview override:', e);
                }

                // Boot the SDK in preview mode
                window.YCookies.initPreviewMode();
                
                // Notify parent debugger
                window.parent.postMessage({ type: 'ycookies_preview_ready' }, '*');
            }
        }, 50);
    </script>
</body>

</html>
