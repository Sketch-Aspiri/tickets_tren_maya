# API / JSON Conventions — Sistema de Tickets (Tren Maya)

Este sistema es **Blade + Alpine**, no un API product. “API” aquí son
endpoints JSON/HTML parciales internos (chat polling, resúmenes de
notificaciones, datos de gráficas). Fuente de verdad: `CLAUDE.md`.

## Principios

- Auth por **sesión** (Breeze) + middleware de cuenta activa y 2FA. No
  Sanctum/tokens para endpoints internos del MVP.
- Misma Policy / alcance que las rutas web. “Solo es un fetch” no exime.
- CSRF en todo `fetch` (`X-CSRF-TOKEN` desde `<meta name="csrf-token">`),
  `credentials: 'same-origin'`. No desactivar CSRF.
- No crear `routes/api.php` / API pública hasta que exista un consumidor
  externo real (YAGNI).

## Rutas

- Viven en `routes/web.php` (grupo `web`) con sesión y CSRF.
- Prefijos por feature (`chat`, `notifications`, `tracking`), no versionado.
- Nombres resourceful alineados con el resto del app.

## Respuestas

- HTML parcial o JSON según el endpoint existente; no inventar un envelope
  distinto por pantalla.
- Validación: dejar el `422` estándar de Form Request.
- Fuera de alcance: **404** (no filtrar existencia). Sin permiso: **403**.
- Errores de servidor: sin stack, SQL ni rutas de archivo en el cuerpo.

## Filtros y paginación

- Query params consistentes: `from`, `to`, `sort`, `direction`, `q`,
  `page`, filtros de estado/prioridad/equipo ya usados en listados.
- Validar siempre con Form Request; ningún filtro puede ensanchar
  `visibleTo` / alcance de Policy.
- `paginate()` / `simplePaginate()` — no cargar tablas enteras.

## Rate limiting

- Definir `RateLimiter::for(...)` (valores en `config/tickets.php`) en
  auth, creación, comentarios, adjuntos, chat poll/envío, exports y
  descargas.

## Adjuntos y previews

- Disco privado; servir solo por controlador con Policy.
- Preview de imagen solo png/jpg, `nosniff`, CSP restrictiva, nunca path
  arbitrario del cliente.

## Exports

- Formato por enum validado en servidor; nombre de archivo generado aquí.
- Anti-fórmulas (`SpreadsheetSanitizer` + celdas texto). Auditar `exported`.
