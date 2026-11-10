// Selector de tema de la barra lateral: sistema (por defecto), claro u oscuro. Guarda la eleccion en
// localStorage y pone o quita la clase "dark" en <html>; public/js/tema.js la aplica al cargar la pagina.
// Compatible con @alpinejs/csp: la vista solo usa propiedades y metodos por nombre.
const OPTIONS = ['sistema', 'claro', 'oscuro'];
const prefersDark = window.matchMedia('(prefers-color-scheme: dark)');

// Dominio de la cookie compartida: el padre comun (".zonaorientetm.tech") para que Tickets y el
// Dashboard lean la misma eleccion; en localhost o una IP, solo el host.
function cookieDomain() {
    const host = window.location.hostname;
    const parts = host.split('.');
    if (/^[\d.]+$/.test(host) || parts.length < 3) {
        return '';
    }
    return `; domain=.${parts.slice(-2).join('.')}`;
}

function saveCookie(theme) {
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `tema=${theme}; max-age=31536000; path=/; SameSite=Lax${cookieDomain()}${secure}`;
}

function readTheme() {
    const cookie = document.cookie.match(/(?:^|; )tema=(sistema|claro|oscuro)(?:;|$)/);
    if (cookie) {
        return cookie[1];
    }
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
            saveCookie(theme);
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
