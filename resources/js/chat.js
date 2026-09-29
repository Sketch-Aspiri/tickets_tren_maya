// Chat interno por polling (sin WebSockets). Alpine build CSP: la logica vive aqui y las vistas solo
// referencian nombres; los datos del servidor viajan en atributos `data-*`. El HTML de los mensajes lo
// genera y escapa el servidor (fragmentos Blade); el cliente solo lo inserta.
const POLL_MS = 4000;
const POLL_MAX_MS = 30000;
const UNREAD_MS = 20000;

const JSON_HEADERS = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

async function readJson(response) {
    try {
        return await response.json();
    } catch {
        return null;
    }
}

export function registerChat(Alpine) {
    Alpine.data('chat', () => ({
        lastId: 0,
        error: '',
        sending: false,
        delay: POLL_MS,
        timer: null,
        polling: false,

        init() {
            const data = this.$el.dataset;
            this.pollUrl = data.pollUrl;
            this.sendUrl = data.sendUrl;
            this.csrf = data.csrf;
            this.genericError = data.errorGeneric;
            this.sessionError = data.errorSession;
            this.lastId = Number(data.lastId || 0);

            this.scrollToEnd();
            this.schedule();
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) {
                    this.poll();
                }
            });
        },

        get hasError() {
            return this.error !== '';
        },

        schedule() {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.poll(), this.delay);
        },

        // Inserta solo los mensajes que aun no estan en pantalla (evita duplicados entre envio y poll).
        append(html) {
            const list = this.$refs.list;
            const nearBottom = list.scrollHeight - list.scrollTop - list.clientHeight < 80;
            const template = document.createElement('template');
            template.innerHTML = html;

            for (const element of Array.from(template.content.children)) {
                const id = element.getAttribute('data-message-id');
                if (id !== null && list.querySelector('[data-message-id="' + id + '"]') === null) {
                    list.appendChild(element);
                }
            }

            if (nearBottom) {
                this.scrollToEnd();
            }
        },

        scrollToEnd() {
            const list = this.$refs.list;
            list.scrollTop = list.scrollHeight;
        },

        async poll() {
            if (this.polling || document.hidden) {
                this.schedule();
                return;
            }

            this.polling = true;

            try {
                const response = await fetch(this.pollUrl + '?after=' + this.lastId, {
                    headers: JSON_HEADERS,
                    credentials: 'same-origin',
                });

                if (response.status === 429) {
                    this.delay = Math.min(this.delay * 2, POLL_MAX_MS);
                } else if (response.status === 401 || response.status === 419) {
                    this.error = this.sessionError;
                    return;
                } else if (response.ok) {
                    const body = await readJson(response);
                    this.delay = POLL_MS;
                    this.error = '';

                    if (body !== null && body.success && body.data.count > 0) {
                        this.append(body.data.html);
                        this.lastId = Math.max(this.lastId, body.data.last_id);
                    }
                }
            } catch {
                this.delay = Math.min(this.delay * 2, POLL_MAX_MS);
            } finally {
                this.polling = false;
            }

            this.schedule();
        },

        // Enter envia; durante la composicion IME (o con Mayus) Enter conserva su funcion normal.
        sendOnEnter(event) {
            if (event.isComposing || event.shiftKey) {
                return;
            }

            event.preventDefault();
            this.send();
        },

        async send() {
            if (this.sending) {
                return;
            }

            const form = this.$refs.form;
            const body = form.elements.body.value.trim();
            const file = form.elements.attachment.files.length;

            if (body === '' && file === 0) {
                return;
            }

            this.sending = true;
            this.error = '';

            try {
                const response = await fetch(this.sendUrl, {
                    method: 'POST',
                    headers: { ...JSON_HEADERS, 'X-CSRF-TOKEN': this.csrf },
                    credentials: 'same-origin',
                    body: new FormData(form),
                });
                const payload = await readJson(response);

                if (response.status === 201 && payload !== null && payload.success) {
                    this.append(payload.data.html);
                    this.scrollToEnd();
                    // No se avanza `lastId`: mensajes ajenos con id menor aun no recibidos se perderian; el
                    // siguiente poll trae el propio y `append` lo ignora por estar ya en pantalla.
                    form.reset();
                } else if (response.status === 401 || response.status === 419) {
                    this.error = this.sessionError;
                } else {
                    this.error = this.messageFrom(payload);
                }
            } catch {
                this.error = this.genericError;
            } finally {
                this.sending = false;
            }
        },

        messageFrom(payload) {
            if (payload === null) {
                return this.genericError;
            }

            if (payload.errors) {
                const first = Object.values(payload.errors)[0];
                return Array.isArray(first) ? first[0] : this.genericError;
            }

            return (payload.error && payload.error.message) || payload.message || this.genericError;
        },
    }));

    // Insignia de mensajes sin leer del menu lateral.
    Alpine.data('chatUnread', () => ({
        count: 0,

        init() {
            this.url = this.$el.dataset.url;
            this.refresh();
            setInterval(() => this.refresh(), UNREAD_MS);
        },

        get hasUnread() {
            return this.count > 0;
        },

        async refresh() {
            if (document.hidden) {
                return;
            }

            try {
                const response = await fetch(this.url, { headers: JSON_HEADERS, credentials: 'same-origin' });
                const body = response.ok ? await readJson(response) : null;

                if (body !== null && body.success) {
                    this.count = body.data.unread;
                }
            } catch {
                // Sin red: se conserva el ultimo valor y se reintenta en el siguiente ciclo.
            }
        },
    }));

    // Buscador de personas para iniciar un chat 1 a 1.
    Alpine.data('chatUserSearch', () => ({
        query: '',
        results: [],
        searched: false,

        init() {
            this.url = this.$el.dataset.url;
        },

        get noResults() {
            return this.searched && this.results.length === 0;
        },

        async search() {
            const term = this.query.trim();

            if (term.length < 2) {
                this.results = [];
                this.searched = false;
                return;
            }

            try {
                const response = await fetch(this.url + '?q=' + encodeURIComponent(term), {
                    headers: JSON_HEADERS,
                    credentials: 'same-origin',
                });
                const body = response.ok ? await readJson(response) : null;

                if (body !== null && body.success) {
                    this.results = body.data;
                    this.searched = true;
                }
            } catch {
                this.results = [];
            }
        },
    }));
}
