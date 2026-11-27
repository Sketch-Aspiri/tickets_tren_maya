// Interfaz propia de la aplicación en lugar de la del navegador:
// - diálogo de confirmación (reemplaza window.confirm);
// - botones "enviando…" que evitan el doble envío (la pantalla de carga vive en pantalla-carga.js);
// - mensajes de validación en línea (reemplazan los globos nativos de "Completa este campo").
// Compatible con el build CSP de Alpine: la lógica vive aquí y las vistas solo usan nombres y data-*.

import { envioCambiaDePantalla } from './pantalla-carga.js';

let pendingConfirm = null;

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

function releaseAllForms() {
    document.querySelectorAll('form[data-submitting]').forEach((form) => releaseForm(form));
}

// ---------- Validación en línea ----------
function messageFor(field) {
    const id = errorId(field);
    let message = document.getElementById(id);

    if (!message) {
        message = document.createElement('p');
        message.id = id;
        message.dataset.appFieldError = '1';
        message.className = 'mt-1 text-sm font-medium text-red-700';
        const anchor = field.closest('label') ?? field;
        anchor.insertAdjacentElement('afterend', message);
        const describedBy = (field.getAttribute('aria-describedby') ?? '').split(' ').filter((part) => part !== '' && part !== id);
        field.setAttribute('aria-describedby', [...describedBy, id].join(' '));
    }

    return message;
}

function errorId(field) {
    return `${field.id || field.name || 'campo'}-app-error`.replace(/[^\w-]/g, '_');
}

function clearFieldError(field) {
    field.removeAttribute('aria-invalid');

    const id = errorId(field);
    document.getElementById(id)?.remove();

    const rest = (field.getAttribute('aria-describedby') ?? '').split(' ').filter((part) => part !== '' && part !== id);

    if (rest.length > 0) {
        field.setAttribute('aria-describedby', rest.join(' '));
    } else {
        field.removeAttribute('aria-describedby');
    }
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
    // Envío de formularios: marcar ocupado. Se revisa al final de la cola para respetar a otros manejadores que
    // cancelen el envío (p. ej. el diálogo de confirmación).
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
            if (event.defaultPrevented || (form.target && form.target !== '_self')) {
                return;
            }

            busyForm(form, event.submitter);

            if (!envioCambiaDePantalla(form, event.submitter)) {
                // Descarga por formulario: la página no cambia; se libera el botón en un momento.
                window.setTimeout(() => releaseForm(form), 3000);
            }
        }, 0);
    });

    // Al quitarse la pantalla de carga (volver con "atrás", "Cerrar" o el tiempo límite) o si la página reaparece
    // desde la memoria del navegador, los formularios vuelven a aceptar envíos.
    document.addEventListener('pantalla-carga:oculta', releaseAllForms);
    window.addEventListener('pageshow', releaseAllForms);

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
            // El foco empieza en "Cancelar": un Enter repetido no debe ejecutar la accion.
            this.$nextTick(() => this.$refs.cancel?.focus());
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
