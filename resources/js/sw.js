// FleetQ Service Worker — Workbox-powered caching + Web Push Notifications
// This file is the source compiled by vite-plugin-pwa with InjectManifest strategy.
// Workbox injects the precache manifest at build time replacing self.__WB_MANIFEST.

import { precacheAndRoute, cleanupOutdatedCaches } from 'workbox-precaching';
import { registerRoute } from 'workbox-routing';
import { NetworkFirst, CacheFirst, NetworkOnly } from 'workbox-strategies';
import { clientsClaim } from 'workbox-core';
import { CacheableResponsePlugin } from 'workbox-cacheable-response';
import { ExpirationPlugin } from 'workbox-expiration';
import { BackgroundSyncPlugin } from 'workbox-background-sync';

// Take control of all clients as soon as the SW activates (safe — runs after skipWaiting)
clientsClaim();

// Clean up precaches from older SW versions
cleanupOutdatedCaches();

// Precache stable assets (offline.html, icons) injected by workbox at build time.
// Hashed build assets (/build/**) are NOT precached here — they are runtime-cached
// by the CacheFirst route below, avoiding stale-URL errors on incremental rebuilds.
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

// ─── Background Sync ────────────────────────────────────────────────────────
// Retry failed POST requests when connectivity is restored.
// Approval decisions and signal ingestion are queued offline and replayed on reconnect.
const approvalSyncPlugin = new BackgroundSyncPlugin('fleetq-approvals-queue', {
    maxRetentionTime: 24 * 60, // Retry for up to 24 hours (in minutes)
});
const signalSyncPlugin = new BackgroundSyncPlugin('fleetq-signals-queue', {
    maxRetentionTime: 24 * 60,
});

registerRoute(
    ({ url, request }) =>
        url.pathname.match(/^\/api\/v1\/approvals\/[^/]+\/(approve|reject)$/) &&
        request.method === 'POST',
    new NetworkOnly({ plugins: [approvalSyncPlugin] }),
    'POST'
);

registerRoute(
    ({ url, request }) =>
        url.pathname === '/api/v1/signals' && request.method === 'POST',
    new NetworkOnly({ plugins: [signalSyncPlugin] }),
    'POST'
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

// ─── Background Fetch Handlers ──────────────────────────────────────────────
// Called when a background artifact download completes (even if tab was closed)
self.addEventListener('backgroundfetchsuccess', (event) => {
    const bgFetch = event.registration;

    event.waitUntil(async function () {
        // Cache all fetched responses so the page can retrieve them
        const cache = await caches.open('fleetq-bg-downloads');
        const records = await bgFetch.matchAll();

        await Promise.all(
            records.map(async (record) => {
                const response = await record.responseReady;
                await cache.put(record.request, response);
            })
        );

        // Notify open windows that the download is available
        const allClients = await clients.matchAll({ includeUncontrolled: true });
        const artifactId = bgFetch.id.replace('artifact-', '');
        for (const client of allClients) {
            client.postMessage({ type: 'artifact-download-complete', artifactId });
        }

        await event.updateUI({ title: `${bgFetch.id} — download complete` });
    }());
});

self.addEventListener('backgroundfetchfail', (event) => {
    event.waitUntil(
        event.updateUI({ title: `Download failed — ${event.registration.id}` })
    );
});
