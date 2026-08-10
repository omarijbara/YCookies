<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YCookies Embed Blocking Test Page</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            padding: 40px 20px;
            line-height: 1.6;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 8px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .subtitle {
            color: #94a3b8;
            margin-bottom: 40px;
            font-size: 1.1rem;
        }
        .embed-section {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
        }
        .embed-section h2 {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 4px;
            color: #f1f5f9;
        }
        .embed-section .provider {
            color: #64748b;
            font-size: 0.875rem;
            margin-bottom: 16px;
        }
        .embed-section .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 8px;
        }
        .badge-video { background: #7c3aed20; color: #a78bfa; border: 1px solid #7c3aed40; }
        .badge-social { background: #3b82f620; color: #60a5fa; border: 1px solid #3b82f640; }
        .badge-map { background: #10b98120; color: #34d399; border: 1px solid #10b98140; }
        .badge-audio { background: #f59e0b20; color: #fbbf24; border: 1px solid #f59e0b40; }
        .badge-form { background: #ec489920; color: #f472b6; border: 1px solid #ec489940; }
        .embed-wrapper {
            border-radius: 12px;
            overflow: hidden;
            background: #0f172a;
        }
        .embed-wrapper iframe {
            width: 100%;
            border: none;
        }
        .status-bar {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            padding: 16px 24px;
            margin-bottom: 40px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .status-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #22c55e;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .note {
            background: #1e1b4b;
            border: 1px solid #4338ca40;
            border-radius: 10px;
            padding: 16px 20px;
            margin-top: 32px;
            font-size: 0.9rem;
            color: #a5b4fc;
        }
        .note strong { color: #c7d2fe; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧪 Embed Blocking Test Page</h1>
        <p class="subtitle">This page contains embeds from major providers. When served through the YCookies proxy, each iframe should be replaced with a consent placeholder.</p>

        <div class="status-bar">
            <div class="status-dot"></div>
            <span>If you see placeholders instead of embeds, the content blocker is working correctly.</span>
        </div>

        <!-- ═══════════════════════════════════════════════════ -->
        <!-- VIDEO EMBEDS -->
        <!-- ═══════════════════════════════════════════════════ -->

        <div class="embed-section">
            <h2>YouTube <span class="badge badge-video">Video</span></h2>
            <p class="provider">Provider: Google Ireland Limited</p>
            <div class="embed-wrapper">
                <iframe width="100%" height="400" src="https://www.youtube.com/embed/dQw4w9WgXcQ" title="YouTube video" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
            </div>
        </div>

        <div class="embed-section">
            <h2>YouTube (no-cookie) <span class="badge badge-video">Video</span></h2>
            <p class="provider">Provider: Google Ireland Limited (privacy-enhanced mode)</p>
            <div class="embed-wrapper">
                <iframe width="100%" height="400" src="https://www.youtube-nocookie.com/embed/jNQXAC9IVRw" title="YouTube no-cookie video" allowfullscreen></iframe>
            </div>
        </div>

        <div class="embed-section">
            <h2>Vimeo <span class="badge badge-video">Video</span></h2>
            <p class="provider">Provider: Vimeo.com, Inc.</p>
            <div class="embed-wrapper">
                <iframe src="https://player.vimeo.com/video/76979871" width="100%" height="400" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe>
            </div>
        </div>

        <div class="embed-section">
            <h2>Dailymotion <span class="badge badge-video">Video</span></h2>
            <p class="provider">Provider: Dailymotion SA</p>
            <div class="embed-wrapper">
                <iframe frameborder="0" width="100%" height="400" src="https://www.dailymotion.com/embed/video/x8m2v8p" allowfullscreen allow="autoplay; fullscreen; picture-in-picture"></iframe>
            </div>
        </div>

        <div class="embed-section">
            <h2>TikTok <span class="badge badge-video">Video</span></h2>
            <p class="provider">Provider: TikTok Technology Limited</p>
            <div class="embed-wrapper">
                <iframe width="100%" height="750" src="https://www.tiktok.com/embed/v2/7301234567890123456" frameborder="0" allowfullscreen></iframe>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════ -->
        <!-- SOCIAL EMBEDS -->
        <!-- ═══════════════════════════════════════════════════ -->

        <div class="embed-section">
            <h2>Twitter / X <span class="badge badge-social">Social</span></h2>
            <p class="provider">Provider: Twitter International Unlimited Company</p>
            <div class="embed-wrapper">
                <iframe width="100%" height="300" src="https://platform.twitter.com/embed/Tweet.html?id=1234567890" frameborder="0" scrolling="no"></iframe>
            </div>
        </div>

        <div class="embed-section">
            <h2>Instagram <span class="badge badge-social">Social</span></h2>
            <p class="provider">Provider: Meta Platforms Ireland Ltd.</p>
            <div class="embed-wrapper">
                <iframe width="100%" height="500" src="https://www.instagram.com/p/ABC123/embed/" frameborder="0" scrolling="no" allowtransparency="true"></iframe>
            </div>
        </div>

        <div class="embed-section">
            <h2>Facebook Video <span class="badge badge-social">Social</span></h2>
            <p class="provider">Provider: Meta Platforms Ireland Ltd.</p>
            <div class="embed-wrapper">
                <iframe src="https://www.facebook.com/plugins/video.php?href=https%3A%2F%2Fwww.facebook.com%2FFacebook%2Fvideos%2F10155278547321729%2F" width="100%" height="400" scrolling="no" frameborder="0" allowfullscreen="true" allow="autoplay; clipboard-write; encrypted-media; picture-in-picture"></iframe>
            </div>
        </div>

        <div class="embed-section">
            <h2>Reddit <span class="badge badge-social">Social</span></h2>
            <p class="provider">Provider: Reddit, Inc.</p>
            <div class="embed-wrapper">
                <iframe id="reddit-embed" src="https://www.reddit.com/r/web_design/comments/abc123/embed?ref_source=embed&ref=share&embed=true" sandbox="allow-scripts allow-same-origin allow-popups" style="border: none;" height="400" width="100%" scrolling="yes"></iframe>
            </div>
        </div>

        <div class="embed-section">
            <h2>Pinterest <span class="badge badge-social">Social</span></h2>
            <p class="provider">Provider: Pinterest, Inc.</p>
            <div class="embed-wrapper">
                <iframe src="https://assets.pinterest.com/ext/embed.html?id=123456789" height="400" width="100%" frameborder="0" scrolling="no"></iframe>
            </div>
        </div>

        <div class="embed-section">
            <h2>LinkedIn <span class="badge badge-social">Social</span></h2>
            <p class="provider">Provider: LinkedIn Ireland Unlimited Company</p>
            <div class="embed-wrapper">
                <iframe src="https://www.linkedin.com/embed/feed/update/urn:li:share:1234567890" height="400" width="100%" frameborder="0" allowfullscreen></iframe>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════ -->
        <!-- AUDIO EMBEDS -->
        <!-- ═══════════════════════════════════════════════════ -->

        <div class="embed-section">
            <h2>Spotify <span class="badge badge-audio">Audio</span></h2>
            <p class="provider">Provider: Spotify AB</p>
            <div class="embed-wrapper">
                <iframe src="https://open.spotify.com/embed/track/4uLU6hMCjMI75M1A2tKUQC" width="100%" height="352" frameBorder="0" allowfullscreen allow="autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture" loading="lazy"></iframe>
            </div>
        </div>

        <div class="embed-section">
            <h2>SoundCloud <span class="badge badge-audio">Audio</span></h2>
            <p class="provider">Provider: SoundCloud Global Limited & Co. KG</p>
            <div class="embed-wrapper">
                <iframe width="100%" height="166" scrolling="no" frameborder="no" allow="autoplay" src="https://w.soundcloud.com/player/?url=https%3A//api.soundcloud.com/tracks/123456789&color=%23ff5500&auto_play=false"></iframe>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════ -->
        <!-- MAP & UTILITY EMBEDS -->
        <!-- ═══════════════════════════════════════════════════ -->

        <div class="embed-section">
            <h2>Google Maps <span class="badge badge-map">Map</span></h2>
            <p class="provider">Provider: Google Ireland Limited</p>
            <div class="embed-wrapper">
                <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2428.123!2d13.404954!3d52.520007!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x47a84e373f035901%3A0x42120465b5e3b70!2sBrandenburger%20Tor!5e0!3m2!1sen!2sde!4v1234567890" width="100%" height="400" style="border:0;" allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
            </div>
        </div>

        <div class="embed-section">
            <h2>OpenStreetMap <span class="badge badge-map">Map</span></h2>
            <p class="provider">Provider: OpenStreetMap Foundation</p>
            <div class="embed-wrapper">
                <iframe width="100%" height="350" src="https://www.openstreetmap.org/export/embed.html?bbox=13.377%2C52.516%2C13.420%2C52.525&layer=mapnik" style="border: none;"></iframe>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════ -->
        <!-- FORM EMBEDS -->
        <!-- ═══════════════════════════════════════════════════ -->

        <div class="embed-section">
            <h2>Typeform <span class="badge badge-form">Form</span></h2>
            <p class="provider">Provider: TYPEFORM SL</p>
            <div class="embed-wrapper">
                <iframe src="https://form.typeform.com/to/abc123" width="100%" height="500" frameborder="0" marginheight="0" marginwidth="0"></iframe>
            </div>
        </div>

        <div class="embed-section">
            <h2>Calendly <span class="badge badge-form">Form</span></h2>
            <p class="provider">Provider: Calendly LLC</p>
            <div class="embed-wrapper">
                <iframe src="https://calendly.com/example-user/30min" width="100%" height="600" frameborder="0"></iframe>
            </div>
        </div>

        <div class="embed-section">
            <h2>Google reCAPTCHA <span class="badge badge-form">Security</span></h2>
            <p class="provider">Provider: Google Ireland Limited</p>
            <div class="embed-wrapper">
                <iframe src="https://www.google.com/recaptcha/api2/anchor?ar=1&k=6Lc123456" width="304" height="78" role="presentation" name="a-abc123" frameborder="0" scrolling="no" sandbox="allow-forms allow-popups allow-same-origin allow-scripts allow-top-navigation allow-modals allow-popups-to-escape-sandbox"></iframe>
            </div>
        </div>

        <div class="note">
            <strong>How to test:</strong> Serve this page through the YCookies proxy (Node) with content blockers enabled.
            Each <code>&lt;iframe&gt;</code> should be replaced by a styled placeholder with "Load this content" and "Always allow [Provider]" buttons.
            When no proxy is active, you'll see the raw embeds (or error frames for fake URLs).
        </div>
    </div>
</body>
</html>
