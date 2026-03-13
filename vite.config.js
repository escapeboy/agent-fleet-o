import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/terminal.js', 'resources/js/push-notifications.js', 'resources/js/pwa-features.js'],
            refresh: true,
        }),
        tailwindcss(),
        VitePWA({
            strategies: 'injectManifest',
            srcDir: 'resources/js',
            filename: 'sw.js',
            outDir: 'public',
            injectRegister: null,
            // Disable manifest injection — sw.js uses precacheAndRoute([]) intentionally
            injectManifest: { swSrc: 'resources/js/sw.js', swDest: 'public/sw.js', injectionPoint: undefined },
        }),
    ],
    server: {
        origin: 'http://localhost:5174',
        cors: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
