// Selector en cascada Edificio → Coordinación → Equipo (componente Blade `team-cascade`).
// Compatible con el build CSP de Alpine: la vista solo referencia nombres de métodos y los textos viajan en
// `data-labels` (JSON plano). Sin JavaScript la vista ya muestra los equipos agrupados por coordinación.

function readOptions(select) {
    return Array.from(select.querySelectorAll('option[data-parent]')).map((option) => ({
        value: option.value,
        label: option.textContent,
        parent: option.dataset.parent,
    }));
}

// Reconstruye un <select> con solo las opciones indicadas (textContent: nunca HTML).
function fill(select, options, placeholder, wanted, autoSelectSingle) {
    select.replaceChildren();

    const empty = document.createElement('option');
    empty.value = '';
    empty.textContent = placeholder;
    select.append(empty);

    for (const item of options) {
        const option = document.createElement('option');
        option.value = item.value;
        option.textContent = item.label;
        select.append(option);
    }

    let value = options.some((item) => item.value === wanted) ? wanted : '';

    if (value === '' && autoSelectSingle && options.length === 1) {
        value = options[0].value;
    }

    select.value = value;

    return value;
}

export function registerOrgPicker(Alpine) {
    Alpine.data('teamCascade', () => ({
        labels: {},
        autoTeam: false,
        allCoordinations: [],
        allTeams: [],

        init() {
            const root = this.$el;

            try {
                this.labels = JSON.parse(root.dataset.labels ?? '{}');
            } catch {
                this.labels = {};
            }

            this.autoTeam = root.dataset.autoTeam === '1';
            this.station = root.querySelector('[data-cascade="station"]');
            this.coordination = root.querySelector('[data-cascade="coordination"]');
            this.team = root.querySelector('[data-cascade="team"]');
            this.stationWrap = root.querySelector('[data-cascade-wrap="station"]');
            this.coordinationWrap = root.querySelector('[data-cascade-wrap="coordination"]');

            // Se leen una sola vez las opciones completas (los selects se reconstruyen después).
            this.allCoordinations = readOptions(this.coordination);
            this.allTeams = readOptions(this.team);
            this.stationWrap.classList.toggle('hidden', this.station.options.length <= 2);

            this.apply();
        },

        onStation() {
            this.coordination.value = '';
            this.team.value = '';
            this.apply();
        },

        onCoordination() {
            this.team.value = '';
            this.apply();
        },

        onTeam() {
            this.notify(this.coordination.value);
        },

        apply() {
            const stationId = this.station.value;
            const coordinations = this.allCoordinations.filter((item) => item.parent === stationId);
            const coordinationId = fill(this.coordination, coordinations, this.labels.coordination ?? '', this.coordination.value, true);

            // Solo se muestra si hay algo que elegir en ese edificio.
            this.coordinationWrap.classList.toggle('hidden', coordinations.length <= 1);

            const teams = this.allTeams.filter((item) => item.parent === coordinationId);
            let placeholder = this.labels.team ?? '';

            if (coordinationId === '') {
                placeholder = (stationId === '' ? this.labels.pickFirst : this.labels.pickFirstCoordination) ?? '';
            }

            fill(this.team, teams, placeholder, this.team.value, this.autoTeam);
            this.notify(coordinationId);
        },

        // Avisa a los selects del MISMO formulario (categorías, personas) para que solo ofrezcan lo de esa
        // coordinación o, en actividades, lo de ese equipo.
        notify(coordinationId) {
            this.$dispatch('cascade-coordination', { coordinationId, teamId: this.team.value });
        },
    }));

    // Filtra las opciones de un <select> (categorías o personas) por la coordinación elegida en la cascada del mismo
    // formulario. Cada opción declara `data-coordinations` (ids separados por coma; vacío = sirve en cualquiera, p. ej.
    // categorías generales). Sin coordinación elegida se muestran todas. El servidor valida igual: esto solo evita
    // ofrecer combinaciones que serían rechazadas.
    Alpine.data('coordinationFilter', () => ({
        init() {
            this.select = this.$el.querySelector('select') ?? this.$el;
            this.apply(currentCoordination(this.$el));
        },

        onCoordination(event) {
            if (sameForm(event.target, this.$el)) {
                this.apply(String(event.detail?.coordinationId ?? ''));
            }
        },

        apply(coordinationId) {
            filterOptions(this.select, coordinationId);
        },
    }));
}

// Coordinación elegida actualmente en la cascada del mismo formulario ('' si no hay cascada o no se eligió).
export function currentCoordination(element) {
    const scope = element.closest('form') ?? document;

    return scope.querySelector('[data-cascade="coordination"]')?.value ?? '';
}

// Equipo elegido en la cascada del mismo formulario; `null` si el formulario no tiene cascada (un solo equipo posible).
export function currentTeam(element) {
    const scope = element.closest('form') ?? document;
    const select = scope.querySelector('[data-cascade="team"]');

    return select ? select.value : null;
}

export function sameForm(a, b) {
    return (a.closest('form') ?? null) === (b.closest('form') ?? null);
}

export function fitsCoordination(element, coordinationId) {
    const list = element.dataset.coordinations ?? '';

    return coordinationId === '' || list === '' || list.split(',').includes(coordinationId);
}

// Oculta y deshabilita las opciones que no caben; si la elegida queda fuera, se limpia la selección.
export function filterOptions(select, coordinationId) {
    for (const option of select.querySelectorAll('option[data-coordinations]')) {
        const fits = fitsCoordination(option, coordinationId);
        option.hidden = !fits;
        option.disabled = !fits;
    }

    for (const group of select.querySelectorAll('optgroup')) {
        group.hidden = Array.from(group.querySelectorAll('option')).every((option) => option.hidden);
    }

    if (select.selectedOptions[0]?.disabled) {
        select.value = '';
    }
}
