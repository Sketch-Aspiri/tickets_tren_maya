// Interfaz propia de la aplicación en lugar de la del navegador:
// - diálogo de confirmación (reemplaza window.confirm);
// - barra de carga al navegar o enviar formularios, y botones "enviando…" que evitan el doble envío;
// - mensajes de validación en línea (reemplazan los globos nativos de "Completa este campo").
// Compatible con el build CSP de Alpine: la lógica vive aquí y las vistas solo usan nombres y data-*.

const LOADER_DELAY_MS = 150;
const LOADER_FAILSAFE_MS = 15000;
// Descargas: la página no se descarga, así que no deben mostrar la barra.
const DOWNLOAD_PATH = /\/(export(\/|$|\?)|attachments\/\d+(\/preview)?$|incoming-email-attachments\/|avatars\/)/;

let pendingConfirm = null;

function isPlainClick(event) {
    return event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey;
}

function navigatesAway(link) {
    if (!link || link.hasAttribute('download') || link.dataset.noLoader !== undefined || (link.target && link.target !== '_self')) {
        return false;
    }

    let url;

    try {
        url = new URL(link.href, window.location.href);
    } catch {
        return false;
    }

    if (url.origin !== window.location.origin || DOWNLOAD_PATH.test(url.pathname)) {
        return false;
    }

    // Solo cambia el ancla dentro de la misma página.
    return !(url.pathname === window.location.pathname && url.search === window.location.search && url.hash !== '');
}

// ---------- Barra de carga ----------
function loaderElement() {
    return document.getElementById('app-page-loader');
}

let loaderTimer = null;
let failsafeTimer = null;

export function startLoading() {
    const loader = loaderElement();

    if (!loader || loaderTimer !== null) {
        return;
    }

    loaderTimer = window.setTimeout(() => {
        loader.classList.remove('hidden');
        loader.setAttribute('aria-hidden', 'false');
        // Siguiente cuadro: arranca la animación de avance.
        window.requestAnimationFrame(() => loader.dataset.state = 'running');
    }, LOADER_DELAY_MS);

    failsafeTimer = window.setTimeout(stopLoading, LOADER_FAILSAFE_MS);
}

export function stopLoading() {
    const loader = loaderElement();

    window.clearTimeout(loaderTimer);
    window.clearTimeout(failsafeTimer);
    loaderTimer = null;
    failsafeTimer = null;

    if (loader) {
        loader.dataset.state = 'idle';
        loader.classList.add('hidden');
        loader.setAttribute('aria-hidden', 'true');
    }

    document.querySelectorAll('form[data-submitting]').forEach((form) => releaseForm(form));
}

// ---------- Botones "enviando…" ----------
// No se usa `disabled` en el botón: el navegador quitaría su name/value del envío. Se marca como ocupado y el
// formulario rechaza un segundo envío mientras tanto.
function busyForm(form, submitter) {
    form.dataset.submitting = '1';
    form.setAttribute('aria-busy', 'true');

    const button = submitter ?? form.querySelector('button[type="submit"], button:not([type])');

    if (button && !button.querySelector('[data-spinner]')) {
        button.dataset.busy = '1';
        button.setAttribute('aria-disabled', 'true');
        const spinner = document.createElement('span');
        spinner.dataset.spinner = '1';
        spinner.setAttribute('aria-hidden', 'true');
        spinner.className = 'me-2 inline-block h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-current border-t-transparent motion-reduce:animate-none';
        button.prepend(spinner);
    }
}

function releaseForm(form) {
    delete form.dataset.submitting;
    form.removeAttribute('aria-busy');
    form.querySelectorAll('[data-busy]').forEach((button) => {
        delete button.dataset.busy;
        button.removeAttribute('aria-disabled');
        button.querySelector('[data-spinner]')?.remove();
    });
}

// ---------- Validación en línea ----------
function messageFor(field) {
    const id = `${field.id || field.name || 'campo'}-app-error`.replace(/[^\w-]/g, '_');
    let message = document.getElementById(id);

    if (!message) {
        message = document.createElement('p');
        message.id = id;
        message.dataset.appFieldError = '1';
        message.className = 'mt-1 text-sm font-medium text-red-700';
        const anchor = field.closest('label') ?? field;
        anchor.insertAdjacentElement('afterend', message);
        const describedBy = (field.getAttribute('aria-describedby') ?? '').split(' ').filter(Boolean);
        field.setAttribute('aria-describedby', [...describedBy, id].join(' '));
    }

    return message;
}

function clearFieldError(field) {
    if (field.getAttribute('aria-invalid') === 'true') {
        field.removeAttribute('aria-invalid');
    }

    const id = `${field.id || field.name || 'campo'}-app-error`.replace(/[^\w-]/g, '_');
    document.getElementById(id)?.remove();
}

let focusedInvalid = false;
let validationTexts = null;

// Textos en español de la aplicación (no los del navegador, que dependen de su idioma). Viajan como JSON en
// `data-messages` del componente app-ui; :min / :max se reemplazan con los límites del campo.
function texts() {
    if (validationTexts === null) {
        try {
            validationTexts = JSON.parse(document.getElementById('app-validation-messages')?.dataset.messages ?? '{}');
        } catch {
            validationTexts = {};
        }
    }

    return validationTexts;
}

function validationText(field) {
    const v = field.validity;
    const t = texts();
    const fill = (key, values) => Object.entries(values).reduce((text, [name, value]) => text.replace(`:${name}`, String(value)), t[key] ?? '');
    let key = 'invalid';

    if (v.valueMissing) {
        key = field.type === 'checkbox' ? 'checkbox' : (field.tagName === 'SELECT' || field.type === 'file' ? 'choose' : 'required');
    } else if (v.typeMismatch) {
        key = field.type === 'email' ? 'email' : (field.type === 'url' ? 'url' : 'invalid');
    } else if (v.tooShort) {
        key = 'too_short';
    } else if (v.tooLong) {
        key = 'too_long';
    } else if (v.rangeUnderflow) {
        key = 'min';
    } else if (v.rangeOverflow) {
        key = 'max';
    } else if (v.patternMismatch) {
        key = 'pattern';
    } else if (v.badInput) {
        key = 'number';
    }

    const text = fill(key, { min: field.getAttribute('min') ?? field.getAttribute('minlength') ?? '', max: field.getAttribute('max') ?? field.getAttribute('maxlength') ?? '' });

    return text !== '' ? text : field.validationMessage;
}

export function installGlobalUi() {
    // Envío de formularios: confirmar (si aplica), marcar ocupado y mostrar la barra. Se revisa al final de la
    // cola para respetar a otros manejadores que cancelen el envío (p. ej. el diálogo de confirmación).
    document.addEventListener('submit', (event) => {
        const form = event.target;

        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        if (form.dataset.submitting === '1') {
            event.preventDefault();

            return;
        }

        window.setTimeout(() => {
            if (event.defaultPrevented || form.dataset.noLoader !== undefined || (form.target && form.target !== '_self')) {
                return;
            }

            busyForm(form, event.submitter);

            if (!DOWNLOAD_PATH.test(new URL(form.action, window.location.href).pathname)) {
                startLoading();
            } else {
                // Descarga por formulario: la página no cambia; se libera el botón en un momento.
                window.setTimeout(() => releaseForm(form), 3000);
            }
        }, 0);
    });

    document.addEventListener('click', (event) => {
        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;

        if (!link || !isPlainClick(event)) {
            return;
        }

        window.setTimeout(() => {
            if (!event.defaultPrevented && navigatesAway(link)) {
                startLoading();
            }
        }, 0);
    });

    // Volver con el botón "atrás" (bfcache): la página reaparece sin recargar; se limpia el estado de carga.
    window.addEventListener('pageshow', () => stopLoading());

    // Validación: mensaje propio bajo el campo en vez del globo del navegador.
    document.addEventListener('invalid', (event) => {
        const field = event.target;

        if (!(field instanceof HTMLElement) || !('validationMessage' in field)) {
            return;
        }

        event.preventDefault();
        field.setAttribute('aria-invalid', 'true');
        messageFor(field).textContent = validationText(field);

        if (!focusedInvalid) {
            focusedInvalid = true;
            field.focus({ preventScroll: false });
            window.setTimeout(() => (focusedInvalid = false), 0);
        }
    }, true);

    const revalidate = (event) => {
        const field = event.target;

        if (field instanceof HTMLElement && 'checkValidity' in field && field.getAttribute('aria-invalid') === 'true' && field.checkValidity()) {
            clearFieldError(field);
        }
    };
    document.addEventListener('input', revalidate, true);
    document.addEventListener('change', revalidate, true);
}

export function registerUi(Alpine) {
    // Formularios con data-confirm: en vez de window.confirm abren el diálogo de la aplicación.
    Alpine.data('confirmSubmit', () => ({
        onSubmit(event) {
            const form = this.$el;

            if (form.dataset.confirmed === '1') {
                delete form.dataset.confirmed;

                return;
            }

            event.preventDefault();
            pendingConfirm = { form, submitter: event.submitter ?? null };
            window.dispatchEvent(new CustomEvent('app-confirm', { detail: { message: form.dataset.confirm ?? '' } }));
        },
    }));

    // Diálogo de confirmación accesible (alertdialog): Escape o "Cancelar" cancelan; el foco queda atrapado entre
    // sus dos botones y vuelve al botón que lo abrió.
    Alpine.data('appDialog', () => ({
        open: false,
        message: '',

        get isOpen() {
            return this.open;
        },

        show(event) {
            this.message = event.detail?.message ?? '';
            this.open = true;
            this.$nextTick(() => this.$refs.accept?.focus());
        },

        accept() {
            const pending = pendingConfirm;
            pendingConfirm = null;
            this.open = false;

            if (pending) {
                pending.form.dataset.confirmed = '1';
                pending.form.requestSubmit(pending.submitter && pending.form.contains(pending.submitter) ? pending.submitter : undefined);
            }
        },

        cancel() {
            const pending = pendingConfirm;
            pendingConfirm = null;
            this.open = false;
            this.$nextTick(() => pending?.submitter?.focus());
        },

        onKeydown(event) {
            if (!this.open) {
                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                this.cancel();
            } else if (event.key === 'Tab') {
                const buttons = [this.$refs.cancel, this.$refs.accept].filter(Boolean);
                const index = buttons.indexOf(document.activeElement);
                event.preventDefault();
                buttons[(index + (event.shiftKey ? buttons.length - 1 : 1)) % buttons.length]?.focus();
            }
        },
    }));
}
