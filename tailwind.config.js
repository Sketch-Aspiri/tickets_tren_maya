import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            // Fuente del sistema: sin CDN externos (CSP `font-src 'self'`).
            fontFamily: {
                sans: [...defaultTheme.fontFamily.sans],
            },
            colors: {
                // Identidad visual Tren Maya (mismos tokens que el dashboard hermano), muestreada de public/logo.png.
                brand: {
                    mist: '#F2F7F5', // fondo de pagina
                    mint: '#70C2AD', // isotipo del logo: solo decorativo / texto grande (2.1:1 sobre blanco)
                    teal: '#1F7460', // links, focus, bordes (5.64:1 sobre blanco)
                    green: '#06534D', // acciones primarias y texto (8.93:1 sobre blanco)
                    'green-dark': '#043D39', // hover / active del verde primario
                },
            },
        },
    },

    plugins: [forms],
};
