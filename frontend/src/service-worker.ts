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
