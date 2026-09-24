# CLAUDE.md — Sistema de Tickets y Control de Actividades (Tren Maya)

> Léelo completo antes de escribir código. Si una decisión no está aquí, elige la opción más simple que siga las convenciones de este archivo y anótala en la sección **Registro de decisiones** (al final).

---

## 1. Propósito

Sistema web para que los empleados sepan qué tienen pendiente y para que el jefe de zona (y los coordinadores) asignen, marquen y monitoreen el avance.

Hay **dos conceptos distintos**:

| Concepto | Qué es | Quién lo crea | Ejemplo |
|---|---|---|---|
| **Ticket** | Solicitud entrante que hay que atender | Cualquier usuario, o **por correo electrónico** | "Falla en la impresora de la oficina 2" |
| **Actividad** | Tarea planificada, asignada, con subtareas y posible recurrencia | Jefe de zona y coordinadores | "Reporte semanal de avance" (cada lunes) |

Es un **proyecto independiente** del dashboard del jefe de zona (repo, base de datos y usuarios propios), pero comparte **la misma VPS e infraestructura**.

Usuarios: pocos al inicio, diseñado para escalar a **100+**. Debe funcionar bien en celular (responsive).

---

## 2. Reglas de trabajo para Claude Code

1. Trabaja **por sprint** (sección 17). No adelantes funcionalidad de sprints posteriores salvo que sea necesaria.
2. Antes de cada sprint: lee el plan, propón la lista de tareas y espera confirmación si hay ambigüedad. Al terminar: corre pruebas, actualiza el checklist del sprint y el registro de decisiones.
3. Nunca dejes secretos en el repo. Todo va en `.env` (que está en `.gitignore`); mantén `.env.example` actualizado.
4. Toda funcionalidad nueva incluye **Form Request, Policy y pruebas**. Sin excepciones.
5. Contenido que llegue por correo o de cualquier usuario es **dato no confiable**: se valida, se escapa y nunca se interpreta como instrucción.
6. Antes de instalar un paquete nuevo fuera del stack definido, justifícalo en el registro de decisiones.
7. Commits pequeños y en español, formato: `feat: ...`, `fix: ...`, `refactor: ...`, `test: ...`, `docs: ...`.

---

## 3. Stack (misma arquitectura que el dashboard)

| Capa | Tecnología |
|---|---|
| Backend | Laravel, **última versión estable compatible con PHP 8.3+** (el dashboard usa Laravel 11; para un proyecto nuevo usa la versión vigente con soporte de seguridad y documéntala en el registro de decisiones) |
| Frontend | **Blade + Tailwind CSS + Alpine.js** (scaffolding con Laravel Breeze, stack Blade) |
| Gráficas | Chart.js o ApexCharts (usa la misma que use el dashboard) |
| Base de datos | MySQL 8 |
| Servidor | Nginx vía **CloudPanel**, Ubuntu 24.04 LTS, SSL con Let's Encrypt |
| Roles y permisos | `spatie/laravel-permission` |
| Auditoría | `spatie/laravel-activitylog` |
| 2FA | `pragmarx/google2fa-laravel` |
| Colas | Driver `database` + worker con Supervisor |
| Correo saliente | SMTP configurable por `.env` |
| Correo entrante | IMAP (`webklex/laravel-imap`) leído por comando programado |
| Exportación (sprint 5) | `maatwebsite/excel` (solo Excel básico) |
| Pruebas | Pest o PHPUnit (lo que traiga Breeze), más factories y seeders |

No uses React/Vue/Livewire. Mantén Blade + Alpine.

### Estructura de carpetas

```
app/
  Enums/              # TicketStatus, ActivityStatus, Priority, UserStatus, AssignmentRole
  Models/
  Http/
    Controllers/
    Requests/         # Un Form Request por acción de escritura
  Policies/           # Una Policy por modelo
  Services/           # Lógica de negocio (no en controladores)
    TicketService.php
    ActivityService.php
    AssignmentService.php
    RecurrenceService.php
    EmailIngestionService.php
    DashboardMetricsService.php
  Notifications/
  Jobs/
  Console/Commands/   # ingest-emails, notify-due, generate-recurring
resources/views/
  components/         # Componentes Blade reutilizables (badge de estado, tarjeta, tabla, modal)
  layouts/
  tickets/ activities/ dashboard/ users/ admin/
database/
  migrations/ factories/ seeders/
tests/
  Feature/ Unit/
```

Regla: **controladores delgados**; la lógica de negocio vive en `Services/`. Las transiciones de estado se validan en un solo lugar (el Service), nunca en la vista.

---

## 4. Infraestructura

- **Misma VPS que el dashboard** (Hostinger KVM 2, CloudPanel). Este sistema es un **sitio separado** dentro de CloudPanel.
- Separación obligatoria: **usuario de sistema propio, base de datos propia y usuario MySQL propio** (con permisos solo sobre su BD), dominio o subdominio propio y `.env` propio.
- MySQL escucha solo en `localhost`. SSH solo por llave. Firewall (ufw) y fail2ban activos.
- Cron cada minuto para `php artisan schedule:run`. Worker de colas con Supervisor (respaldo: `queue:work --stop-when-empty` por cron cada minuto).
- Backups automáticos diarios de la base de datos y de `storage/app/private` (adjuntos), cifrados, con prueba de restauración documentada.
- Entornos: `local` → `staging` (sitio de CloudPanel aparte) → `production`.

> Ver **Decisiones abiertas** sobre acceso público vs VPN.

---

## 5. Roles y permisos

Tres roles (Spatie Permission), jerarquía **jefe de zona → coordinador → empleado**.

- Existen **equipos** (`teams`). Cada equipo tiene un coordinador. Cada empleado pertenece a un equipo.
- El jefe de zona ve todo. El coordinador ve lo de su equipo. El empleado ve lo suyo.

| Acción | Jefe de zona | Coordinador | Empleado |
|---|---|---|---|
| Aprobar/rechazar registros de usuarios nuevos | ✅ | ❌ | ❌ |
| Gestionar usuarios, roles y equipos | ✅ | ❌ | ❌ |
| Gestionar categorías | ✅ | ❌ | ❌ |
| Crear ticket | ✅ | ✅ | ✅ |
| Ver tickets | Todos | De su equipo | Los suyos (creados o asignados) + bolsa de su equipo |
| Asignar / reasignar / delegar | Todos | De su equipo | ❌ |
| "Tomar" ticket sin asignar de la bolsa | — | — | ✅ (solo de su equipo) |
| Crear actividades y subtareas | ✅ | ✅ (para su equipo) | ❌ |
| Ver actividades | Todas | De su equipo | Las asignadas a él |
| Avanzar estado hasta **En revisión** | ✅ | ✅ | ✅ (solo lo suyo) |
| **Aprobar** (En revisión → Completado) o rechazar | ✅ (cualquiera) | ✅ (su equipo) | ❌ |
| Cancelar / reabrir | ✅ | ✅ (su equipo) | ❌ |
| Panel de seguimiento | Global | De su equipo | ❌ |
| Ver bitácora de auditoría | ✅ | ❌ | ❌ |

Toda regla se implementa en **Policies** y se prueba. Nunca confíes en ocultar un botón en la vista.

---

## 6. Estados y reglas de negocio

Los tickets y las actividades comparten estados:

```
Pendiente → En proceso → En revisión → Completado
                 ↑             │
                 └─ (rechazo, con comentario obligatorio)

Cancelado (desde cualquier estado no final, por jefe/coordinador)
Reabrir: Completado/Cancelado → Pendiente (solo jefe/coordinador, con comentario)
```

Reglas:

- **El empleado nunca puede marcar Completado.** Solo pasa a **En revisión**; el jefe o coordinador aprueban o rechazan (el rechazo exige comentario y regresa a **En proceso**).
- Cada cambio de estado guarda quién, cuándo y comentario (historial + bitácora).
- **Prioridad**: Baja, Media, Alta, Urgente.
- **Vencido** no es un estado: es un cálculo (`due_date < hoy` y estado no final).
- **Asignación**: uno o varios usuarios por ticket/actividad, con rol `responsable` (uno) o `colaborador`. Un ticket puede estar **sin asignar** (bolsa del equipo).
- **Folios**: tickets `TM-AAAA-0001`, actividades `ACT-AAAA-0001` (consecutivo por año).
- **Recurrencia** (solo actividades): frecuencia diaria/semanal/mensual, intervalo, día(s) y fecha fin opcional. Un comando programado **genera instancias** de la actividad; cada instancia se gestiona de forma independiente.
- **Subtareas** (solo actividades): título, responsable opcional, hecha/no hecha. El porcentaje de avance de la actividad se calcula con ellas.

---

## 7. Modelo de datos (base)

Ajusta nombres si es necesario, pero conserva las relaciones.

- `users`: id, name, email (único), password, status (`pending|active|inactive`), team_id (nullable), 2FA (secret, confirmed_at), timestamps
- `teams`: id, name, coordinator_id (FK users)
- `categories`: id, name, active
- `tickets`: id, folio (único), title, description, priority, status, category_id, team_id, created_by (nullable si viene de correo desconocido → no se crea), source (`web|email`), due_date, completed_at, timestamps, softDeletes
- `activities`: id, folio, title, description, priority, status, category_id, team_id, created_by, start_date, due_date, recurrence_rule (JSON, nullable), parent_activity_id (nullable, instancias), completed_at, timestamps, softDeletes
- `subtasks`: id, activity_id, title, assigned_to (nullable), done, done_at
- `assignments` (polimórfica): id, assignable_type, assignable_id, user_id, role (`responsable|colaborador`), assigned_by, timestamps
- `status_histories` (polimórfica): id, historable_type, historable_id, from_status, to_status, user_id, comment, created_at
- `comments` (polimórfica): id, commentable_type, commentable_id, user_id, body, created_at
- `attachments` (polimórfica): id, attachable_type, attachable_id, user_id, original_name, path, mime, size
- `email_ingestions`: id, message_id (único), from_email, subject, ticket_id (nullable), result (`created|rejected|duplicate|error`), reason, processed_at
- Tablas de Spatie (permissions, activity_log) y `notifications` de Laravel.

**Índices** (pensando en 100+ usuarios): `status`, `due_date`, `team_id`, `priority`, `assignments(user_id)`, `assignments(assignable_type, assignable_id)`. Paginación en todos los listados; evita N+1 con `with()` y revísalo con pruebas.

---

## 8. Módulos del MVP

1. **Autenticación**: registro propio, login por correo y contraseña, recuperación de contraseña, 2FA, aprobación de cuentas.
2. **Usuarios y equipos** (jefe de zona).
3. **Tickets**: CRUD, filtros (estado, prioridad, categoría, responsable, vencidos), comentarios, adjuntos, asignación/reasignación/delegación, bolsa de tickets, flujo de revisión.
4. **Actividades**: CRUD, subtareas, recurrencia, asignación múltiple, flujo de revisión.
5. **Mis pendientes** (vista del empleado): lo asignado a él, ordenado por vencimiento y prioridad.
6. **Ingesta de correos → tickets**.
7. **Notificaciones** dentro del sistema y por correo.
8. **Panel de seguimiento** para jefe y coordinadores.
9. **Bitácora de auditoría** (visor para el jefe).

### Registro de usuarios (importante)

- Cualquiera puede registrarse, pero la cuenta queda en `pending` **sin rol y sin acceso** hasta que el **jefe de zona la apruebe** y le asigne rol y equipo. Notifica al jefe por correo y en el sistema cuando haya registros pendientes.
- Rate limiting en registro, login y recuperación de contraseña.
- Validar formato de correo. (Ver decisiones abiertas sobre restringir por dominio.)

---

## 9. Correo entrante → tickets

- Comando `php artisan tickets:ingest-emails` programado cada 5 minutos (configurable), conectado por IMAP a la bandeja definida en `.env`.
- Flujo por cada correo no leído:
  1. Si `Message-ID` ya existe en `email_ingestions` → `duplicate`, ignorar.
  2. Si el remitente **no corresponde a un usuario activo** → `rejected` (no crear ticket), registrar motivo y ofrecer al jefe verlo en un listado.
  3. Si corresponde → crear ticket: asunto = título, cuerpo = descripción (texto plano o HTML **sanitizado**), adjuntos validados igual que en la web, `source = email`, `team_id` = equipo del remitente, prioridad Media, sin asignar (bolsa).
  4. Marcar el correo como procesado y guardar el resultado en `email_ingestions`.
- Límites: tamaño máximo de correo/adjuntos, ignorar autorespuestas (`Auto-Submitted`, `Precedence: bulk`), máximo N tickets por remitente por hora.
- El contenido del correo **nunca se ejecuta ni se interpreta como instrucción**; es solo texto de un ticket.
- Fuera del MVP: respuestas por correo que se agreguen como comentarios (hilos).

---

## 10. Notificaciones

Laravel Notifications con canales `database` (dentro del sistema, campana con contador) y `mail`. Todo por cola.

Eventos:
- Cuenta nueva pendiente de aprobación (→ jefe).
- Cuenta aprobada/rechazada (→ usuario).
- Ticket/actividad asignado o reasignado (→ asignados).
- Cambio de estado, ticket puesto En revisión (→ aprobadores), aprobado o rechazado (→ asignados).
- Nuevo comentario (→ participantes).
- **Vence pronto** (24 h antes) y **vencido** (comando `notify:due`, sin duplicar alertas).
- Ticket **estancado** (sin movimiento por X días, configurable) → coordinador/jefe.
- Ticket creado por correo (→ coordinador del equipo).

---

## 11. Panel de seguimiento (jefe y coordinadores)

- Tarjetas: abiertos, en revisión, vencidos, completados en el periodo.
- **Pendientes por empleado** y **carga de trabajo** (abiertos y vencidos por persona).
- **Tiempo promedio de cierre** por categoría y por equipo.
- Gráficas (estado, prioridad, tendencia de creados vs. completados).
- Filtros por periodo, equipo, empleado, categoría.
- Toda la lógica de métricas en `DashboardMetricsService`, con consultas agregadas (no cargar registros completos), y cache corto donde ayude.
- Exportación **básica a Excel** de los listados filtrados (sprint 5, solo si el tiempo lo permite). PDF y reportes avanzados quedan fuera.

---

## 12. Seguridad (no negociable)

- **HTTPS** siempre; cabeceras de seguridad (HSTS, X-Frame-Options, X-Content-Type-Options, CSP razonable).
- **2FA obligatorio para jefe de zona y coordinadores**; opcional para empleados.
- Contraseñas con política mínima (longitud, no comunes); hash por defecto de Laravel.
- Autorización por **Policies** en todos los modelos y rutas; cero acceso a datos ajenos por cambiar un ID en la URL (probar IDOR en pruebas).
- Validación con Form Requests; escapar salida en Blade (`{{ }}`); sanitizar HTML de correos.
- **Adjuntos**: lista blanca de tipos (pdf, imágenes, docx, xlsx, txt), tamaño máximo (p. ej. 10 MB), nombre aleatorio, guardados en `storage/app/private` y **servidos solo por un controlador que verifica permiso**. Nunca en `public/`.
- Sin SQL crudo con datos del usuario; usar Eloquent/query builder con bindings.
- `$fillable` explícito en todos los modelos (sin `$guarded = []`).
- Rate limiting en autenticación, creación de tickets y endpoints de descarga.
- Auditoría con Spatie Activitylog: quién, qué, cuándo, valores anteriores y nuevos; login, logout, intentos fallidos y cambios de rol.
- `APP_DEBUG=false` en staging y producción. Logs sin datos sensibles.
- Dependencias: `composer audit` y `npm audit` en cada sprint.

---

## 13. Convenciones de código

- PHP **PSR-12** (Laravel Pint). `declare(strict_types=1)` en clases nuevas.
- Nombres de clases, métodos y tablas **en inglés**; textos de interfaz, mensajes y correos **en español (es_MX)** usando archivos de idioma (`lang/es`), no textos sueltos.
- Enums para estados, prioridades y roles de asignación (nada de strings mágicos).
- Un Form Request por acción de escritura; una Policy por modelo; lógica en Services.
- Vistas: componentes Blade reutilizables (`<x-status-badge>`, `<x-priority-badge>`, `<x-card>`, `<x-modal>`, tablas y filtros). Alpine solo para interacción ligera.
- Diseño **mobile-first** con Tailwind; probar en pantalla de 360 px.
- Zona horaria del sistema: la de Quintana Roo (`America/Cancun`); guardar en UTC y mostrar en local.
- Migraciones reversibles; seeders para roles, permisos, categorías y datos demo (solo en `local`/`staging`).

---

## 14. Pruebas

- Feature tests para: registro y aprobación, cada regla de la matriz de permisos, transiciones válidas e inválidas, asignación/reasignación, recurrencia, ingesta de correos (con correos simulados), notificaciones y métricas.
- Pruebas de autorización negativas (un empleado no puede ver/editar lo ajeno).
- Meta: pruebas verdes antes de cerrar cada sprint. Seeder de demo con ~100 usuarios y miles de tickets para verificar rendimiento en el sprint 6.

---

## 15. Comandos útiles

```bash
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan test
./vendor/bin/pint
php artisan schedule:work            # local
php artisan queue:work               # local
php artisan tickets:ingest-emails    # ingesta manual
php artisan notify:due               # alertas de vencimiento
php artisan activities:generate-recurring
composer audit && npm audit
```

---

## 16. Fuera del MVP (no implementar)

- Integración con n8n.
- Notificaciones por WhatsApp.
- Reportes avanzados y exportación a PDF.
- Respuestas por correo como comentarios (hilos).
- Conversión de ticket a actividad, SLAs, tableros Kanban, app móvil nativa.

---

## 17. Plan por sprints

Sprints de **1 semana** (ritmo rápido). Cada sprint termina con demo en `staging`, pruebas verdes y checklist actualizado.

### Sprint 0 — Fundación (2–3 días)
**Objetivo:** proyecto corriendo en local y en staging.
- [ ] Repo privado, `.gitignore`, `.env.example`, ramas (`main`, `develop`). _(parcial: `.gitignore` y `.env.example` listos; falta repo y ramas, se hará fuera de este entorno)_
- [x] Instalar Laravel + Breeze (Blade) + Tailwind + Alpine + Pint + Pest/PHPUnit.
- [x] Layout base responsive (sidebar/navbar), componentes Blade iniciales, idioma es_MX y zona horaria.
- [ ] Sitio en CloudPanel (staging): usuario, BD y usuario MySQL propios, SSL, cron y Supervisor. _(pendiente: infraestructura)_
- [ ] Despliegue manual documentado (`docs/DEPLOY.md`). _(pendiente: infraestructura)_

**Listo cuando:** la pantalla base se ve en staging por HTTPS y las pruebas corren.

### Sprint 1 — Autenticación, roles y usuarios
**Objetivo:** registro con aprobación, roles y equipos.
- [x] Registro propio con estado `pending` y sin acceso; aprobación por el jefe con asignación de rol y equipo.
- [x] Spatie Permission: roles `jefe_zona`, `coordinador`, `empleado`, permisos y seeders.
- [x] Módulo de usuarios y equipos (jefe): listar, aprobar/rechazar, activar/inactivar, cambiar rol/equipo.
- [x] 2FA (obligatorio jefe y coordinador), recuperación de contraseña, rate limiting.
- [x] Spatie Activitylog instalado y registrando login, cambios de rol y aprobaciones.
- [x] Policies base y pruebas de la matriz de permisos de usuarios.

**Listo cuando:** un usuario se registra, el jefe lo aprueba y entra con su rol; un usuario `pending` no puede acceder a nada.

### Sprint 2 — Tickets (núcleo)
**Objetivo:** ciclo completo de un ticket por web.
- [x] Migraciones, modelos, enums, categorías (CRUD del jefe).
- [x] CRUD de tickets, folio consecutivo, prioridad, fecha límite.
- [x] Servicio de transiciones de estado + historial; flujo **En revisión → aprobar/rechazar**.
- [x] Asignación múltiple, reasignación, delegación, bolsa de tickets y "tomar ticket".
- [x] Comentarios y adjuntos seguros.
- [x] Listados con filtros y paginación; vista "Mis pendientes" del empleado.
- [x] Policies y pruebas (incluye IDOR y transiciones inválidas).

**Listo cuando:** un empleado crea/atiende un ticket, lo pasa a revisión y el coordinador o jefe lo aprueba, todo con historial y respetando permisos.

### Sprint 3 — Actividades
**Objetivo:** planificación de tareas con subtareas y recurrencia.
- [x] Migraciones y CRUD de actividades (jefe/coordinador), asignación múltiple.
- [x] Subtareas y cálculo de avance.
- [x] Recurrencia: `RecurrenceService` + comando `activities:generate-recurring` programado.
- [x] Reutilizar flujo de estados, comentarios y adjuntos del sprint 2.
- [x] Integrar actividades en "Mis pendientes".
- [x] Pruebas de recurrencia (semanal, mensual, fecha fin) y permisos.

**Listo cuando:** el jefe crea una actividad semanal recurrente y se generan las instancias correctas para los asignados.

### Sprint 4 — Notificaciones y correo entrante
**Objetivo:** alertas y creación de tickets por correo.
- [ ] Notificaciones `database` + `mail` por cola; campana con contador y vista de notificaciones.
- [ ] Eventos de la sección 10, incluidos `notify:due` (vence pronto/vencido) y tickets estancados, sin duplicados.
- [ ] `EmailIngestionService` + comando de ingesta con IMAP, deduplicación por `Message-ID`, rechazo de remitentes desconocidos, límites y sanitización.
- [ ] Listado de correos rechazados para el jefe.
- [ ] Pruebas con correos simulados (válido, duplicado, remitente desconocido, autorespuesta, adjunto inválido).

**Listo cuando:** un correo de un usuario activo genera un ticket en la bolsa de su equipo y el coordinador recibe la alerta.

### Sprint 5 — Panel de seguimiento y bitácora
**Objetivo:** que el jefe monitoree.
- [x] `DashboardMetricsService`: abiertos, en revisión, vencidos, completados, por empleado, carga de trabajo, tiempo promedio de cierre (tickets y actividades; decisiones 44-48).
- [x] Vista del panel con tarjetas, gráficas (Chart.js empaquetado, sin CDN ni cambios de CSP) y filtros (periodo, equipo, empleado, categoría); versión limitada para coordinador (`/tracking`; decisiones 45 y 49).
- [x] Visor de bitácora de auditoría con filtros (jefe) (`/audit-log`; decisión 50).
- [x] Exportación básica a Excel de listados filtrados (solo si sobra tiempo): cubre los listados de tickets (`/tickets/export`; decisión 51) y de actividades (`/activities/export`; decisión 53).
- [x] Pruebas de métricas y de alcance por rol (`tests/Feature/Dashboard`, `tests/Feature/Audit`, `TicketExportTest`).

**Listo cuando:** el jefe ve el estado global y por persona, y el coordinador solo el de su equipo.

### Sprint 6 — Endurecimiento, rendimiento y salida a producción
**Objetivo:** listo para usuarios reales.
- [ ] Revisión de seguridad completa (sección 12), `composer audit` y `npm audit`.
- [ ] Prueba de rendimiento con seeder de ~100 usuarios y miles de tickets; ajustar índices y consultas.
- [ ] QA en celular y navegadores principales.
- [ ] Backups automáticos cifrados + prueba de restauración documentada.
- [ ] Sitio de producción, SSL, Supervisor, cron, monitoreo básico y `APP_DEBUG=false`.
- [ ] Manuales breves (jefe, coordinador, empleado) y sesión de prueba con usuarios reales (UAT).
- [ ] Lista de correcciones y backlog post-MVP.

**Listo cuando:** el sistema está en producción, con backup verificado, y los usuarios piloto completaron el flujo completo sin ayuda.

---

## 18. Decisiones abiertas (con valor por defecto)

Usa el valor por defecto y **avísame** cuando llegues al punto donde importa. Anota la decisión final en el registro.

| # | Tema | Por defecto | Se necesita saber antes de |
|---|---|---|---|
| 1 | **Acceso público vs VPN.** El dashboard va detrás de VPN (Tailscale), pero aquí hasta 100+ empleados se registran solos y envían correos. | Acceso público por HTTPS con 2FA para roles altos, rate limiting, fail2ban y aprobación manual de cuentas | Sprint 0 (staging) |
| 2 | **Restricción de registro por dominio de correo** (p. ej. solo dominios institucionales). | Sin restricción; la aprobación manual del jefe es el control | Sprint 1 |
| 3 | **Proveedor de la bandeja de entrada** (IMAP clásico, Gmail, Microsoft 365, que puede exigir OAuth). | IMAP simple con credenciales en `.env` | Sprint 4 |
| 4 | **Correo saliente (SMTP)** y dirección remitente. | Configurable por `.env`, log driver en local | Sprint 4 |
| 5 | **Dominio/subdominio** del sistema. | Subdominio de staging temporal | Sprint 0 |
| 6 | **Días sin movimiento** para considerar un ticket estancado. | 5 días hábiles, configurable | Sprint 4 |
| 7 | **Versión de Laravel** elegida. | La última estable compatible con PHP 8.3+ | Sprint 0 |

---

## 19. Registro de decisiones

> Claude Code: agrega aquí cada decisión técnica relevante (fecha, decisión, motivo).

**2026-09-24 — Sprint 1 (autenticación, roles y usuarios) + base mínima del Sprint 0**

1. **Versión de Laravel (decisión abierta #7):** Laravel **13.17** (última estable al momento; `php: ^8.3`, ejecutado localmente en PHP 8.4.19). Breeze 2.4 (stack Blade), **PHPUnit 12** (no Pest; sin mezclar estilos), Alpine 3, **Tailwind 3** (el que instala Breeze; se quitó `@tailwindcss/vite` v4 que dejó el esqueleto para no tener dos versiones). Se eliminó `AGENTS.md` del esqueleto (guías de Boost, fuera del stack).
2. **Conflicto con `.claude/rules/*.md`:** esos archivos describen el "Dashboard Jefe de Zona" (solo VPN, un único usuario, un solo rol). Para este proyecto **manda este CLAUDE.md** (registro público con aprobación, 100+ usuarios, 3 roles, 2FA por rol). Siguen vigentes de esas reglas: Pint, controladores delgados, Form Request por escritura, Policies con Spatie, `$fillable` explícito, sin `dd()`, migraciones con `down()`/`onDelete`, sin `env()` fuera de `config/`, etc. NO aplican `server-conventions.md` (VPN-only) ni el estilo de API de un solo usuario.
3. **Decisión abierta #1 (público vs VPN):** se usó el valor por defecto: acceso público por HTTPS, 2FA obligatorio para roles altos, rate limiting (`RateLimiter::for` en login, registro, recuperación y 2FA), aprobación manual de cuentas, cabeceras de seguridad (HSTS solo en producción). Pendiente de infraestructura (Sprint 0): HTTPS real, `SESSION_SECURE_COOKIE=true`, fail2ban/ufw. Preguntar al jefe antes de producción si prefiere VPN.
4. **Decisión abierta #2 (dominio de correo):** sin restricción de dominio; la aprobación manual del jefe es el control. Mitigaciones: nombre validado (letras/números/puntuación básica, se muestra en correos y bitácora) y límite de 10 registros/hora por IP.
5. **Paquete QR:** `bacon/bacon-qr-code ^3.1` declarado explícitamente (ya llegaba como dependencia transitiva de `pragmarx/google2fa-qrcode`). Se usa el backend **SVG** (sin Imagick) y el QR se genera **en el servidor**: el secreto nunca viaja a servicios externos de QR.
6. **2FA:** se usa la clase núcleo `PragmaRX\Google2FA\Google2FA` (paquete `pragmarx/google2fa-laravel`) con middleware/controladores propios (`EnsureTwoFactorVerified`, `TwoFactorService`) en lugar del middleware de sesión del paquete, para controlar: secreto cifrado (`encrypted`), códigos de recuperación **hasheados** de un solo uso, anti-reuso de TOTP (`two_factor_last_timestamp` + `verifyKeyNewer`, se pasa `0` en lugar de `null` porque con `null` la librería devuelve `true` sin periodo), verificación en sesión ligada al id de usuario y descartada en cada login. Se eliminó el `config/google2fa.php` publicado (sin uso).
7. **Rol/equipo en la aprobación:** equipo obligatorio para `coordinador` y `empleado`; **opcional para `jefe_zona`** (ve todo y el primer jefe se crea por consola sin equipo). Esto relaja la frase "rol y equipo obligatorios" solo para el jefe. La relación de coordinación es `teams.coordinator_id` (debe ser un usuario activo con rol `coordinador`, validado con la regla `ActiveCoordinator`); `users.team_id` es la pertenencia. Un equipo con integrantes no se puede eliminar. Al inactivar a un coordinador o quitarle el rol se le libera de los equipos que coordinaba.
8. **Rechazo de solicitudes:** rechazar = pasar la cuenta a `inactive` (sin borrarla) y registrar el evento `rejected` con motivo en la bitácora; el jefe puede aprobarla después. `UserStatus` conserva solo `pending|active|inactive`.
9. **Registro sin auto-login:** al registrarse no se abre sesión (evita sesiones para correos no verificados); el usuario `pending` puede iniciar sesión y solo ve la pantalla "cuenta pendiente" (o "inactiva"/"sin rol") y puede cerrar sesión. Se eliminaron de Breeze: verificación de correo (la aprobación la sustituye), confirmación de contraseña y autoeliminación de cuenta. El correo no se edita desde el perfil (será la identidad para la ingesta de correos del Sprint 4).
10. **Integridad de jefes:** el jefe no puede inactivarse ni quitarse su propio rol y el sistema no puede quedarse sin ningún jefe activo (`UserService`, con `lockForUpdate` en transacción). Primer jefe: `php artisan users:create-jefe {email} --name=` (contraseña por prompt oculto, nunca por argumento). No existe "restablecer 2FA de otro usuario" (backlog); hoy se recupera con códigos de recuperación.
11. **Bitácora (activitylog v5):** en v5 los valores anteriores/nuevos van en la columna `attribute_changes` y lo demás en `properties`. `User`/`Team` usan `LogsActivity` con `logOnly` de campos seguros (nunca contraseña ni campos 2FA); los eventos explícitos (`approved`, `rejected`, `activated`, `deactivated`, `role_changed`, `team_changed`, `login`, `logout`, `login_failed`, `password_reset`, `password_changed`, `two_factor_*`) pasan por `AuditLogger`, que además elimina llaves sensibles. Se agregó `down()` a la migración publicada de `activity_log` (el stub del paquete no lo trae).
12. **Zona horaria:** `APP_TIMEZONE=UTC` (se guarda en UTC) y `APP_DISPLAY_TIMEZONE=America/Cancun` para mostrar (componente `<x-local-datetime>`).
13. **CSP:** `script-src 'self' 'unsafe-eval'` porque el build estándar de Alpine evalúa expresiones; no hay scripts en línea. Migrar al build `@alpinejs/csp` (y quitar `unsafe-eval`) queda como mejora. Fuentes del sistema (sin CDN externos).
14. **Contraseñas:** mínimo 10, mayúsculas/minúsculas y números; `uncompromised()` (consulta HIBP, requiere red) se controla con `PASSWORD_CHECK_BREACHED` (activo por defecto; desactivado en pruebas y en el `.env` local).
15. **Recuperación de contraseña:** respuesta uniforme exista o no el correo (no revela cuentas). Notificación al jefe por registro: `database` + `mail` en cola (`QUEUE_CONNECTION=database`); requiere worker/Supervisor en el servidor. Un resumen diario en lugar de un correo por registro queda en backlog si hay spam de registros.
16. **Pendiente de entorno:** no hay Xdebug/PCOV local (sin reporte de cobertura) ni MySQL local (pruebas en SQLite en memoria; migraciones verificadas con rollback/re-migrate en SQLite, falta verificarlas en MySQL 8 en staging).

**2026-09-24 — Correcciones de las revisiones de código y seguridad del Sprint 1** _(precisan o reemplazan lo indicado en las decisiones 3, 7 y 10)_

17. **Invariante coordinador/equipo (reemplaza parte de la decisión 7):** `users.team_id` es la fuente de verdad de "su equipo". Un coordinador solo puede coordinar el equipo al que pertenece y nunca más de uno. La regla vive en un solo lugar, `Team::canBeCoordinatedBy(User)` (usuario activo + rol `coordinador` + `team_id` igual al del equipo), y la usan `ActiveCoordinator` (validación de la petición) y `TeamService::releaseInvalidCoordination` (al cambiar de equipo o de rol, inactivar, rechazar: se libera `teams.coordinator_id` de lo que ya no le corresponde; queda en la bitácora del equipo). Además, migración `2026_09_24_150000_enforce_single_team_per_coordinator`: normaliza datos existentes (libera coordinaciones incoherentes) y agrega índice único en `teams.coordinator_id`. **Equipo nuevo:** al crearse aún no tiene integrantes, por lo que **nace sin coordinador** (enviar `coordinator_id` al crear es un error de validación); el coordinador se asigna al editar el equipo, cuando ya pertenece a él (se mueve primero desde Usuarios). Al mover un coordinador de A a B se libera A y **no** se le asigna B automáticamente (elegir es decisión explícita del jefe). El selector de coordinador de "editar equipo" solo lista a los integrantes coordinadores de ese equipo. Consecuencia para la vista de "nuevo equipo" (pendiente de UX): el selector de coordinador no aplica al crear.
18. **Política de sesiones (reemplaza "no hay restablecer 2FA/otro" en lo que toca a sesiones):** `AuthenticateSession` en el grupo `web` (`authenticateSessions()`) invalida sesiones cuyo hash de contraseña ya no coincide. Un único servicio, `SessionInvalidator::revokeAll(User, ?sesiónActual)`, rota `remember_token` y borra las filas de `sessions` del usuario (driver `database`; con otro driver solo actúan `AuthenticateSession` y `EnsureAccountIsActive`). Se usa al cambiar contraseña (conserva la sesión actual), restablecerla por correo (todas), inactivar, rechazar, **reactivar** (así una reactivación no "resucita" sesiones anteriores ni su marca `two_factor.verified_user_id`, que vive en el payload) y restablecer el 2FA por consola. Consecuencia aceptada: tras cambiar la contraseña, el dispositivo actual conserva la sesión pero pierde el "recordarme" (el token rotó).
19. **Configuración segura por entorno (precisa la decisión 3):** `config/session.php`: `secure` y `encrypt` son `true` por defecto salvo en `local`/`testing`; `SameSite=lax` (se descartó `strict` porque no envía la cookie al abrir el sistema desde enlaces externos, p. ej. un ticket enlazado en un correo del Sprint 4, y el CSRF token cubre el resto). `AppServiceProvider` lanza excepción al arrancar en `production`/`staging` si `APP_DEBUG=true`, la cookie no es `secure` o `APP_URL` no es `https://` (`App\Support\EnvironmentSecurityCheck`; no afecta `local`/`testing`; incluye comandos de consola: un `composer install` sin `.env` en un entorno no local fallará al descubrir paquetes, así que el build/deploy debe tener `.env` real o `APP_ENV=local`). `URL::forceScheme('https')` fuera de `local`/`testing`. `TRUSTED_PROXIES` y `TRUSTED_HOSTS` (comas) viven en `config/tickets.php` (`http.*`); vacíos por defecto; sin `TRUSTED_HOSTS`, staging/producción aceptan solo el host exacto de `APP_URL` (`App\Support\TrustedHosts`). HSTS se envía cuando `isSecure()` y el entorno no es `local`/`testing` (staging incluido; detrás de Nginx requiere `TRUSTED_PROXIES` para ver `X-Forwarded-Proto`). Las pantallas con secretos TOTP/códigos de recuperación/desafío 2FA llevan `Cache-Control: no-store` (middleware `no-store` por ruta). CSP sin cambios (`unsafe-eval` sigue pendiente para el Sprint 2).
20. **Datos demo solo en local:** `DemoDataSeeder` corre únicamente en `local`/`testing`, también si se invoca con `--class` (en otro entorno avisa y no hace nada); `DatabaseSeeder` ya no lo llama en `staging`: allí el primer jefe se crea con `users:create-jefe`. Un `DEMO_USER_PASSWORD` débil (política de contraseñas) aborta el seeder; la contraseña solo se imprime si es generada y se aplicó a cuentas nuevas en esa ejecución.
21. **`users:reset-2fa {email}` (reemplaza "no existe restablecer 2FA" de la decisión 10):** comando de consola, no ruta web. Pide confirmación, borra secreto/marca de tiempo/códigos/`two_factor_confirmed_at`, cierra todas las sesiones del usuario y audita `two_factor_reset_by_console` sin secretos. El usuario deberá configurar 2FA de nuevo al entrar (obligatorio para jefe/coordinador).
22. **Integridad y atomicidad:** el jefe de zona nunca conserva `team_id` (se fuerza `null` cuando el rol no requiere equipo, en la petición y en `UserService`). `reject`/`activate` y todas las operaciones de `TwoFactorService` que cambian estado y auditan van en `DB::transaction` (si la bitácora falla, el cambio se revierte). El consumo de TOTP y de códigos de recuperación se hace bajo `lockForUpdate` sobre la fila del usuario (cierra la carrera TOCTOU de reuso). La regla del último jefe bloquea el conjunto completo de jefes activos ordenado por `id` y cuenta después de bloquear; ante interbloqueo se reintenta 3 veces y, si persiste, responde `BusinessRuleException` de reintento (no 500). `BusinessRuleException` no se reporta al log. `User::scopeActiveWithRole` es la única definición de "activo con rol". `laravel/tinker` pasó a `require-dev`; contraseñas con `max:255`; `LOG_STACK=daily` en `.env.example`. _No verificado:_ el comportamiento de bloqueos/interbloqueos reales en MySQL (las pruebas usan SQLite en memoria, donde `FOR UPDATE` no aplica).


**2026-09-24 — Sprint 2 (tickets, núcleo)**

23. **Morph map (`Relation::enforceMorphMap`, un solo lugar: `App\Support\MorphMap`, registrado en `AppServiceProvider`):** alias `ticket`, `activity` (reservado; el modelo llega en el Sprint 3), `comment`, `attachment`, `category`. Como el mapa es **forzado**, todo modelo objetivo polimórfico debe estar en él; por eso `User` y `Team` se registran con su **nombre de clase completo como alias** (`App\Models\User`, `App\Models\Team`): son los valores que Spatie Permission (`model_has_roles.model_type`), Activitylog y Notifications ya guardaron en la BD en el Sprint 1, así no hay que reescribir filas ni se pierden roles. Consecuencia: en `activity_log.subject_type` los tickets se guardan como `ticket` (las pruebas y futuros visores deben usar el alias, p. ej. `(new Ticket)->getMorphClass()`). Un modelo nuevo usado como sujeto de bitácora sin alias lanzará `ClassMorphViolationException` (falla ruidosa a propósito).
24. **Folios:** `App\Services\FolioGenerator` con la tabla `folio_sequences` (prefijo + año, único). Dentro de una transacción hace `insertOrIgnore` del contador, lo relee con `lockForUpdate` y lo incrementa; corre en la MISMA transacción que crea el ticket, así un rollback devuelve el número (sin huecos) y dos altas simultáneas se serializan. Formato `TM-AAAA-0001` (`%04d`; pasa a 5 dígitos desde 10000 sin colisionar). El año es el de la zona de negocio (`America/Cancun`, `App\Support\LocalTime`), no el UTC. Prefijos en `config/tickets.php` (`folio_prefixes`); el Sprint 3 usará `ACT`. Las fábricas de pruebas usan `TF-` para no chocar. _No verificado:_ concurrencia real en MySQL (SQLite en memoria no simula `FOR UPDATE`).
25. **Alcance `Ticket::scopeVisibleTo(User)` (única definición; lo usan listados, "Mis pendientes" y `TicketPolicy`):** jefe = todos; **coordinador = tickets de su equipo (`users.team_id`, decisión 17) MÁS los que se le asignaron explícitamente** (sin esto, un ticket asignado por el jefe fuera de su equipo sería trabajo suyo que no puede abrir); empleado = creados por él + asignados a él + bolsa (sin asignar) de su equipo; pendiente/inactivo/sin rol = ninguno. La Policy `view` ejecuta esa misma consulta sobre el ticket (`whereKey(...)->exists()`), así el listado y el acceso por ID no pueden divergir. **Fuera de alcance responde 404** (`Response::denyAsNotFound()`, no revela que el ID existe); dentro de alcance pero sin permiso para la acción responde 403. Los Form Requests de tickets autorizan ANTES de validar con `Gate::authorize` (concern `AuthorizesWithGate`) para respetar ese 404 y no filtrar reglas de validación a quien no debe ni ver el recurso.
26. **Permisos Spatie (idempotentes en `RolesAndPermissionsSeeder`):** `tickets.view`, `tickets.create`, `tickets.work` (avanzar hasta En revisión, tomar, comentar, adjuntar) para los tres roles; `tickets.assign`, `tickets.review` (aprobar/rechazar) y `tickets.manage` (editar cualquier ticket del alcance, eliminar, cancelar, reabrir) para jefe y coordinador; `categories.manage` solo jefe. El permiso habilita la acción y la Policy limita el alcance. Tras desplegar hay que correr `php artisan db:seed --class=RolesAndPermissionsSeeder --force` (los roles existentes reciben los permisos nuevos; los usuarios conservan sus roles).
27. **Máquina de estados (un solo lugar):** el grafo vive en `TicketStatus::allowedTargets()` (Pendiente→En proceso|Cancelado; En proceso→En revisión|Cancelado; En revisión→Completado|En proceso (rechazo)|Cancelado; Completado/Cancelado→Pendiente (reabrir)) y `TicketService::transition()` lo aplica: bloquea la fila (`lockForUpdate`), **vuelve a autorizar** contra el estado fresco (evita TOCTOU), valida la arista, exige comentario en rechazo y reapertura, actualiza `completed_at` (solo mientras está Completado), guarda `status_histories` y audita `status_changed` (con valor anterior/nuevo) en una sola transacción; el evento genérico `updated` del modelo se suprime para no duplicar. `TicketPolicy::transition` decide QUIÉN recorre cada arista: cancelar y reabrir → `tickets.manage`; aprobar y rechazar → `tickets.review`; avanzar hasta En revisión → gestor del alcance, o el empleado solo en lo suyo (creado o asignado; la bolsa se "toma" primero). El empleado nunca completa, cancela ni reabre (probado para todos los estados). "Vencido" es el cálculo `Ticket::scopeOverdue()`/`isOverdue()` (`due_date` anterior a hoy en hora de negocio y estado no final), nunca un estado. Al crear se guarda una fila inicial de historial (`from_status = null`).
28. **Asignación (`AssignmentService`):** asignar, reasignar y delegar son la misma operación (`assign`: fija el conjunto completo con exactamente un `responsable` y hasta `max_collaborators` = 10 `colaborador`; conserva las filas que no cambian). Jefe → cualquier usuario activo con rol (incluye otros equipos); coordinador → solo activos de SU equipo; nunca pendientes/inactivos/sin rol; el responsable no se duplica como colaborador; tickets finales no se asignan (primero se reabren). `unassign` devuelve el ticket a la bolsa. `take` (tomar de la bolsa) exige equipo propio igual al del ticket (el jefe no tiene equipo, no toma) y es atómico: bloquea el ticket y comprueba que siga sin asignar. Todo bajo `lockForUpdate` del ticket y con autorización revalidada. Bitácora: `assigned`, `reassigned`, `unassigned`, `taken` con el conjunto anterior/nuevo. Índice único `(assignable_type, assignable_id, user_id)` (sirve también como índice por `(type, id)`); "un solo responsable" lo garantiza el servicio, no la BD. Si un usuario se inactiva, sus asignaciones se conservan (reasignarlas es decisión del coordinador; pendiente de UX en un sprint posterior).
29. **Adjuntos:** disco `local` privado (`storage/app/private/tickets/{id}/{40 aleatorios}.{ext}`), nunca `public/`. Lista blanca por **extensión Y MIME real** (finfo, `App\Support\AttachmentInspector`; el MIME declarado por el cliente se ignora): pdf, png, jpg/jpeg, txt, docx, xlsx; docx/xlsx además deben ser un paquete OOXML válido (`[Content_Types].xml` + `word/document.xml` o `xl/workbook.xml`, porque libmagic los adivina por el nombre de la primera entrada del zip). Máximo 10 MB (`ATTACHMENT_MAX_KB`) y 10 archivos por ticket (`ATTACHMENT_MAX_PER_TICKET`), ambos en `config/tickets.php`; el tope por ticket se aplica bajo lock del ticket. `original_name` se sanea (sin rutas, marcado ni caracteres de control, ≤100) y siempre se imprime con `{{ }}`; `path` está en `$hidden`. Descarga solo por `AttachmentController` (Policy `AttachmentPolicy::view` hereda el alcance del ticket; fuera de alcance 404): `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`, throttle `attachment-download`. Borrar: autor o gestor del alcance; se elimina fila y archivo y se audita. SVG y HTML quedan fuera de la lista (vector de XSS). El disco `local` de Laravel conserva su ruta `/storage/{path}` firmada (no se emiten firmas; sin firma responde 403).
30. **Comentarios:** texto plano de hasta 2000 caracteres (`comment_max_length`), se guarda tal cual (recortado) y se imprime siempre escapado (`whitespace-pre-line`, sin `{!! !!}`; hay una prueba que falla si aparece `{!!` en las vistas de tickets, categorías o componentes). Quién puede ver el ticket puede comentar y adjuntar. Sin edición ni borrado de comentarios en el MVP. Se cargan todos los del ticket (sin paginar; el límite de tasa 20/min por usuario acota el volumen; paginarlos queda en backlog si un ticket llega a cientos).
31. **CSP: se eliminó `'unsafe-eval'` (reemplaza la decisión 13 y el cierre de la 19):** Alpine pasó al build `@alpinejs/csp` 3.17.4 (mismo Alpine, mismo stack; `alpinejs` ya no se importa) y toda la lógica interactiva se registra con `Alpine.data(...)` en `resources/js/app.js` (`sidebar`, `modal`, `modalTrigger`, `confirmSubmit`, `twoFactorChallenge`). Las vistas solo referencian nombres simples (propiedades/métodos) y los datos del servidor viajan en atributos `data-*` (texto plano), nunca dentro de expresiones. Pruebas: ningún `x-*` renderizado contiene algo distinto de un identificador, ningún dato de usuario aparece en atributos `x-*`/`data-*`, no hay `<script>` en línea ni manejadores `on*`. Verificado con `npm run build` y un smoke test en jsdom (fuera del repo) de menú lateral, modal (abrir/cerrar/Escape), confirmación de formularios y alternancia 2FA. _No verificado:_ navegador real con la cabecera CSP aplicada (queda para QA del Sprint 6).
32. **Listados:** `TicketListingService` siempre parte de `visibleTo`, pagina (15) y hace eager loading (`category`, `team`, `assignments.user`); hay pruebas de conteo constante de consultas. Filtros validados por `IndexTicketsRequest` (estado, prioridad, categoría, responsable, equipo, `overdue`, `unassigned`, `q`, `sort` en lista blanca, `direction`, `page`); ningún filtro puede ensanchar el alcance. Búsqueda `q` sobre título y folio con `LIKE ... ESCAPE '!'` (portable MySQL/SQLite; el término solo viaja como binding). Orden por prioridad/estado con `CASE` parametrizado (orden de negocio, no alfabético) y fecha límite con nulos al final. "Mis pendientes" = asignado al usuario (responsable o colaborador), no final, ordenado por vencimiento (sin fecha al final) y prioridad; vive en `TicketListingService::pendingFor()`, listo para sumar actividades en el Sprint 3.
33. **Ambigüedades resueltas con lo más simple:** (a) la categoría del ticket es **opcional** (si no hay categorías cargadas nadie quedaría bloqueado); (b) el jefe, que no tiene equipo, elige el equipo al crear; coordinadores y empleados siempre usan el suyo (un `team_id` enviado se ignora); (c) el creador edita solo en Pendiente; los gestores del alcance editan cualquier ticket no final; los tickets finales no se editan; (d) eliminar (soft delete) solo tickets Pendientes o Cancelados; lo demás se cancela; (e) categorías: no se borran si tienen tickets (incluso eliminados), se desactivan; un ticket conserva su categoría aunque se desactive; (f) un equipo con tickets (incluso eliminados) no se puede eliminar (`TeamService`, FK `restrict`); (g) FKs de tickets/asignaciones/historial/comentarios/adjuntos a usuarios y equipos en `restrict` (los usuarios nunca se borran, solo se inactivan); (h) límites de tasa por usuario en `config/tickets.php`: crear ticket 30/h, escrituras 60/min, comentarios 20/min, subidas 10/min, descargas 60/min (`RateLimiter::for`); (i) fecha límite: al crear no puede ser anterior a hoy (hora de negocio); al editar se conserva la que ya tenía aunque haya vencido; (j) `DemoDataSeeder` (solo local/testing) ahora crea 4 categorías y tickets de ejemplo en todos los estados y equipos usando los mismos servicios (folio, historial y bitácora reales); es idempotente por el prefijo `[Demo]`. Cambios a pruebas del Sprint 1 por decisiones legítimas: las dos que exigían `'unsafe-eval'` en la CSP (`SecurityHeadersTest`, `SecureConfigurationTest`) ahora exigen su ausencia, y `SeedersAndCommandsTest` ya no exige que coordinador/empleado tengan 0 permisos (tienen los de tickets, ninguno de administración). _Pendiente de entorno:_ MySQL 8 (índices, `FOR UPDATE`, `INSERT IGNORE` del contador de folios) y navegador real; correr `php artisan migrate` y el seeder de permisos en cada entorno.

**2026-09-24 — Sprint 3 (actividades)**

34. **Estados de las actividades: se reutiliza `TicketStatus` tal cual (no existe `ActivityStatus`).** La sección 6 dice que tickets y actividades comparten estados y grafo; un enum gemelo solo duplicaría la máquina. El algoritmo de transición se extrajo de `TicketService` a `App\Services\StatusTransitioner` (bloquea la fila, re-autoriza contra el estado fresco, valida la arista, exige comentario en rechazo y reapertura, escribe `status_histories` + bitácora `status_changed` en una transacción) y lo usan `TicketService` y `ActivityService`; acepta un `guard` opcional para reglas propias del tipo. `App\Models\Concerns\HasWorkflow` comparte `scopeOverdue`/`scopeOpen`/`isOverdue` (vencido sigue siendo un cálculo) y `App\Support\WorkflowSubject` deriva el nombre de bitácora y el grupo de traducciones (`tickets.*`/`activities.*`) del tipo. Los 731 tests anteriores siguen verdes con este refactor.
35. **Modelo de datos.** `activities` (folio `ACT-AAAA-0001` único con el mismo `FolioGenerator`, `recurrence_rule` JSON nulo, `parent_activity_id` autorreferencia `restrict`, `occurrence_date`, `softDeletes`; FKs a categoría/equipo/usuario en `restrict`) y `subtasks` (`activity_id` en `cascade`, `assigned_to` en `nullOnDelete`). Índices: `status`, `priority`, `due_date`, `(team_id, status)`, `created_at`, `(activity_id, done)` y el ÚNICO `(parent_activity_id, occurrence_date)` (cubre también las búsquedas por padre y hace idempotente la generación; las filas normales tienen `occurrence_date` nulo y no chocan). Sin migraciones nuevas para `assignments`, `status_histories`, `comments` ni `attachments` (polimórficas, alias `activity` ya reservado en `MorphMap`, ahora apunta al modelo). Nota SQLite: un cast `date` se guarda como `Y-m-d 00:00:00`; las comparaciones por día de `occurrence_date` usan `whereDate` para que se comporten igual en SQLite (pruebas) y MySQL.
36. **Permisos y alcance.** Permisos Spatie nuevos e idempotentes: `activities.view` y `activities.work` (jefe, coordinador, empleado); `activities.create`, `activities.assign`, `activities.review` y `activities.manage` (jefe y coordinador). `Activity::scopeVisibleTo` es la única definición de lectura: jefe todas; coordinador las de su equipo (`users.team_id`) más las que se le asignaron explícitamente; empleado solo las asignadas a él (responsable o colaborador) o con una subtarea asignada a él, y NUNCA plantillas de recurrencia; pendiente/inactivo/sin rol ninguna. Fuera de alcance responde 404; con alcance sin permiso, 403. `ActivityPolicy::viewAny` exige `activities.manage`: el listado y su menú son de jefe/coordinador, el empleado llega a lo suyo por "Mis pendientes" y por el detalle. Crear/editar/eliminar/asignar: jefe (todas) y coordinador (su equipo; asigna solo a activos de su equipo). Las actividades NO tienen bolsa, "tomar" ni "devolver a la bolsa": siempre responsable único + colaboradores (no hay ruta para ello).
37. **Subtareas y avance.** Quién marca hecha/no hecha (`SubtaskPolicy::markDone`, con `activities.work`): quien gestiona la actividad, el RESPONSABLE de la actividad (cualquier subtarea, porque responde por su avance) o la persona a la que se asignó ESA subtarea; un colaborador sin subtarea propia no marca las ajenas. Crear/editar/eliminar subtareas: gestores del alcance. El responsable de una subtarea debe ser un usuario activo con rol asignado a la actividad o de su equipo (`SubtaskService`). Tope de 50 subtareas por actividad (`tickets.max_subtasks`). Una actividad final (Completado/Cancelado) rechaza todo cambio de subtareas (hay que reabrirla); en una plantilla se editan pero no se marcan. Avance = `floor(hechas × 100 / total)` (0 % sin subtareas; nunca llega a 100 % con algo pendiente), calculado con subconsultas (`Activity::scopeWithProgress`, sin cargar subtareas ni N+1). Regla de consistencia: no se puede pasar a **En revisión** ni **Completado** con subtareas pendientes (rechazar y cancelar sí, en cualquier momento). Marcar es idempotente (`done` viaja explícito; sin cambio real no se registra) y cada cambio queda en la bitácora de la actividad (`subtask_added|updated|removed|done|undone`).
38. **Regla de recurrencia (`App\Support\RecurrenceRule`, valor puro sin dependencias; Carbon, sin librerías nuevas).** JSON `{frequency: daily|weekly|monthly, interval: 1..52, days_of_week: [1..7 ISO, lunes=1], day_of_month: 1..31, ends_at: Y-m-d|null}`; las llaves que no aplican a la frecuencia se descartan. Diaria: cada `interval` días desde el inicio. Semanal: cada `interval` semanas ISO contadas desde la semana del inicio, en los días indicados (sin días: el día de la semana del inicio). Mensual: cada `interval` meses en `day_of_month` (sin él: el día del inicio); en meses más cortos se usa el ÚLTIMO día del mes (31 → 30/28/29) y cada mes se calcula desde la regla, sin "arrastrar" un 28 a marzo. `ends_at` es inclusive; nunca hay ocurrencias anteriores a `start_date`, que es obligatoria en una actividad recurrente. Validada por Form Request (`ValidatesRecurrence`) solo cuando la actividad es o será recurrente; en la edición solo una PLANTILLA la edita (y es obligatoria).
39. **Modelo madre/instancia y generación (`RecurrenceService`, comando `activities:generate-recurring`).** La actividad con `recurrence_rule` es la PLANTILLA (badge "Recurrente (plantilla)", oculta al empleado, excluida de "Mis pendientes", solo se cancela/reabre/edita; sus subtareas no se marcan); cada ocurrencia es una actividad hija (`parent_activity_id` + `occurrence_date`, badge "Instancia de ACT-…") con su propio folio, estado, historial, asignaciones copiadas, subtareas copiadas y reiniciadas (`done = false`) y fecha límite = ocurrencia + (límite − inicio) de la plantilla; se gestiona de forma independiente. Ventana = de HOY (hora de Cancún, no UTC) a HOY + `RECURRENCE_HORIZON_DAYS` (14 por defecto, en `config/tickets.php`), ambos inclusive; NO se rellenan ocurrencias pasadas. Idempotente: bajo `lockForUpdate` de la plantilla se comprueba que la ocurrencia no exista (incluida una instancia eliminada, que no se regenera) y el índice único frena una carrera; correr el comando dos veces no duplica. La plantilla cancelada o eliminada no genera; reabrirla reanuda. Asignados que ya no están activos se omiten y queda `assignee_skipped` en la bitácora; si el inactivo es el responsable, la instancia nace sin responsable pero conserva colaboradores (se reasigna a mano). El folio usa el año del momento de generación. Al crear una actividad recurrente se generan de inmediato sus primeras instancias; **editar la plantilla cambia la regla y los datos que heredarán las instancias futuras, no toca las ya generadas ni genera al instante** (las ya creadas dentro del horizonte con la regla anterior se cancelan o eliminan a mano). Un fallo en una plantilla se reporta y no detiene a las demás (el comando termina con código 1). Programado en `routes/console.php`: diario 02:00 hora de Cancún (`RECURRENCE_SCHEDULE_AT`), `withoutOverlapping()`, una sola entrada de cron (`schedule:run` cada minuto en el servidor).
40. **Comentarios, adjuntos y asignación generalizados.** `CommentService`, `AttachmentService` y `AssignmentService` aceptan `Ticket|Activity` (bitácora y mensajes según el tipo, sin copiar código); `unassign`/`take` siguen siendo solo de tickets. Los Form Requests de asignar, transicionar, comentar y adjuntar resuelven el modelo de la ruta (`ResolvesWorkItem`: `{ticket}` o `{activity}`). `AttachmentPolicy` resuelve el padre por la relación polimórfica y exige poder VER ese padre (ticket o actividad); si el padre no existe (eliminado) nadie descarga; borrar = autor o gestor del tipo (`tickets.manage`/`activities.manage`). Los adjuntos de actividades van en `activities/{id}/…` (los ids de tickets y actividades pueden coincidir, así que nunca comparten directorio); el tope `max_per_ticket` (10) aplica a ambos. Pruebas de IDOR cruzado (adjunto de ticket con el mismo id que una actividad visible, y al revés). Vistas: `history-panel`, `comments-panel` y `attachments-panel` (componentes compartidos que también usa `tickets/show`; el permiso de borrar adjuntos se calcula una vez por página, no por adjunto). `ValidatesTicketFields` pasó a `ValidatesWorkItemFields`. Los listados comparten `App\Support\ListingQuery` (búsqueda con LIKE escapado y orden por enum/fechas nulas al final).
41. **"Mis pendientes" combinado = dos secciones claras** (Tickets y Actividades) en la misma vista `tickets.pending` (ruta y textos sin renombrar), cada una paginada por su cuenta (`page` y `activities_page`, sin N+1). Actividades: abiertas (no finales), sin plantillas, asignadas al usuario (responsable o colaborador) o con una subtarea suya SIN hacer, ordenadas por vencimiento (sin fecha al final) y prioridad; un coordinador ve solo lo asignado a él, no todo su equipo. Sidebar: sección "Trabajo" (antes "Tickets") con Mis pendientes y Tickets para todos, y Actividades solo para jefe/coordinador.
42. **Límites de tasa por usuario** (`RateLimiter::for`, valores en `config/tickets.php`): crear actividad 30/h (`activity-create`), escrituras 60/min (`activity-write`, incluye subtareas, asignación, transición, edición y borrado), comentarios 20/min y subidas 10/min; las descargas comparten `attachment-download`.
43. **Ambigüedades resueltas con lo más simple.** (a) La descripción es obligatoria (igual que en tickets) y la categoría opcional; (b) el jefe elige el equipo al crear, el coordinador usa el suyo (un `team_id` enviado se ignora); (c) el responsable y los colaboradores se eligen al crear y luego se cambian desde el detalle; (d) solo se eliminan (soft delete) actividades Pendientes o Canceladas; (e) la fecha límite de una actividad normal no puede ser anterior a hoy (al editar se conserva la ya guardada) y la de una recurrente solo debe seguir a su inicio; (f) el avance se muestra con `<progress>` nativo más texto, sin estilos en línea; (g) `DemoActivitiesSeeder` (solo local/testing, idempotente por el prefijo `[Demo]`, invocado desde `DemoDataSeeder`) crea con los mismos servicios una serie semanal con instancias y, por equipo, actividades en curso (avance parcial), completada, vencida sin subtareas y cancelada. Cambios a pruebas anteriores por decisiones legítimas: `SeedersAndCommandsTest` (matriz de permisos y conteos: empleado 5, coordinador 12, por los permisos de actividades). Despliegue: correr `php artisan migrate` y `php artisan db:seed --class=RolesAndPermissionsSeeder --force`, y comprobar que exista la entrada de cron de `schedule:run`. _No verificado:_ MySQL 8 (columna JSON, índice único con nulos, `whereDate`, `FOR UPDATE` real), concurrencia real de dos procesos generando a la vez, navegador real con la CSP aplicada (el editor de recurrencia usa `Alpine.data('recurrenceEditor')`, sin `unsafe-eval`; verificado con pruebas de HTML y build) y cron/Supervisor reales.

**2026-09-25 — Sprint 5 (panel de seguimiento, bitácora y exportación)**

44. **Permisos, habilidades y rutas.** Permisos Spatie nuevos e idempotentes en `RolesAndPermissionsSeeder`: `dashboard.view` (jefe y coordinador), `audit.view` (solo jefe) y `exports.create` (jefe y coordinador; el empleado no exporta). Habilidad `view-dashboard` = `DashboardPolicy::view` (no hay modelo asociado, se registra con `Gate::define` en `AppServiceProvider`) y `AuditLogPolicy::viewAny` registrada con `Gate::policy` para el modelo de Spatie (`Spatie\Activitylog\Models\Activity`, importado con alias `ActivityLogEntry` para no chocar con `App\Models\Activity`); `TicketPolicy::export` (permiso `exports.create` + `tickets.view`). Rutas dentro del grupo `auth` + `account.active` + `two-factor`: `GET /tracking` (`tracking.index`, `throttle:dashboard` 30/min por usuario), `GET /audit-log` (`audit.index`, `throttle:audit-view` 60/min) y `GET /tickets/export` (`tickets.export`, `throttle:export` 10/h; declarada antes de `tickets/{ticket}`). `/dashboard` (Breeze) sigue siendo el inicio de todos: el empleado aterriza ahí (con su enlace a "Mis pendientes") y jefe/coordinador ven además un enlace al panel; el panel es una ruta aparte para no cambiar el aterrizaje ni las pruebas existentes. Sidebar: sección "Seguimiento" (panel) solo con `view-dashboard` y enlace "Bitácora" solo con `audit.view`; ocultar el enlace es cosmético, la Policy es la barrera (403 para quien no tiene el permiso). Los Form Requests del sprint autorizan ANTES de validar (`AuthorizesWithGate`): un empleado recibe 403 aunque mande filtros inválidos, sin aprender las reglas. Cambio a pruebas previas por decisión legítima: `SeedersAndCommandsTest` (el coordinador pasa de 12 a 14 permisos; la matriz por rol incluye `dashboard.view`/`exports.create` y comprueba que `audit.view` es solo del jefe). Textos de inicio: `common.dashboard.coming_soon` ahora apunta a "Mis pendientes".
45. **Alcance del panel: una sola definición (`DashboardScope`).** Jefe = global (el filtro `team_id` lo estrecha); coordinador = SOLO su equipo: parte de `visibleTo` (Ticket/Activity) y lo restringe a su `users.team_id`, de modo que lo que se le asignó explícitamente fuera de su equipo (que `visibleTo` sí incluye para que pueda abrirlo) NO cuenta en el panel del equipo; empleado, pendiente, inactivo o sin rol = nada (además 403 por Policy). El filtro `team_id` de un coordinador que no sea su equipo falla la validación (422/redirección con error) y, si la validación se saltara, `DashboardScope` lo ignora igualmente; `user_id` de un coordinador debe ser un integrante de su equipo (validado con `Rule::exists` acotado); ningún filtro (equipo, persona, categoría) puede ensanchar el alcance porque la consulta siempre parte de `visibleTo`. Selectores: el equipo solo lo ve el jefe; personas = activas con rol (jefe) o integrantes activos de su equipo (coordinador). La tabla de carga por persona lista a quien tenga asignado trabajo dentro del alcance (un coordinador puede ver ahí a alguien de otro equipo que tenga asignado un ticket de su equipo). Se cuentan tickets Y actividades con desglose por tipo; plantillas de recurrencia y registros eliminados (soft delete) no cuentan.
46. **Definición de cada métrica (`DashboardMetricsService`).** Periodo = dos fechas de calendario en hora de negocio (America/Cancun), ambas inclusivas, por defecto los últimos 30 días terminando hoy, tope de 366 días (`tickets.dashboard.*`), `to` no puede ser futura y `from <= to`; se convierten a un intervalo semiabierto en UTC (`LocalTime::storageBoundary`). Tarjetas: **Abiertos** = estado no final, **En revisión**, **Vencidos** (`due_date < hoy` y no final; cálculo, nunca estado) son una FOTO de hoy y no dependen del periodo (sí de los filtros); **Completados** = estado Completado con `completed_at` dentro del periodo (una reapertura limpia `completed_at`, así que no cuenta). Gráfica por estado y por prioridad = registros CREADOS en el periodo según su estado actual (el texto de la gráfica lo dice). Tendencia = creados vs completados por día local (por semana ISO —lunes— si el periodo supera 45 días); los días sin datos salen en cero. Carga por persona = asignaciones (responsable o colaborador) sobre lo abierto hoy: tickets abiertos, actividades abiertas, en revisión, vencidos; hasta 50 filas (`max_employee_rows`) y se avisa si hay más personas. Tiempo promedio de cierre = promedio de `completed_at - created_at` de lo completado en el periodo, por categoría (incluye "Sin categoría"), por equipo y general; el promedio de tickets+actividades sale de suma/cuenta, no de promediar promedios. Todo son agregados (COUNT/SUM/GROUP BY con `toBase()`, sin hidratar modelos ni cargar registros completos): unas 2 consultas por tipo y métrica (una prueba exige que el número de consultas no crezca con los datos y sea <= 30).
47. **Expresiones SQL portables y agrupación por día (`App\Support\SqlExpressions`).** El tiempo de cierre se promedia EN LA BASE con `strftime('%s')` (SQLite) o `timestampdiff(second)` (MySQL/MariaDB) sobre columnas fijas del código; la agrupación por día local usa `date(col, '-300 minutes')` / `date(date_add(col, interval -300 minute))`. Reglas: las columnas se validan con una lista estricta (`^[a-z_]+\.[a-z_]+$`) y el desfase es un entero calculado en PHP; nunca entra texto del usuario. El desfase es fijo (el del último día del periodo): correcto para America/Cancun (sin horario de verano); con una zona con horario de verano, lo cercano al cambio de hora podría caer un día corrido. Un driver distinto de SQLite/MySQL/MariaDB lanza excepción. Índices nuevos (migración `2026_09_25_100100`, reversible): `(status, completed_at)` y `(team_id, created_at)` en `tickets` y `activities`; `created_at` y `event` en `activity_log`.
48. **Cache del panel.** `Cache::remember` por 60 s (`DASHBOARD_CACHE_TTL`, `0` = sin cache; en el driver `database` del servidor) con la llave `dashboard:report:v1:` + sha1 de {id de usuario, rol, `team_id`, día local, filtros}. Como la llave incluye usuario, rol y equipo, un resultado nunca se sirve a otro alcance (probado: dos coordinadores, jefe vs coordinador, cambio de rol/equipo del mismo usuario cambian la llave). Solo se cachean arreglos de escalares (nunca modelos). Consecuencia aceptada: hasta 60 s de retraso en las cifras (la vista muestra la hora de cálculo).
49. **Chart.js (justificación, Regla 6).** Se eligió `chart.js` 4.5 (solo `devDependencies`, se empaqueta con Vite en un chunk aparte que `app.js` importa dinámicamente SOLO si la página trae un `<canvas data-chart>`; ~63 KB gzip, tree-shaking de los controladores usados: línea, barras y dona). Motivos: es la opción que CLAUDE.md sección 3 deja abierta ("Chart.js o ApexCharts"), es menor y más estable que ApexCharts, dibuja en `<canvas>` sin inyectar scripts ni `eval` y sin CDN. CSP sin cambios (`script-src 'self'`, sin `unsafe-eval`, sin scripts en línea; el chunk sale de `/build`, mismo origen). Los datos viajan en UN atributo `data-chart` con JSON plano (tipo, paleta, etiquetas traducidas y números) escapado con `{{ }}`; `resources/js/charts.js` lo lee con `JSON.parse` en `try/catch` y jamás evalúa texto. Accesibilidad: cada gráfica es una `<figure>` con `role="img"`, `aria-label`, leyenda y una tabla equivalente en un `<details>`; los colores de la línea se refuerzan con trazo/forma distinta; respeta `prefers-reduced-motion`. Mobile-first: rejillas de 1 columna, tablas con desplazamiento horizontal, columnas secundarias ocultas bajo `sm`/`md`. Componentes nuevos: `x-kpi-card`, `x-chart-card`, `x-duration`; `x-secondary-button` ahora acepta `href`. Textos en `lang/es/tracking.php`, `audit.php` y `tickets.export.*`.
50. **Visor de bitácora (solo jefe, solo lectura).** `AuditLogService` pagina 25 por página (`audit_per_page`) con `causer` y `subject` cargados por lotes (constante en consultas, probado). Filtros validados por `IndexAuditLogRequest`: usuario (`causer_id`), evento (solo `[a-z0-9_]`, máx. 60), tipo de registro (lista blanca `ticket|activity|user|team|category` mapeada a los alias del morph map; hay una prueba que la mantiene alineada con `MorphMap`), rango de fechas en días de negocio y búsqueda (LIKE con escape sobre descripción, evento, nombre de log, `properties` y `attribute_changes`). Se excluyen las filas con `subject_type`/`causer_type` fuera del mapa (no se pueden hidratar con el morph map forzado). Todo se imprime escapado; los valores anteriores/nuevos y las propiedades pasan antes por `AuditPayload::redact` (lista negra defensiva por fragmentos —password, secret, token, recovery, two_factor, cookie, authorization...— y llaves cortas exactas —code, otp, pin, key, hash—; se ELIMINA la llave, a cualquier profundidad) aunque `AuditLogger` ya descarte secretos al escribir, y cada valor se acota a 300 caracteres. Sujeto eliminado o inexistente se muestra como `#id`; eventos del sistema sin causante como "Sistema". No hay ninguna ruta ni habilidad de escritura sobre la bitácora (probado: 405 y Policy).
51. **Exportación a Excel (Regla 6: `maatwebsite/excel` 4.0.3).** Justificación: es el paquete que CLAUDE.md sección 3 fija para el Sprint 5 ("solo Excel básico"); la versión 4.0 declara compatibilidad con Laravel 13 y PHP 8.3+ y se instaló sin conflictos (arrastra `phpoffice/phpspreadsheet` 5.10, `maennchen/zipstream-php`, `markbaker/*`, `composer/pcre` y `composer/semver`; `composer audit` sin avisos). Requisito de servidor: extensiones PHP `gd`, `zip` y `xml` (Ubuntu 24.04: `php8.3-gd php8.3-zip php8.3-xml`). Alcance: SOLO el listado de tickets (`/tickets/export`), con los mismos filtros, alcance (`visibleTo`) y orden que `/tickets` (`TicketListingService::limited`), hasta 5000 filas (`EXPORT_MAX_ROWS`; se pide una de más para avisar y auditar el recorte). El formato sale del enum `ExportFormat` (hoy solo `xlsx`) validado en el Form Request; el nombre del archivo lo genera el servidor (`tickets-AAAAMMDD-HHMMSS.xlsx`) y nada de la petición llega a rutas ni plantillas. Protección contra inyección de fórmulas en dos capas: `StringValueBinder` (toda celda es texto, nunca fórmula) y `SpreadsheetSanitizer` (antepone `'` a todo texto que empiece por `=`, `+`, `-`, `@`, tabulador o retorno de carro, también tras espacios o saltos iniciales); prueba que lee el `.xlsx` generado y comprueba el tipo de celda y que el XML no tiene `<f>`. No se exporta la descripción. Respuesta con `Cache-Control: private, no-store` y `X-Content-Type-Options: nosniff`. Cada exportación registra el evento `exported` (log `exports`, sin sujeto; quién, tipo, formato, filas, recorte y filtros usados) y solo si se generó el archivo; las peticiones rechazadas (403/422) no dejan entrada. Las actividades y las tablas del panel no se exportan (fuera de alcance de este sprint); PDF sigue fuera del MVP.
52. **Datos demo, despliegue y lo NO verificado.** `DemoTrackingSeeder` (solo local/testing, invocado desde `DemoDataSeeder`, determinista e idempotente, sin bitácora) reparte en ~3 semanas las fechas de creación/cierre de los tickets y actividades demo (sin tocar plantillas ni instancias) para que el panel muestre tendencia, vencidos, carga y tiempos de cierre. Despliegue: `composer install` (ahora con `maatwebsite/excel`; instalar `php-gd`, `php-zip`, `php-xml`), `npm ci && npm run build` (chunk `charts-*.js`), `php artisan migrate --force` (índices nuevos) y `php artisan db:seed --class=RolesAndPermissionsSeeder --force` (permisos `dashboard.view`, `audit.view`, `exports.create`; sin esto el panel, la bitácora y la exportación responden 403 a todos, jefe incluido). `.env.example` suma `DASHBOARD_CACHE_TTL` y `EXPORT_MAX_ROWS`. _No verificado:_ MySQL 8 (las expresiones `timestampdiff`/`date_add`, el LIKE sobre columnas JSON de la búsqueda de la bitácora, los índices nuevos y el plan de ejecución real; en SQLite todo pasa); navegador real con la CSP aplicada (Chart.js dibujando, `<details>`, 360 px; solo se verificó el HTML, la ausencia de scripts en línea/`unsafe-eval`, el build de Vite y el JSON de cada gráfica); rendimiento con volumen (miles de tickets, ~100 usuarios: queda para el Sprint 6; la prueba solo asegura que el número de consultas es constante); apertura real de los `.xlsx` en Excel/LibreOffice (se releen con PhpSpreadsheet); y cobertura numérica (no hay Xdebug/PCOV local).

**2026-09-25 — Exportación de actividades (Sprint 5, continuación)**

53. **Exportación a Excel de actividades (mismo patrón que la de tickets, decisión 51).** `GET /activities/export` (`activities.export`, declarada antes del resource), `throttle:export` (el MISMO contador por usuario que la de tickets: 10 por hora entre ambas), `ActivityPolicy::export` = permiso `exports.create` + `activities.manage` (jefe y coordinador; el empleado recibe 403 antes de validar, igual que `/activities`, que tampoco puede ver). `ExportActivitiesRequest` extiende `IndexActivitiesRequest` (mismos filtros, sin `page`) más `format` del enum `ExportFormat`, con `AuthorizesWithGate`. `ActivityListingService` se refactorizó como el de tickets (`filtered()` compartida con `paginate()` y `limited()`, sin cambio de comportamiento): mismo alcance (`Activity::visibleTo`, con lo asignado al coordinador fuera de su equipo incluido, igual que el listado), filtros y orden; muestra exactamente lo que el listado (las plantillas se incluyen salvo que el filtro `kind` las excluya; hay una prueba que compara el orden y los folios contra `/activities`). Sin duplicar lógica: `ExportDownloader` concentra tope de filas (`EXPORT_MAX_ROWS`, con aviso de recorte y bitácora), nombre generado por el servidor (`activities-AAAAMMDD-HHMMSS.xlsx`), cabeceras `no-store`/`nosniff` y el evento `exported` (`type` = `tickets` o `activities`); `TicketExportService` y `ActivityExportService` solo aportan sus filas y su hoja; el trait `BuildsSheetRows` comparte `StringValueBinder`, fechas locales y nombres de responsable/colaboradores entre `TicketsExport` y `ActivitiesExport`. Columnas: folio, título, estado, prioridad, categoría, equipo, responsable, colaboradores, fecha de inicio, fecha límite, avance (%), vencida (Sí/No), recurrencia (Normal / Plantilla / Instancia de ACT-…) y creada; sin descripción; textos de usuario por `SpreadsheetSanitizer` y celdas siempre texto. Sin N+1: `baseQuery` ya carga categoría, equipo, padre y asignaciones/usuarios y calcula el avance con subconsultas (`withProgress`); la prueba exige el mismo número de consultas con 12 actividades más (la carga de `parent` solo corre si hay alguna instancia, por lo que la línea base incluye una). Vista: botón "Exportar a Excel" en `activities/index` con los filtros actuales y aviso de truncado si el total supera el máximo. Sin migraciones ni permisos nuevos (reutiliza `exports.create`); no requiere volver a correr el seeder si ya se corrió el del Sprint 5. _No verificado:_ igual que la decisión 52 (MySQL 8 y apertura real de los `.xlsx` en Excel/LibreOffice).

54. **"Mis pendientes": alternar entre lo propio y lo del equipo (solo coordinadores).** `?scope=mine|team` (enum `PendingScope`, validado en `PendingTicketsRequest` con `Rule::in`; un valor desconocido falla la validación). `mine` es el valor por defecto y no cambia nada de lo anterior. `team` solo lo obtiene un coordinador activo con `team_id` (`PendingScope::canUseTeam`, única definición; `PendingScope::resolve` la aplica en el request y los dos servicios la repiten como defensa en profundidad): para empleado, jefe (no tiene equipo), coordinador sin equipo o cuenta inactiva el parámetro se IGNORA y ven lo propio, así que no ensancha ningún alcance. Qué muestra `team`: lo abierto (no final) del equipo del coordinador (`team_id` propio), asignado o no —incluye la bolsa sin asignar—, tickets y actividades (estas sin plantillas de recurrencia), con el mismo orden por fecha límite (sin fecha al final) y prioridad, siempre dentro de `visibleTo`; lo asignado al coordinador en OTRO equipo sigue apareciendo solo en `mine` (igual que en el panel, decisión 45). Interfaz: control de dos enlaces GET ("Mis pendientes" / "Mi equipo") dentro de un `<nav>` con `aria-current` en la vista activa, sin JavaScript ni cambios de CSP; solo se muestra a quien puede usarlo; la paginación de cada sección conserva el `scope` (`withQueryString`); textos e intro propios para la vista de equipo. Sin migraciones ni permisos nuevos. Pruebas: `tests/Feature/Tickets/PendingScopeToggleTest.php`.
