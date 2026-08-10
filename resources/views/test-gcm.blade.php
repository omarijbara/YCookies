<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>GCM Test Page</title>
    <!-- Explicitly set the config that manager.js expects, simulating the proxy injection -->
    <script>
        window.ycookies_config = {
            id: 'test-site-id',
            settings: {
                trigger_mode: 'interaction',
                overlay: { layout: 'default' },
                advanced: { 
                    tcf_enabled: false
                }
            },
            appearance: {
                colors: { primary: '#000', background: '#fff', text: '#000' }
            },
            content: {
                title: 'Test Consent',
                description: 'Please accept.',
                buttons: { accept_all: 'Accept All', save: 'Save', settings: 'Settings' }
            },
            services: [
                { id: 'google_analytics', group: 'statistics', name: 'Google Analytics' }
            ],
            groups: [
                { id: 'statistics', title: 'Statistics' }
            ],
            tcm_config: {
                enabled: true,
                advanced_consent_mode: false,
                mapping: {
                    marketing: ['ad_storage', 'ad_user_data', 'ad_personalization', 'personalization_storage'],
                    statistics: ['analytics_storage', 'functionality_storage', 'security_storage']
                },
                has_google_services: true,
                regional_defaults: []
            }
        };
    </script>
    @vite(['resources/js/manager.js'])
</head>
<body>
    <h1>Local GCM Test Environment</h1>
    <p>Move mouse or press key to trigger interaction.</p>
</body>
</html>
