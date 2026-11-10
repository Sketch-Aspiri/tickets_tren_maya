// Selector de tema de la barra lateral: sistema (por defecto), claro u oscuro. Guarda la eleccion en
// localStorage y pone o quita la clase "dark" en <html>; public/js/tema.js la aplica al cargar la pagina.
// Compatible con @alpinejs/csp: la vista solo usa propiedades y metodos por nombre.
const OPTIONS = ['sistema', 'claro', 'oscuro'];
const prefersDark = window.matchMedia('(prefers-color-scheme: dark)');

function readTheme() {
    try {
        const value = localStorage.getItem('tema');
        return OPTIONS.includes(value) ? value : 'sistema';
    } catch {
        return 'sistema';
    }
}

export function applyTheme(theme) {
    const dark = theme === 'oscuro' || (theme === 'sistema' && prefersDark.matches);
    document.documentElement.classList.toggle('dark', dark);
    window.dispatchEvent(new CustomEvent('theme-changed', { detail: { dark } }));
}

export function isDarkTheme() {
    return document.documentElement.classList.contains('dark');
}

export function registerTheme(Alpine) {
    Alpine.data('themeToggle', () => ({
        theme: readTheme(),

        init() {
            prefersDark.addEventListener('change', () => {
                if (this.theme === 'sistema') {
                    applyTheme('sistema');
                }
            });
        },

        choose(theme) {
            this.theme = theme;
            try {
                if (theme === 'sistema') {
                    localStorage.removeItem('tema');
                } else {
                    localStorage.setItem('tema', theme);
                }
            } catch {
                // Sin almacenamiento (ventana privada): el cambio dura solo esta pagina.
            }
            applyTheme(theme);
        },

        setSystem() {
            this.choose('sistema');
        },

        setLight() {
            this.choose('claro');
        },

        setDark() {
            this.choose('oscuro');
        },

        get isSystem() {
            return this.theme === 'sistema';
        },

        get isLight() {
            return this.theme === 'claro';
        },

        get isDark() {
            return this.theme === 'oscuro';
        },
    }));
}
