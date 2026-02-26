// FleetQ Service Worker — Workbox-powered caching + Web Push Notifications
// This file is the source compiled by vite-plugin-pwa with InjectManifest strategy.
// Workbox injects the precache manifest at build time replacing self.__WB_MANIFEST.

import { precacheAndRoute, cleanupOutdatedCaches } from 'workbox-precaching';
import { registerRoute } from 'workbox-routing';
import { NetworkFirst, CacheFirst, NetworkOnly } from 'workbox-strategies';
import { clientsClaim } from 'workbox-core';
import { CacheableResponsePlugin } from 'workbox-cacheable-response';
import { ExpirationPlugin } from 'workbox-expiration';

// Take control of all clients immediately on install/update
self.skipWaiting();
clientsClaim();

// Clean up precaches from older SW versions
cleanupOutdatedCaches();

// Precache all assets listed in the Workbox manifest (injected by vite-plugin-pwa)
precacheAndRoute(self.__WB_MANIFEST);

// ─── Routing Strategies ────────────────────────────────────────────────────

// Livewire component XHR — always network, never cache (stale responses break UI)
registerRoute(
    ({ url }) => url.pathname.startsWith('/livewire/'),
    new NetworkOnly()
);

// API calls — always network (Sanctum-scoped, auth-sensitive)
registerRoute(
    ({ url }) => url.pathname.startsWith('/api/'),
    new NetworkOnly()
);

// App navigation (HTML pages) — NetworkFirst with 3s timeout, offline fallback
registerRoute(
    ({ request }) => request.mode === 'navigate',
    new NetworkFirst({
        cacheName: 'fleetq-pages',
        networkTimeoutSeconds: 3,
        plugins: [
            {
                // If network fails and no cached version exists, serve offline page
                handlerDidError: async () => {
                    const cache = await caches.open('fleetq-offline');
                    return cache.match('/offline.html') ?? Response.error();
                },
            },
            new CacheableResponsePlugin({ statuses: [200] }),
        ],
    })
);

// Vite hashed build assets — CacheFirst (hash in URL = immutable content)
registerRoute(
    ({ url }) => url.pathname.startsWith('/build/'),
    new CacheFirst({
        cacheName: 'fleetq-build-assets',
        plugins: [
            new CacheableResponsePlugin({ statuses: [200] }),
            new ExpirationPlugin({ maxAgeSeconds: 365 * 24 * 60 * 60 }),
        ],
    })
);

// Fonts CDN (fonts.bunny.net) — CacheFirst, long expiry
registerRoute(
    ({ url }) => url.hostname.includes('bunny.net'),
    new CacheFirst({
        cacheName: 'fleetq-fonts',
        plugins: [
            new CacheableResponsePlugin({ statuses: [0, 200] }),
            new ExpirationPlugin({ maxAgeSeconds: 365 * 24 * 60 * 60 }),
        ],
    })
);

// ─── Offline Page Pre-cache ─────────────────────────────────────────────────
// Ensure /offline.html is always cached separately so the fallback handler finds it
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open('fleetq-offline').then((cache) => cache.add('/offline.html'))
    );
});

// ─── SW Update Message ──────────────────────────────────────────────────────
// Allow the page to trigger a SW update via postMessage
self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

// ─── Web Push Notifications ─────────────────────────────────────────────────

self.addEventListener('push', function (event) {
    if (!event.data) return;

    let data = {};
    try {
        data = event.data.json();
    } catch (e) {
        data = { title: 'FleetQ', body: event.data.text() };
    }

    const title = data.title || 'FleetQ';
    const options = {
        body: data.body || '',
        icon: data.icon || '/icons/icon-192.png',
        badge: '/icons/badge-72x72.png',
        data: data.data || {},
        actions: data.actions || [],
        vibrate: [200, 100, 200],
        requireInteraction: false,
        tag: data.data?.type || 'fleetq-notification',
        renotify: true,
    };

    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    const url = event.notification.data?.url || '/notifications';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            for (const client of clientList) {
                if (client.url.includes(self.location.origin) && 'focus' in client) {
                    client.navigate(url);
                    return client.focus();
                }
            }
            if (clients.openWindow) {
                return clients.openWindow(url);
            }
        })
    );
});
