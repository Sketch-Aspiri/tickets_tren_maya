// Asistente Temayin: preguntas frecuentes precargadas (sin servidor). Compatible con el build CSP de Alpine:
// la vista solo referencia nombres y los textos viajan en `data-*` (JSON plano), nunca como expresiones.
export function registerTemayin(Alpine) {
    Alpine.data('temayin', () => ({
        open: false,
        activeId: '',
        faqs: {},

        init() {
            try {
                this.bubbleDismissed = window.sessionStorage.getItem('temayin-bubble-dismissed') === '1';
            } catch {
                this.bubbleDismissed = false;
            }

            try {
                this.faqs = JSON.parse(this.$el.dataset.faqs ?? '{}');
            } catch {
                this.faqs = {};
            }
        },

        get hasActive() {
            return this.activeId !== '' && this.activeId in this.faqs;
        },

        get hasNoActive() {
            return !this.hasActive;
        },

        get activeQuestion() {
            return this.hasActive ? this.faqs[this.activeId].q : '';
        },

        get activeAnswer() {
            return this.hasActive ? this.faqs[this.activeId].a : '';
        },

        // Burbuja "recuerda crear un ticket a Soporte": visible mientras el panel está cerrado, hasta que se descarta
        // (se recuerda en la sesión del navegador; si sessionStorage no está disponible, solo dura en esta página).
        bubbleDismissed: false,

        get showBubble() {
            return !this.open && !this.bubbleDismissed;
        },

        dismissBubble() {
            this.bubbleDismissed = true;

            try {
                window.sessionStorage.setItem('temayin-bubble-dismissed', '1');
            } catch {
                // Almacenamiento bloqueado: basta con ocultarla en esta página.
            }
        },

        get toggleExpanded() {
            return this.open ? 'true' : 'false';
        },

        toggle() {
            if (this.open) {
                this.close();
                return;
            }

            this.open = true;
            this.$nextTick(() => this.$refs.panel.focus());
        },

        close() {
            if (!this.open) {
                return;
            }

            this.open = false;
            this.activeId = '';
            this.$nextTick(() => this.$refs.toggle.focus());
        },

        ask(event) {
            this.activeId = event.currentTarget.dataset.id ?? '';
            this.$nextTick(() => this.$refs.panel.focus());
        },

        back() {
            this.activeId = '';
            this.$nextTick(() => this.$refs.panel.focus());
        },
    }));
}
