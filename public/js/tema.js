// Aplica el tema (claro / oscuro / sistema) antes de pintar la pagina para que no parpadee en blanco.
// Es un archivo propio (no un script en linea) por la CSP `script-src 'self'`, y se carga de forma
// sincrona desde layouts/head.blade.php. La preferencia vive en la cookie "tema" del dominio comun (compartida con el
// Dashboard) y en localStorage; sin ella se sigue la del sistema. El selector esta en resources/js/theme.js (Alpine.data('themeToggle')).
(function () {
    // La cookie "tema" (dominio comun del ecosistema) manda: asi Tickets y el Dashboard
    // comparten la eleccion. Si no existe, se usa localStorage.
    var tema = null;
    var cookie = document.cookie.match(/(?:^|; )tema=(sistema|claro|oscuro)(?:;|$)/);
    if (cookie) {
        tema = cookie[1];
    } else {
        try {
            tema = localStorage.getItem('tema');
        } catch (e) {
            tema = null;
        }
    }
    var oscuro = tema === 'oscuro' || (tema !== 'claro' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', oscuro);
})();
