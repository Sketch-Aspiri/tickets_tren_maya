# Plan: escalar el sistema a varias estaciones y coordinaciones

## Contexto

Hoy el sistema sirve a **una sola coordinación**. La única unidad organizacional es `Team`, y `administrador` y `jefe_zona` tienen alcance **global** (`UserRole::hasGlobalScope()`). No existe ningún modelo de estación, coordinación ni zona.

Algunas cosas suponen una sola unidad:
- Hay un único buzón IMAP.
- La bandeja de correos entrantes la ven completa todos los coordinadores.
- Las notificaciones de "registro pendiente" y "correo nuevo" van a todos los jefes.
- Los equipos y las categorías tienen nombre único global.
- El panel y la bitácora muestran todo al jefe.

El objetivo es que el sistema sirva a varias coordinaciones ubicadas en distintas estaciones o edificios, con las reglas que confirmaste:

| Tema | Decisión |
|---|---|
| Jerarquía | **Estación/Edificio → Coordinación → Equipo → Empleados** |
| Aislamiento | Cada coordinación ve **solo lo suyo**, pero **se puede mandar un ticket a un equipo de otra coordinación** (ya funciona hoy con `ticketTeamIdFor`) |
| Mando | **Varios jefes de zona**, cada uno supervisa **una o varias coordinaciones**. El **administrador** sigue siendo global |
| Correo | **Un buzón por coordinación**, con su propia bandeja de entrada |

Valores por defecto que propongo (anotarlos en el Registro de decisiones):
- **Folios:** siguen siendo globales (`TM-AAAA-0001`, `ACT-AAAA-0001`). Son únicos, no cambian enlaces ni el chat, y la coordinación se ve en el ticket.
- **Categorías:** las actuales quedan globales y cada coordinación puede tener las suyas (`categories.coordination_id` nullable; `null` = global).
- **Chat directo:** se sigue pudiendo buscar a cualquier usuario activo. Hace falta para coordinar tickets entre coordinaciones. Los canales de equipo no cambian.
- **Excel en la nube:** sigue siendo global (lo opera TI y el administrador). Un archivo por coordinación queda en backlog.
- **Registro:** el formulario pide **coordinación** (un select agrupado por estación). Así la solicitud llega al jefe correcto. Riesgo aceptado: los nombres de estaciones y coordinaciones quedan visibles en la página pública de registro.

---

## Modelo de datos (nuevas migraciones, reversibles)

1. **`stations`**: `id, name (unique), active, timestamps`.
2. **`coordinations`**: `id, station_id (FK restrict), name, code (unique, p. ej. "TI-MER"), active, timestamps`, con `unique(station_id, name)`.
3. **`coordination_user`** (los jefes de zona y sus coordinaciones): `coordination_id (cascade), user_id (cascade), PK compuesta, índice user_id`.
4. **`teams.coordination_id`** (FK restrict). La migración:
   - crea una estación y una coordinación por defecto ("Estación principal" / "Coordinación principal", que luego se renombran desde la UI);
   - les asigna todos los equipos existentes;
   - pone la columna como `NOT NULL`;
   - cambia `teams.name unique` por `unique(coordination_id, name)`.
5. La misma migración (o la siguiente) inserta a **todos los jefes activos** en `coordination_user` de la coordinación por defecto. Así nadie pierde visibilidad el día del despliegue.
6. **`categories.coordination_id`** nullable (FK restrict). `name unique` pasa a `unique(coordination_id, name)`.
7. **`incoming_emails.coordination_id`** (FK restrict, backfill a la coordinación por defecto) más un índice `(coordination_id, status)`.
8. **`coordination_mailboxes`**: `coordination_id (unique), host, port, encryption, username, password (cast encrypted), folder, active, last_polled_at, last_error`. El primer buzón se siembra desde los `IMAP_*` actuales del `.env`.
9. **`activity_log.coordination_id`** nullable con índice, para poder acotar la bitácora (fase 4).
10. **`users.requested_coordination_id`** nullable: la coordinación que el usuario eligió al registrarse.

Tickets y actividades **no** llevan `coordination_id` desnormalizado. La coordinación se deriva de `team_id`, ya indexado, con una subconsulta `whereIn('team_id', …)`. Así no hay dos fuentes de verdad.

Modelos nuevos: `Station`, `Coordination`, `CoordinationMailbox`. Hay que agregarlos a `App\Support\MorphMap` (con alias `station`, `coordination`) porque el mapa es forzado. Llevan `$fillable` explícito y `LogsActivity`.

---

## Núcleo: una sola definición de alcance organizacional

Hoy el alcance global se decide en unos 12 lugares con `seesAllTeams()` o `match` sobre `UserRole::JefeZona`. Se concentra en `User`:

- `UserRole::hasGlobalScope()` pasa a devolver `true` **solo para `Administrador`**. `JefeZona` deja de ser global.
- Nuevo `User::coordinationIdsQuery()`:
  - **jefe**: sus filas de `coordination_user`;
  - **coordinador y empleado**: las coordinaciones de sus equipos.
- Nuevo `User::scopedTeamIdsQuery(): ?QueryBuilder`, la **única definición** de "equipos que gestiono":
  - **administrador**: `null` (todos);
  - **jefe**: equipos cuyo `coordination_id` está en sus coordinaciones;
  - **coordinador y empleado**: `teamIdsQuery()` (sin cambio).
- `seesAllTeams()` sigue existiendo pero ahora significa "administrador". `selectableTeams()`, `workTeamIdFor()` y `mustChooseTeam()` usan `scopedTeamIdsQuery()`. Para un jefe: sus equipos de coordinación, agrupados por coordinación.
- `ticketTeamIdFor()` no cambia: cualquier equipo de cualquier coordinación, que es el "envío entre coordinaciones". El selector de `/tickets/create` agrupa los equipos por **Estación › Coordinación** (`<optgroup>`) y preselecciona el propio.

Lugares a migrar a esta definición. Todos ya existen, solo cambia la rama del jefe:
- `Ticket::scopeVisibleTo` y `Activity::scopeVisibleTo` (`app/Models/Ticket.php:150`, `Activity.php:192`): el jefe usa la misma regla que el coordinador, pero con `scopedTeamIdsQuery()` (equipos de sus coordinaciones + lo creado por él o asignado a él).
- `TicketListingService::filtered` / `ActivityListingService::filtered` (líneas 73/81) y `pendingFor` (`PendingScope::Team` también para el jefe).
- `AssignmentService::assignableUsers` / `assertAssignable` (líneas 45, 186, 193): el jefe asigna a integrantes de equipos de sus coordinaciones.
- `ValidatesWorkItemFields::newWorkItemTeamRules`, `DashboardScope` (71/86/105) y `DashboardMetricsService::cacheKey` (con `coordinationIds`).
- `IncomingEmailController::index` + `IncomingEmailPolicy`: nuevo `IncomingEmail::scopeVisibleTo` con `coordination_id IN coordinationIdsQuery()` (el administrador ve todo). Fuera de alcance responde **404**.
- `TeamPolicy`, `UserPolicy` (gestión y aprobación), `CategoryPolicy`, `UserService`, `TeamService`, `CategoryService`: el jefe gestiona solo equipos, usuarios y categorías de sus coordinaciones. Las globales (`coordination_id` null) solo las gestiona el administrador.
- Notificaciones de rol en `UserRegistrationService::notifyApprovers` e `IngestIncomingEmailsCommand`: van a los **jefes de esa coordinación**. Si no tiene jefe activo, a los administradores. Se centraliza en un nuevo `Coordination::approvers()`.
- `UserService::assertNotLastActiveOfRole`: el mínimo de uno activo se mantiene **solo para administrador** (`mustKeepOneActive`). Una coordinación sin jefe la cubre el administrador. El jefe sigue sin poder inactivarse a sí mismo.

Permisos nuevos (idempotentes en `RolesAndPermissionsSeeder`): `org.manage` (estaciones, coordinaciones y buzones) **solo para el administrador**. El jefe conserva `users.*`, `teams.manage`, `categories.manage` y `audit.view`, pero acotados por la Policy.

---

## Fases (sprints cortos, cada uno con pruebas verdes)

### Fase 1: Estructura sin cambio de comportamiento
- Migraciones 1–6 y 10, modelos, factories (`StationFactory`, `CoordinationFactory`; `TeamFactory` crea su coordinación), morph map.
- CRUD de **Estaciones** y **Coordinaciones** (solo administrador, `org.manage`): controlador delgado, `StationService`/`CoordinationService`, Form Requests y Policies. No se puede eliminar algo con equipos o hijos; se desactiva.
- Formulario de equipos: el campo **coordinación** es obligatorio.
- Aprobación de usuarios: para el rol `jefe_zona`, selector múltiple de coordinaciones (`ValidatesRoleAndTeam`, `UserService::approve/updateRoleAndTeams` sincronizan `coordination_user`). El jefe deja de exigir "sin equipo".
- En esta fase el jefe todavía es global. Solo se agrega estructura y se verifica el backfill.

### Fase 2: Aislamiento por coordinación (el cambio central)
- `hasGlobalScope()` = solo administrador, `scopedTeamIdsQuery()`/`coordinationIdsQuery()` y la migración de todos los lugares listados arriba.
- Pruebas IDOR entre coordinaciones: un jefe o coordinador de la coordinación A recibe **404** al abrir un ticket, actividad, adjunto, correo, equipo o usuario de B. Por listado: no aparece y no se exporta.
- Pruebas de "envío": un empleado de A manda un ticket a un equipo de B. El jefe y el coordinador de B lo ven en su bolsa. El creador lo sigue abriendo. El jefe de A **no** lo ve en su listado.
- Ajustar `tests/Concerns/BuildsTicketScenario`, `BuildsActivityScenario` y `BuildsDashboardScenario` con **dos coordinaciones en dos estaciones**, cada una con su jefe, y un administrador global. Esto implica revisar las pruebas que suponían "jefe = global": hoy el escenario tiene un único jefe global; lo que antes probaba el jefe pasa a probarlo el administrador.

### Fase 3: Un buzón por coordinación
- Migraciones 7–8. `MailboxClient` ahora se construye **por buzón** (`WebklexMailboxClient::forMailbox(CoordinationMailbox)`, cuenta dinámica de Webklex `ClientManager::make([...])`).
- `emails:ingest` recorre los buzones activos. Cada uno se procesa aislado: un fallo se registra en `last_error` y en la bitácora y no detiene a los demás (mismo patrón que `RecurrenceService`). La deduplicación pasa a `unique(coordination_id, message_id)`. Los límites por remitente y por corrida se aplican por buzón.
- UI de buzones (administrador): alta y edición, contraseña cifrada que **nunca se vuelve a mostrar** (el campo vacío conserva la actual) y botón "Probar conexión" con límite de tasa. Se valida que host y puerto no apunten a IPs privadas o locales (anti-SSRF).
- `IncomingEmailConversionController`: solo ofrece equipos de la coordinación del correo.
- Decisión abierta #8 (Microsoft 365 / OAuth) sigue vigente, ahora por buzón.

### Fase 4: Registro, panel, bitácora y textos
- Registro: select obligatorio de coordinación (solo activas, agrupadas por estación), guardado en `requested_coordination_id`. El jefe de esa coordinación ve la solicitud y recibe el aviso. El administrador ve todas.
- Panel `/tracking`: filtros **Estación** y **Coordinación** (solo dentro del alcance; un id ajeno falla la validación), más tarjetas o gráfica por coordinación para el administrador y para el jefe con varias.
- Bitácora: `AuditLogger` y `tapActivity` llenan `activity_log.coordination_id`, tomada del sujeto (ticket o actividad → equipo → coordinación; equipo o usuario) o, si no hay sujeto, de la coordinación del causante. El jefe ve solo sus coordinaciones y el administrador todo. Las filas históricas toman la coordinación por defecto.
- Textos en `lang/es` que dicen "el jefe de zona" en singular (`auth.php`, `account.php`, `profile.php`, `users.php`, `errors.php`, `activities.php`) y FAQ de Temayin (`lang/es/assistant.php`): pasan a "el jefe de zona de tu coordinación" o "el administrador".
- Sidebar: sección "Organización" (Estaciones, Coordinaciones, Buzones) solo con `org.manage`. Se muestra la coordinación del usuario en el pie del menú.

### Fase 5: Rendimiento y salida
- `DemoDataSeeder` con 3 estaciones, 5 coordinaciones, unos 100 usuarios y miles de tickets. Se revisan los planes de consulta de `visibleTo` con la subconsulta `teams.coordination_id` (índice `teams(coordination_id)`) y el número constante de consultas en listados y panel.
- Documentar el despliegue: `migrate --force`, `db:seed --class=RolesAndPermissionsSeeder --force`, renombrar la estación y la coordinación por defecto, revisar las coordinaciones asignadas a cada jefe, dar de alta los buzones y quitar los `IMAP_*` del `.env` cuando el buzón ya esté en la BD.
- Actualizar `CLAUDE.md`: secciones 1, 5 (matriz de permisos con columna Administrador y jefe acotado), 7, 9, 17 (nuevo sprint) y Registro de decisiones (69+).

---

## Archivos críticos
- `app/Enums/UserRole.php`, `app/Models/User.php` (núcleo de alcance), `app/Models/{Ticket,Activity,IncomingEmail,Team,Category}.php`
- `app/Services/{TicketListingService,ActivityListingService,AssignmentService,DashboardScope,DashboardMetricsService,UserService,TeamService,UserRegistrationService,EmailIngestionService,AuditLogService}.php`
- `app/Policies/{Team,User,Category,IncomingEmail,AuditLog}Policy.php`, `app/Http/Requests/Concerns/{ValidatesRoleAndTeam,ValidatesWorkItemFields}.php`
- `app/Infrastructure/WebklexMailboxClient.php`, `app/Console/Commands/IngestIncomingEmailsCommand.php`
- `database/seeders/RolesAndPermissionsSeeder.php`, `DemoDataSeeder.php`, `database/factories/{Team,User}Factory.php`, `tests/Concerns/Builds*Scenario.php`
- Nuevos: `Station`, `Coordination`, `CoordinationMailbox` (modelo, Service, Policy, Requests, controlador y vistas en `resources/views/admin/`)

## Verificación
- `php -d memory_limit=1G artisan test` verde al cierre de cada fase, más `./vendor/bin/pint --test`.
- Pruebas nuevas por fase:
  - backfill de la migración (los datos existentes quedan en la coordinación por defecto y el jefe actual conserva su visibilidad);
  - matriz de permisos por rol × coordinación;
  - IDOR (404) cruzado en tickets, actividades, adjuntos, correos, usuarios y equipos;
  - envío de ticket entre coordinaciones;
  - ingesta con dos buzones, uno de ellos fallando;
  - alcance y llave de cache del panel por coordinación;
  - bitácora acotada.
- Prueba manual en local con `DemoDataSeeder`: iniciar sesión como jefe A, jefe B, coordinador y empleado, y recorrer listado, Mis pendientes, panel, correos entrantes y aprobación de usuarios.
- Antes de producción: probar las migraciones en MySQL 8 de staging (FK, índices únicos compuestos, backfill) y probar la conexión real de al menos un buzón.
- Revisión con `code-reviewer` y `security-auditor` al cierre de las fases 2 y 3 (autorización y credenciales de buzón).
