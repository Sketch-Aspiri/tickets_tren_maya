// Service worker del sistema de tickets (PWA + Web Push).
//
// Seguridad: NO se cachea ninguna respuesta autenticada ni dinamica (paginas, JSON, adjuntos, avatares).
// Solo se guardan la pagina de "sin conexion", los iconos y los assets versionados de /build/ (nombres con
// hash, sin datos de usuario). Subir la version invalida la cache anterior.
const VERSION = 'v3';

// Sistemas del ecosistema a los que puede llevar una notificacion (ademas de este sitio). Lista exacta, sin
// comodines: un subdominio de terceros no debe abrirse desde un aviso del sistema. Mantenerla igual a
// config('ecosistema.urls') y cambiarla junto con VERSION si cambian los dominios.
const ECOSYSTEM_HOSTS = ['tickets.zonaorientetm.tech', 'dashboard.zonaorientetm.tech'];
const CACHE = `tickets-static-${VERSION}`;
const OFFLINE_URL = '/offline.html';
const PRECACHE = [OFFLINE_URL, '/icons/icon-192.png', '/icons/icon-512.png'];
const DEFAULT_ICON = '/icons/icon-192.png';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    // Navegaciones: siempre red (contenido autenticado); si no hay conexion, pagina offline.
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));

        return;
    }

    // Assets versionados por Vite (hash en el nombre): cache primero.
    if (url.pathname.startsWith('/build/assets/')) {
        event.respondWith(
            caches.match(request).then((cached) => cached || fetch(request).then((response) => {
                if (response.ok) {
                    const copy = response.clone();
                    caches.open(CACHE).then((cache) => cache.put(request, copy));
                }

                return response;
            })),
        );
    }
    // Todo lo demas (API, JSON, adjuntos, avatares) pasa directo a la red, sin cache.
});

self.addEventListener('push', (event) => {
    let payload = {};

    try {
        payload = event.data ? event.data.json() : {};
    } catch {
        payload = { body: event.data ? event.data.text() : '' };
    }

    const title = typeof payload.title === 'string' && payload.title !== '' ? payload.title : 'Tren Maya Tickets';
    const options = {
        body: typeof payload.body === 'string' ? payload.body : '',
        icon: payload.icon || DEFAULT_ICON,
        badge: payload.badge || DEFAULT_ICON,
        tag: payload.tag || undefined,
        data: { url: payload.data && payload.data.url ? payload.data.url : '/' },
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    // Solo se abren URLs de este sitio o de otro sistema del ecosistema (https, sin puerto, host de la lista): el
    // payload es dato no confiable aunque lo mande nuestro servidor, que ya valida el destino al enviarlo.
    let target = new URL('/', self.location.origin);

    try {
        const candidate = new URL((event.notification.data && event.notification.data.url) || '/', self.location.origin);

        const sameSite = candidate.origin === self.location.origin;
        const ecosystem = candidate.protocol === 'https:' && candidate.port === '' && !candidate.username && !candidate.password
            && ECOSYSTEM_HOSTS.includes(candidate.hostname);

        if (sameSite || ecosystem) {
            target = candidate;
        }
    } catch {
        // URL invalida: se queda el inicio.
    }

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            for (const client of windows) {
                if (new URL(client.url).origin === target.origin && 'focus' in client) {
                    return client.focus().then((focused) => ('navigate' in focused ? focused.navigate(target.href) : focused));
                }
            }

            return self.clients.openWindow(target.href);
        }),
    );
});
