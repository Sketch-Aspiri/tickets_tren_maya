// Build de Alpine compatible con CSP (@alpinejs/csp): no usa `eval`/`new Function`, por lo que el
// encabezado Content-Security-Policy no necesita 'unsafe-eval'. A cambio, la logica interactiva se
// registra aqui con Alpine.data(...) y las vistas solo referencian propiedades y metodos por nombre.
// Los datos del servidor viajan en atributos `data-*` (texto plano), nunca dentro de expresiones.
import Alpine from '@alpinejs/csp';
import { registerChat } from './chat.js';

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

// Editor de recurrencia de actividades: solo muestra/oculta secciones segun la casilla "se repite" y la
// frecuencia elegida (los campos son inputs normales que viajan en el formulario).
// data-recurring="1" (iniciar activo) y data-frequency="daily|weekly|monthly" (frecuencia inicial).
Alpine.data('recurrenceEditor', () => ({
    recurring: false,
    frequency: 'weekly',

    init() {
        this.recurring = this.$el.dataset.recurring === '1';
        this.frequency = this.$el.dataset.frequency ?? 'weekly';
    },

    onToggle(event) {
        this.recurring = event.target.checked;
    },

    onFrequency(event) {
        this.frequency = event.target.value;
    },

    get isRecurring() {
        return this.recurring;
    },

    get isWeekly() {
        return this.frequency === 'weekly';
    },

    get isMonthly() {
        return this.frequency === 'monthly';
    },
}));

// Selector de asignados: casillas de colaboradores con filtro local por nombre, contador y exclusion del
// responsable elegido. Es una mejora progresiva: sin JavaScript las casillas y el <select> funcionan igual.
// data-max (tope de colaboradores) y data-counter (plantilla "{count} ... {max}") vienen del servidor.
Alpine.data('assigneePicker', () => ({
    ready: false,
    count: 0,
    max: 10,
    query: '',
    noResults: false,

    init() {
        this.max = Number(this.$el.dataset.max) || 10;
        this.ready = true;
        this.sync();
    },

    boxes() {
        return [...this.$el.querySelectorAll('input[data-assignee]')];
    },

    responsibleValue() {
        return this.$el.querySelector('select[data-responsible]')?.value ?? '';
    },

    // Reglas: el responsable no puede ser colaborador; al llegar al tope se bloquean las casillas sin marcar.
    sync() {
        const responsible = this.responsibleValue();
        const boxes = this.boxes();

        boxes.forEach((box) => {
            if (responsible !== '' && box.value === responsible) {
                box.checked = false;
            }
        });

        this.count = boxes.filter((box) => box.checked).length;

        boxes.forEach((box) => {
            const isResponsible = responsible !== '' && box.value === responsible;
            box.disabled = isResponsible || (!box.checked && this.count >= this.max);
            const row = box.closest('[data-row]');
            row?.classList.toggle('opacity-50', box.disabled);
            row?.querySelector('[data-responsible-tag]')?.classList.toggle('hidden', !isResponsible);
        });
    },

    onChange() {
        this.sync();
    },

    onFilter(event) {
        this.query = event.target.value.trim().toLowerCase();
        let shown = 0;

        this.boxes().forEach((box) => {
            const row = box.closest('[data-row]');
            const matches = this.query === '' || row.textContent.toLowerCase().includes(this.query);
            row.classList.toggle('hidden', !matches);
            if (matches) {
                shown += 1;
            }
        });

        this.noResults = shown === 0;
    },

    get counterText() {
        return (this.$el.dataset.counter ?? '').replace('{count}', String(this.count)).replace('{max}', String(this.max));
    },
}));

registerChat(Alpine);

Alpine.start();

// Graficas del panel de seguimiento: Chart.js se carga bajo demanda (chunk propio, mismo origen) solo si la
// pagina trae algun <canvas data-chart>; el resto de las paginas no descarga nada extra.
if (document.querySelector('canvas[data-chart]') !== null) {
    import('./charts.js').then(({ initCharts }) => initCharts());
}
