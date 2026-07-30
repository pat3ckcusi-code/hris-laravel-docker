// HRIS service worker — conservative by design: this is a session-authenticated
// MPA with sensitive HR data, so navigations are never cached (network-first,
// falling back to a static offline page). Only content-hashed /build/assets/
// files are cached-first. Everything else (cross-origin, non-GET, API/JSON)
// falls through to default browser networking untouched.

const VERSION = 'v1';
const STATIC_CACHE = `hris-static-${VERSION}`;
const OFFLINE_URL = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE).then((cache) => {
            return cache.add(new Request(OFFLINE_URL, { cache: 'reload' }));
        }).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys.filter((key) => key !== STATIC_CACHE).map((key) => caches.delete(key))
            );
        }).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE_URL))
        );
        return;
    }

    if (url.pathname.startsWith('/build/assets/')) {
        event.respondWith(
            caches.match(request).then((cached) => {
                if (cached) {
                    return cached;
                }

                return fetch(request).then((response) => {
                    if (response.ok) {
                        const copy = response.clone();
                        caches.open(STATIC_CACHE).then((cache) => cache.put(request, copy));
                    }

                    return response;
                });
            })
        );
    }
});

// App-icon badge count. The Badging API is set/clear only (no read-back), and
// this service worker is ephemeral (killed after ~30s idle), so the running
// count has to be persisted here rather than kept in memory or it would reset
// to 0 — and each new push would overwrite the badge with 1 instead of
// incrementing — every time the worker restarts.
const BADGE_DB = 'hris-badge';
const BADGE_STORE = 'meta';
const BADGE_KEY = 'count';

function openBadgeDb() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(BADGE_DB, 1);
        request.onupgradeneeded = () => {
            request.result.createObjectStore(BADGE_STORE);
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

async function getBadgeCount() {
    const db = await openBadgeDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(BADGE_STORE, 'readonly');
        const request = tx.objectStore(BADGE_STORE).get(BADGE_KEY);
        request.onsuccess = () => resolve(request.result || 0);
        request.onerror = () => reject(request.error);
    });
}

async function setBadgeCount(count) {
    const db = await openBadgeDb();
    return new Promise((resolve, reject) => {
        const tx = db.transaction(BADGE_STORE, 'readwrite');
        tx.objectStore(BADGE_STORE).put(count, BADGE_KEY);
        tx.oncomplete = () => resolve();
        tx.onerror = () => reject(tx.error);
    });
}

async function incrementBadge() {
    const count = (await getBadgeCount()) + 1;
    await setBadgeCount(count);

    if ('setAppBadge' in navigator) {
        try {
            await navigator.setAppBadge(count);
        } catch (e) {
            // Unsupported/unavailable in this context — no-op.
        }
    }
}

async function clearBadge() {
    await setBadgeCount(0);

    if ('clearAppBadge' in navigator) {
        try {
            await navigator.clearAppBadge();
        } catch (e) {
            // Unsupported/unavailable in this context — no-op.
        }
    }
}

self.addEventListener('push', (event) => {
    let payload = { title: '[HRIS] Notification', body: '' };

    if (event.data) {
        try {
            payload = event.data.json();
        } catch (e) {
            payload.body = event.data.text();
        }
    }

    const title = payload.title || '[HRIS] Notification';
    const options = {
        body: payload.body || '',
        icon: '/icons/icon-192.png',
        badge: '/icons/icon-192.png',
        data: { url: (payload.data && payload.data.url) || '/dashboard' },
    };

    event.waitUntil(
        Promise.all([self.registration.showNotification(title, options), incrementBadge()])
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const targetUrl = (event.notification.data && event.notification.data.url) || '/dashboard';

    event.waitUntil(
        Promise.all([
            clearBadge(),
            self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
                for (const client of clients) {
                    if (client.url.includes(self.location.origin) && 'focus' in client) {
                        client.navigate(targetUrl);
                        return client.focus();
                    }
                }

                return self.clients.openWindow(targetUrl);
            }),
        ])
    );
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'CLEAR_BADGE') {
        event.waitUntil(clearBadge());
    }
});
