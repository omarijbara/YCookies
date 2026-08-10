import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/manager.js', 'resources/css/filament/admin/theme.css'],
            refresh: true,
        }),
        tailwindcss(),
        VitePWA({
            registerType: 'autoUpdate',
            devOptions: { enabled: true },
            workbox: {
                // Remove html to avoid grabbing backend Blade shells
                globPatterns: ['**/*.{js,css,png,ico}'],
                runtimeCaching: [{
                    // Cache the core consent-config API responses for returning users off-grid
                    urlPattern: /^https:\/\/[^\/]+\/api\/v1\/consent-config/,
                    handler: 'NetworkFirst',
                    options: {
                        cacheName: 'consent-configs',
                        expiration: { maxEntries: 50 },
                    }
                }]
            }
        })
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
    build: {
        minify: 'esbuild',
        rollupOptions: {
            output: {
                manualChunks: (id) => {
                    if (id.includes('resources/js/manager.js') || id.includes('@iabtechlabtcf')) {
                        return 'manager-bundle';
                    }
                }
            }
        }
    }
});
