// Build de Alpine compatible con CSP (@alpinejs/csp): no usa `eval`/`new Function`, por lo que el
// encabezado Content-Security-Policy no necesita 'unsafe-eval'. A cambio, la logica interactiva se
// registra aqui con Alpine.data(...) y las vistas solo referencian propiedades y metodos por nombre.
// Los datos del servidor viajan en atributos `data-*` (texto plano), nunca dentro de expresiones.
import Alpine from '@alpinejs/csp';

window.Alpine = Alpine;

const FOCUSABLE_SELECTOR = "a, button, input:not([type='hidden']), textarea, select, details, [tabindex]:not([tabindex='-1'])";

// Barra lateral responsiva (menu movil).
Alpine.data('sidebar', () => ({
    menuOpen: false,

    openMenu() {
        this.menuOpen = true;
    },

    closeMenu() {
        this.menuOpen = false;
    },

    panelClass() {
        return this.menuOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0';
    },
}));

// Modal accesible: data-name (identificador), data-show="1" para abrirlo al cargar, data-focusable="1".
Alpine.data('modal', () => ({
    show: false,
    name: '',
    focusable: false,

    init() {
        this.name = this.$el.dataset.name ?? '';
        this.focusable = this.$el.dataset.focusable === '1';
        this.show = this.$el.dataset.show === '1';

        this.$watch('show', (value) => {
            document.body.classList.toggle('overflow-y-hidden', value);

            if (value && this.focusable) {
                setTimeout(() => this.firstFocusable()?.focus(), 100);
            }
        });
    },

    open() {
        this.show = true;
    },

    close() {
        this.show = false;
    },

    onOpenModal(event) {
        if (event.detail === this.name) {
            this.show = true;
        }
    },

    onCloseModal(event) {
        if (event.detail === this.name) {
            this.show = false;
        }
    },

    focusables() {
        return [...this.$el.querySelectorAll(FOCUSABLE_SELECTOR)].filter((el) => !el.hasAttribute('disabled'));
    },

    firstFocusable() {
        return this.focusables()[0];
    },

    lastFocusable() {
        return this.focusables().slice(-1)[0];
    },

    onTab(event) {
        const items = this.focusables();
        if (items.length === 0) {
            return;
        }

        event.preventDefault();
        const index = items.indexOf(document.activeElement);
        const next = event.shiftKey ? items[index - 1] ?? this.lastFocusable() : items[index + 1] ?? this.firstFocusable();
        next.focus();
    },
}));

// Boton que abre un modal por nombre (data-modal="nombre").
Alpine.data('modalTrigger', () => ({
    open() {
        this.$dispatch('open-modal', this.$el.dataset.modal ?? '');
    },
}));

// Formulario con confirmacion nativa (data-confirm="texto ya traducido en el servidor").
Alpine.data('confirmSubmit', () => ({
    onSubmit(event) {
        if (!window.confirm(this.$el.dataset.confirm ?? '')) {
            event.preventDefault();
        }
    },
}));

// Desafio 2FA: alterna entre codigo de la app y codigo de recuperacion (data-recovery="1" para iniciar en recuperacion).
Alpine.data('twoFactorChallenge', () => ({
    recovery: false,

    init() {
        this.recovery = this.$el.dataset.recovery === '1';
    },

    toggle() {
        this.recovery = !this.recovery;
    },

    get usingCode() {
        return !this.recovery;
    },
}));

Alpine.start();
