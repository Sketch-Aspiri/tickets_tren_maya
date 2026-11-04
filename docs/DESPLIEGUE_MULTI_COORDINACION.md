# Despliegue: multi-coordinación (Sprint 7)

Pasos para llevar a staging o producción la estructura Estación → Coordinación → Equipo con buzones por coordinación.
Detalle de diseño: `docs/PLAN_MULTI_COORDINACION.md` y decisiones 70–77 de `CLAUDE.md`.

> Confirma el servidor de destino antes de correr `migrate`. **Haz un respaldo de la base de datos antes** (las
> migraciones cambian índices únicos y vuelven obligatoria `teams.coordination_id`).

## 1. Antes de desplegar

1. Probar las migraciones en **MySQL 8 de staging** con una copia de datos reales (nunca se probaron contra MySQL; en
   SQLite pasan): cambio de `teams.coordination_id` a `NOT NULL` con FK, `DROP INDEX` de `teams_name_unique`,
   `categories_name_unique` e `incoming_emails_message_id_unique`, e índices únicos compuestos.
2. Tener a la mano los datos de cada buzón: servidor IMAP **público**, puerto, cifrado, usuario, contraseña y carpeta.

## 2. Secuencia

```bash
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force   # imprescindible: crea el permiso org.manage
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
```

Sin el seeder de permisos, las pantallas de Estaciones, Coordinaciones y Buzones responden 403 a todos (incluido el
administrador).

## 3. Qué hace la migración con los datos existentes

- Si ya hay equipos o usuarios, crea **«Estación principal» / «Coordinación principal»** (código `PRINCIPAL`).
- Le asigna **todos los equipos**, **todos los jefes de zona activos** (nadie pierde visibilidad el día del despliegue),
  las cuentas pendientes sin coordinación solicitada y los correos entrantes ya recibidos.
- La bitácora existente toma esa misma coordinación.
- En una base vacía no crea nada.

## 4. Después de desplegar (con la sesión del administrador)

1. **Renombrar** la estación y la coordinación por defecto (Estaciones / Coordinaciones).
2. **Crear** el resto de estaciones y coordinaciones y **mover los equipos** a la coordinación que corresponda
   (Equipos → Editar). Los tickets siguen a su equipo.
3. **Asignar coordinaciones a cada jefe de zona** (Usuarios → el jefe → «Coordinaciones que supervisa»). Solo el
   administrador puede hacerlo. Un jefe sin coordinaciones no ve nada.
4. **Dar de alta los buzones** (Buzones → Configurar) y usar **Probar conexión**. Para migrar el buzón actual del `.env`:

   ```bash
   php artisan mailboxes:seed-from-env PRINCIPAL
   ```

   Luego comprobar en «Buzones» que el estado es «Activo» y **quitar las variables `IMAP_*` del `.env`**.
5. Revisar que el cron de `schedule:run` y el worker de colas sigan activos (el scheduler corre `emails:ingest` cada 5
   minutos y recorre todos los buzones).

## 5. Verificación manual (local, con datos demo)

```bash
php artisan migrate:fresh --seed        # dos estaciones y dos jefes demo (jefe@demo.test, jefe.norte@demo.test)
php artisan db:seed --class=DemoScaleSeeder   # opcional: 5 coordinaciones, 110 usuarios, 4000 tickets
```

Iniciar sesión como jefe A, jefe B, coordinador y empleado y recorrer: listado de tickets, «Mis pendientes», panel de
seguimiento, correos entrantes y aprobación de usuarios. Un jefe no debe ver nada de la coordinación del otro.

## 6. Riesgos conocidos

- El nombre de las estaciones y coordinaciones es visible en la página pública de registro.
- La regla anti-SSRF del host del buzón valida al guardar; la conexión resuelve el DNS de nuevo después.
- Decisión abierta #8 (Microsoft 365 / Google con autenticación moderna): si el proveedor exige OAuth, IMAP clásico dejará
  de funcionar, ahora por buzón.
- Rollback: `php artisan migrate:rollback --step=5` deshace las migraciones del sprint (restaura los índices únicos
  globales; si ya existen equipos o categorías con el mismo nombre en coordinaciones distintas, el rollback fallará por
  los duplicados: renómbralos antes).
