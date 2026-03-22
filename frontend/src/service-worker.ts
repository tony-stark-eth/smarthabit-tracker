/// <reference types="@sveltejs/kit" />
/// <reference no-default-lib="true"/>
/// <reference lib="esnext" />
/// <reference lib="webworker" />

import { build, files, version } from '$service-worker';

declare const self: ServiceWorkerGlobalScope;

const CACHE_NAME = `smarthabit-${version}`;
const ASSETS = [...build, ...files];

// Install: cache all static assets
self.addEventListener('install', (event: ExtendableEvent) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(ASSETS))
    );
});

// Activate: clean old caches
self.addEventListener('activate', (event: ExtendableEvent) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                keys
                    .filter((key) => key !== CACHE_NAME)
                    .map((key) => caches.delete(key))
            )
        )
    );
});

// Fetch: cache-first for assets, network-first for API
self.addEventListener('fetch', (event: FetchEvent) => {
    const url = new URL(event.request.url);

    // Skip non-GET and API requests
    if (event.request.method !== 'GET') return;
    if (url.pathname.startsWith('/api/')) return;

    event.respondWith(
        caches.match(event.request).then((cached) => {
            return cached ?? fetch(event.request);
        })
    );
});

// Web Push notification handler
self.addEventListener('push', (event: PushEvent) => {
    if (!event.data) return;

    const data = event.data.json() as { title: string; body: string; habitId: string };

    event.waitUntil(
        self.registration.showNotification(data.title, {
            body: data.body,
            icon: '/icons/icon-192.png',
            data: { habitId: data.habitId, url: `/?log=${data.habitId}` },
            actions: [
                { action: 'log', title: 'Done ✓' },
                { action: 'dismiss', title: 'Later' },
            ],
        })
    );
});

// Notification click — open app, optionally auto-log
self.addEventListener('notificationclick', (event: NotificationEvent) => {
    event.notification.close();

    const { url } = event.notification.data as { habitId: string; url: string };

    event.waitUntil(
        self.clients.matchAll({ type: 'window' }).then((clients) => {
            // Focus existing window if open
            for (const client of clients) {
                if (client.url.includes('/') && 'focus' in client) {
                    return client.focus();
                }
            }
            // Otherwise open new window
            return self.clients.openWindow(url);
        })
    );
});
