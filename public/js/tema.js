// Aplica el tema (claro / oscuro / sistema) antes de pintar la pagina para que no parpadee en blanco.
// Es un archivo propio (no un script en linea) por la CSP `script-src 'self'`, y se carga de forma
// sincrona desde layouts/head.blade.php. La preferencia vive en localStorage ("tema"); sin ella se sigue
// la del sistema. El selector esta en resources/js/theme.js (Alpine.data('themeToggle')).
(function () {
    var tema = null;
    try {
        tema = localStorage.getItem('tema');
    } catch (e) {
        tema = null;
    }
    var oscuro = tema === 'oscuro' || (tema !== 'claro' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', oscuro);
})();
