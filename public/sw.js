/*
 * Service worker for the installed app.
 *
 * Three deliberate strategies, because the three kinds of request have nothing
 * in common:
 *   - build assets are content-hashed, so they can be cached forever;
 *   - recipe photos come from other people's servers and are the bulk of the
 *     traffic, so they are cached but capped;
 *   - pages must be fresh, because two people share one account and one of them
 *     may have changed something — the cache is only a fallback for being offline.
 */

// v3: the cooking timer's alarm. Bumped so an installed phone picks up the
// message handler rather than keeping a worker that ignores it.
const VERSION = 'v3';
const SHELL_CACHE = `kuchnia-shell-${VERSION}`;
const ASSET_CACHE = `kuchnia-assets-${VERSION}`;
const IMAGE_CACHE = `kuchnia-images-${VERSION}`;
const PAGE_CACHE = `kuchnia-pages-${VERSION}`;

const OFFLINE_URL = '/offline.html';

/** Recipe photos alone would fill the disk: ~2650 recipes, one image each. */
const MAX_CACHED_IMAGES = 300;
const MAX_CACHED_PAGES = 60;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(SHELL_CACHE)
            .then((cache) => cache.addAll([OFFLINE_URL]))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    const keep = [SHELL_CACHE, ASSET_CACHE, IMAGE_CACHE, PAGE_CACHE];

    event.waitUntil(
        caches
            .keys()
            .then((names) =>
                Promise.all(
                    names
                        .filter((name) => !keep.includes(name))
                        .map((name) => caches.delete(name)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

/**
 * Keeps a cache from growing without limit by dropping the oldest entries.
 * `keys()` returns insertion order, so the front of the list is the oldest.
 */
async function trim(cacheName, maxEntries) {
    const cache = await caches.open(cacheName);
    const keys = await cache.keys();

    if (keys.length <= maxEntries) {
        return;
    }

    await Promise.all(
        keys.slice(0, keys.length - maxEntries).map((key) => cache.delete(key)),
    );
}

async function cacheFirst(request, cacheName, maxEntries) {
    const cache = await caches.open(cacheName);
    const cached = await cache.match(request);

    if (cached) {
        return cached;
    }

    const response = await fetch(request);

    // Opaque cross-origin responses are cached too: recipe photos are served
    // without CORS headers and would otherwise never be available offline.
    if (response.ok || response.type === 'opaque') {
        await cache.put(request, response.clone());

        if (maxEntries) {
            await trim(cacheName, maxEntries);
        }
    }

    return response;
}

async function networkFirst(request) {
    const cache = await caches.open(PAGE_CACHE);

    try {
        const response = await fetch(request);

        if (response.ok) {
            await cache.put(request, response.clone());
            await trim(PAGE_CACHE, MAX_CACHED_PAGES);
        }

        return response;
    } catch {
        const cached = await cache.match(request);

        if (cached) {
            return cached;
        }

        return (await caches.match(OFFLINE_URL)) ?? Response.error();
    }
}

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // Inertia's own XHR must never be served stale: it carries the data the two
    // of us are looking at, and a cached answer would show one of us the past.
    if (request.headers.get('X-Inertia')) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(networkFirst(request));

        return;
    }

    if (url.origin === self.location.origin && url.pathname.startsWith('/build/')) {
        event.respondWith(cacheFirst(request, ASSET_CACHE));

        return;
    }

    if (request.destination === 'image') {
        event.respondWith(cacheFirst(request, IMAGE_CACHE, MAX_CACHED_IMAGES));
    }
});

/*
 * The cooking timer's alarm.
 *
 * The page owns the clock — it holds a deadline and recomputes what is left, so
 * it is right whether or not anything here ever runs. This exists for the one
 * thing a page cannot do: tell you the water is boiling while the app is in the
 * background and the screen is off.
 *
 * It is best effort by nature. A phone shuts idle workers down, and this one has
 * no way to ask to be woken (Notification Triggers never shipped beyond a flag,
 * and there is no push server here — a private two-person app is not worth one).
 * So: a timeout while the worker happens to be alive, and the page shows the
 * finished timer the moment it is looked at again. Nothing is ever lost, because
 * neither of those is where the time is kept.
 */
let alarm = null;

function clearAlarm() {
    if (alarm !== null) {
        clearTimeout(alarm);
        alarm = null;
    }
}

async function ringAlarm(body, url) {
    clearAlarm();

    // Silent if a window is already showing the timer: the page rings, vibrates
    // and says so on screen, and two alarms for one pot is one too many.
    const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    const watching = clients.some((client) => client.visibilityState === 'visible');

    if (watching) {
        return;
    }

    await self.registration.showNotification('Minutnik skończył', {
        body,
        icon: '/icons/icon-192.png',
        badge: '/icons/icon-192.png',
        tag: 'kuchnia-cook-timer',
        renotify: true,
        requireInteraction: true,
        vibrate: [300, 120, 300],
        data: { url },
    });
}

self.addEventListener('message', (event) => {
    const data = event.data ?? {};

    if (data.type === 'cook-timer:clear') {
        clearAlarm();

        return;
    }

    if (data.type !== 'cook-timer:set') {
        return;
    }

    clearAlarm();

    const delay = data.endsAt - Date.now();

    if (delay <= 0) {
        event.waitUntil(ringAlarm(data.body, data.url));

        return;
    }

    alarm = setTimeout(() => {
        void ringAlarm(data.body, data.url);
    }, delay);
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = event.notification.data?.url ?? '/';

    // Back to the pot, not to a second copy of the app: an already-open window
    // is focused and steered there.
    event.waitUntil(
        self.clients
            .matchAll({ type: 'window', includeUncontrolled: true })
            .then((clients) => {
                const open = clients.find((client) => client.url.includes(url));

                if (open) {
                    return open.focus();
                }

                return self.clients.openWindow(url);
            }),
    );
});
