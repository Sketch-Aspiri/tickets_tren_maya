// PWA + Web Push. Compatible con el build CSP de Alpine: la vista solo referencia nombres y los textos
// viajan en `data-labels` (JSON plano). La llave VAPID pública viaja en <meta name="vapid-public-key">.

const JSON_HEADERS = { Accept: 'application/json', 'Content-Type': 'application/json' };

// Registra el service worker (scope: todo el sitio). Un fallo no debe romper la aplicación.
export function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) {
        return;
    }

    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

function vapidKey() {
    return document.querySelector('meta[name="vapid-public-key"]')?.content ?? '';
}

// base64url -> Uint8Array (formato que exige applicationServerKey).
function urlBase64ToUint8Array(base64Url) {
    const padding = '='.repeat((4 - (base64Url.length % 4)) % 4);
    const raw = atob((base64Url + padding).replace(/-/g, '+').replace(/_/g, '/'));

    return Uint8Array.from(raw, (char) => char.charCodeAt(0));
}

async function postJson(url, payload) {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { ...JSON_HEADERS, 'X-CSRF-TOKEN': csrfToken() },
        body: JSON.stringify(payload),
    });

    if (!response.ok) {
        throw new Error(`push:${response.status}`);
    }
}

function isIos() {
    return /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

function isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
}

export function registerPush(Alpine) {
    Alpine.data('pushToggle', () => ({
        // loading | on | off | blocked | unsupported | ios_install | missing_key
        state: 'loading',
        busy: false,
        failed: false,
        labels: {},
        // Se leen en init(): dentro de un manejador de clic `$el` es el botón, no el contenedor con los data-*.
        subscribeUrl: '',
        unsubscribeUrl: '',

        init() {
            this.subscribeUrl = this.$el.dataset.subscribeUrl ?? '';
            this.unsubscribeUrl = this.$el.dataset.unsubscribeUrl ?? '';

            try {
                this.labels = JSON.parse(this.$el.dataset.labels ?? '{}');
            } catch {
                this.labels = {};
            }

            this.detect();
        },

        async detect() {
            if (isIos() && !isStandalone()) {
                this.state = 'ios_install';

                return;
            }

            if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
                this.state = 'unsupported';

                return;
            }

            if (vapidKey() === '') {
                this.state = 'missing_key';

                return;
            }

            if (Notification.permission === 'denied') {
                this.state = 'blocked';

                return;
            }

            try {
                const registration = await navigator.serviceWorker.ready;
                const subscription = await registration.pushManager.getSubscription();
                this.state = subscription !== null && Notification.permission === 'granted' ? 'on' : 'off';
            } catch {
                this.state = 'unsupported';
            }
        },

        get canToggle() {
            return this.state === 'on' || this.state === 'off';
        },

        get isOn() {
            return this.state === 'on';
        },

        get isOff() {
            return this.state === 'off';
        },

        get statusText() {
            return this.failed ? this.labels.error : (this.labels['state_' + this.state] ?? '');
        },

        // Se pide el permiso solo tras el clic del usuario, nunca al cargar la página.
        async enable() {
            if (this.busy) {
                return;
            }

            this.busy = true;
            this.failed = false;

            try {
                const permission = await Notification.requestPermission();

                if (permission !== 'granted') {
                    this.state = permission === 'denied' ? 'blocked' : 'off';

                    return;
                }

                const registration = await navigator.serviceWorker.ready;
                const subscription = (await registration.pushManager.getSubscription())
                    ?? (await registration.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: urlBase64ToUint8Array(vapidKey()),
                    }));
                const data = subscription.toJSON();
                const encodings = PushManager.supportedContentEncodings ?? [];

                await postJson(this.subscribeUrl, {
                    endpoint: data.endpoint,
                    keys: data.keys,
                    contentEncoding: encodings.includes('aes128gcm') ? 'aes128gcm' : 'aesgcm',
                });
                this.state = 'on';
            } catch {
                this.failed = true;
            } finally {
                this.busy = false;
            }
        },

        async disable() {
            if (this.busy) {
                return;
            }

            this.busy = true;
            this.failed = false;

            try {
                const registration = await navigator.serviceWorker.ready;
                const subscription = await registration.pushManager.getSubscription();

                if (subscription !== null) {
                    await postJson(this.unsubscribeUrl, { endpoint: subscription.endpoint });
                    await subscription.unsubscribe();
                }

                this.state = 'off';
            } catch {
                this.failed = true;
            } finally {
                this.busy = false;
            }
        },
    }));
}
