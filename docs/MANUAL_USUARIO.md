# Manual de usuario — Tren Maya Tickets

Sistema para saber qué tienes pendiente, atender solicitudes (**tickets**) y planificar trabajo (**actividades**). Funciona en computadora y en celular.

## Contenido

1. [Conceptos básicos](#1-conceptos-básicos)
2. [Roles y qué puede hacer cada uno](#2-roles-y-qué-puede-hacer-cada-uno)
3. [Acceso: registro, ingreso y 2FA](#3-acceso-registro-ingreso-y-2fa)
4. [Pantalla de inicio y menú](#4-pantalla-de-inicio-y-menú)
5. [Mis pendientes](#5-mis-pendientes)
6. [Tickets](#6-tickets)
7. [Actividades](#7-actividades)
8. [Estados y flujo de revisión](#8-estados-y-flujo-de-revisión)
9. [Comentarios y archivos adjuntos](#9-comentarios-y-archivos-adjuntos)
10. [Correos entrantes](#10-correos-entrantes)
11. [Panel de seguimiento](#11-panel-de-seguimiento)
12. [Administración (jefe de zona)](#12-administración-jefe-de-zona)
13. [Mi perfil y seguridad](#13-mi-perfil-y-seguridad)
14. [Preguntas frecuentes](#14-preguntas-frecuentes)

---

## 1. Conceptos básicos

| Concepto | Qué es | Ejemplo |
|---|---|---|
| **Ticket** | Solicitud que hay que atender. Cualquier usuario puede crearla. | «Falla en la impresora de la oficina 2» |
| **Actividad** | Tarea planificada, con responsable, subtareas y, si se desea, repetición. La crean jefe de zona y coordinadores. | «Reporte semanal de avance» (cada lunes) |

Datos que verás en todo el sistema:

- **Folio**: identificador único. Tickets `TM-AAAA-0001`; actividades `ACT-AAAA-0001`.
- **Prioridad**: Baja, Media, Alta, Urgente.
- **Estado**: Pendiente, En proceso, En revisión, Completado, Cancelado.
- **Vencido**: no es un estado; se marca cuando la fecha límite ya pasó y el trabajo no está terminado ni cancelado.
- **Responsable y colaboradores**: cada ticket o actividad tiene **un** responsable y puede tener varios colaboradores.
- **Equipo**: grupo de trabajo con un coordinador. Cada empleado pertenece a uno o más equipos.
- **Bolsa del equipo**: tickets sin asignar que cualquier integrante del equipo puede tomar.

Las horas se muestran en horario de Quintana Roo (Cancún).

---

## 2. Roles y qué puede hacer cada uno

| Acción | Jefe de zona / Administrador | Coordinador | Empleado |
|---|---|---|---|
| Crear ticket | Sí | Sí | Sí |
| Ver tickets | Todos | Los de su equipo | Los suyos (creados o asignados) y la bolsa de su equipo |
| Tomar un ticket de la bolsa | — | — | Sí (de su equipo) |
| Asignar / reasignar tickets | Todos | De su equipo | No |
| Crear actividades y subtareas | Sí | Sí (para su equipo) | No |
| Ver actividades | Todas | De su equipo | Las asignadas a él |
| Avanzar un trabajo hasta **En revisión** | Sí | Sí | Sí (solo lo suyo) |
| **Aprobar o rechazar** (En revisión) | Sí | Sí (su equipo) | No |
| Cancelar y reabrir | Sí | Sí (su equipo) | No |
| Correos entrantes | Sí | Sí | No |
| Panel de seguimiento | Global | Su equipo | No |
| Exportar a Excel | Sí | Sí | No |
| Usuarios, equipos y categorías | Sí | No | No |
| Bitácora de auditoría | Sí | No | No |

> El **empleado nunca marca «Completado»**: solo puede pasar el trabajo a «En revisión». Un jefe o coordinador lo aprueba.
> El **Administrador** tiene los mismos permisos que el jefe de zona y además gestiona cuentas de administrador.

Si intentas abrir algo fuera de tu alcance, el sistema responde como si no existiera («no encontrado»).

---

## 3. Acceso: registro, ingreso y 2FA

### 3.1 Registrarte

1. En la pantalla de acceso elige **Registrarse**.
2. Captura nombre, correo y contraseña (mínimo 10 caracteres, con mayúsculas, minúsculas y números).
3. Tu cuenta queda **pendiente**: no tienes acceso hasta que el jefe de zona la apruebe y te asigne rol y equipo. Recibirás un aviso cuando se resuelva.

Mientras esté pendiente, al iniciar sesión solo verás una pantalla informativa y la opción de cerrar sesión. Si tu cuenta fue rechazada o inactivada verás un mensaje equivalente; contacta al jefe de zona.

### 3.2 Iniciar sesión

Con tu correo y contraseña. Si olvidaste la contraseña usa **¿Olvidaste tu contraseña?**: recibirás un enlace por correo (el sistema responde igual exista o no la cuenta).

Hay límites de intentos; si fallas varias veces tendrás que esperar unos minutos.

### 3.3 Verificación en dos pasos (2FA)

- **Obligatoria** para jefe de zona, administrador y coordinadores. Opcional para empleados.
- Necesitas una app de autenticación (Google Authenticator, Microsoft Authenticator, Authy, etc.).

Primera vez:

1. Escanea el código QR con la app.
2. Escribe el código de 6 dígitos que muestra la app.
3. **Guarda los códigos de recuperación** que aparecen: se muestran una sola vez y cada uno sirve una sola vez. Guárdalos en un lugar seguro.

En cada inicio de sesión se te pedirá el código de la app (o un código de recuperación si perdiste el teléfono).

---

## 4. Pantalla de inicio y menú

Al entrar ves **Inicio** con tu nombre y rol. Jefe y coordinador ven además accesos rápidos (registros pendientes de aprobación, panel de seguimiento).

En celular, abre el menú con el botón de la esquina superior. Las secciones del menú (según tu rol):

| Sección | Opciones |
|---|---|
| — | Inicio |
| **Trabajo** | Mis pendientes, Tickets, Actividades (jefe/coordinador) |
| **Correo** | Correos entrantes (jefe/coordinador) |
| **Seguimiento** | Panel de seguimiento (jefe/coordinador), Bitácora (jefe) |
| **Administración** | Usuarios, Equipos, Categorías (jefe) |
| — | Mi perfil |

También hay una campana con las notificaciones internas.

---

## 5. Mis pendientes

Es tu lista de trabajo: lo que tienes asignado (como responsable o colaborador), ordenado por fecha límite y prioridad. Tiene dos secciones, **Tickets** y **Actividades**, cada una con su propia paginación.

- **Vencen hoy**: botón para ver solo lo que vence el día de hoy.
- **Coordinadores**: pueden alternar entre **Mis pendientes** (lo asignado a ellos) y **Mi equipo** (todo lo abierto del equipo, asignado o en bolsa).
- Lo que te asignaron aparece aquí aunque sea de otro equipo.
- En actividades también aparecen aquellas donde tienes una subtarea pendiente.

---

## 6. Tickets

### 6.1 Crear un ticket

1. Menú **Tickets → Nuevo ticket**.
2. Llena: **título**, **descripción**, **prioridad**, **fecha límite** (opcional, no puede ser anterior a hoy), **categoría** (opcional) y **equipo**.
3. **Equipo destino**: puedes mandar el ticket a **cualquier equipo** (por ejemplo, a TI). Por defecto se propone el tuyo.
4. Guarda. El ticket nace **sin asignar**, en la bolsa del equipo destino, y tú lo sigues viendo por ser el creador.

### 6.2 Listado y filtros

En **Tickets** ves los de tu(s) equipo(s). Filtra por estado, prioridad, categoría, responsable, equipo, vencidos, sin asignar y búsqueda por título o folio. Puedes ordenar por columnas. El listado se pagina de 15 en 15.

> Tickets que te asignaron de otro equipo no salen en este listado, pero sí en **Mis pendientes**.

### 6.3 Atender un ticket

Abre el ticket para ver detalle, historial, comentarios y archivos.

**Si está en la bolsa** (sin asignar) y eres empleado de ese equipo:

1. Pulsa **Tomar ticket**. Serás el responsable.

**Trabajarlo:**

1. Cambia el estado a **En proceso** cuando empieces.
2. Cuando termines, cámbialo a **En revisión**. Si lo deseas, agrega un comentario.
3. Un coordinador o jefe lo **aprueba** (queda **Completado**) o lo **rechaza** (vuelve a **En proceso**, con un comentario que explica qué falta).

### 6.4 Asignar, reasignar y delegar (jefe / coordinador)

En el detalle, sección **Asignación**: elige **un responsable** y hasta 10 colaboradores. Guardar reemplaza el conjunto anterior.

- El jefe puede asignar a cualquier usuario activo; el coordinador, solo a activos de su equipo.
- **Devolver a la bolsa** deja el ticket sin asignar.
- No se asignan tickets Completados o Cancelados: primero hay que reabrirlos.

### 6.5 Editar, cancelar, reabrir y eliminar

- **Editar**: el creador, solo mientras esté Pendiente; jefe/coordinador, mientras no esté terminado. El equipo de un ticket ya creado no se cambia.
- **Cancelar / Reabrir** (jefe/coordinador): la reapertura exige comentario y regresa el ticket a Pendiente.
- **Eliminar**: solo tickets Pendientes o Cancelados. Si está en otro estado, se cancela.

### 6.6 Exportar a Excel (jefe / coordinador)

En el listado, **Exportar a Excel** genera un `.xlsx` con los filtros aplicados (hasta 5,000 filas; si hay más, se avisa). Hay un límite de 10 exportaciones por hora.

---

## 7. Actividades

Solo jefe de zona y coordinadores las crean y las ven en el listado; los empleados llegan a las suyas desde **Mis pendientes**.

### 7.1 Crear una actividad

1. **Actividades → Nueva actividad**.
2. Llena título, descripción, prioridad, categoría (opcional), fecha de inicio y fecha límite. El jefe elige el equipo; el coordinador usa el suyo.
3. Elige **responsable** y colaboradores.
4. Para que se repita, activa **Recurrente** (ver 7.3).

### 7.2 Subtareas y avance

- Agrega subtareas (máximo 50) con título y, opcionalmente, un responsable.
- El **porcentaje de avance** se calcula con las subtareas hechas.
- Pueden marcar una subtarea como hecha: quien gestiona la actividad, el **responsable** de la actividad y la persona asignada a esa subtarea.
- **No se puede pasar a En revisión ni a Completado con subtareas pendientes.**

### 7.3 Actividades recurrentes

Configura la frecuencia:

| Frecuencia | Opciones |
|---|---|
| Diaria | Cada N días |
| Semanal | Cada N semanas, en los días de la semana que elijas |
| Mensual | Cada N meses, en un día del mes (en meses cortos se usa el último día) |

Puedes fijar una **fecha fin**. Requiere fecha de inicio.

Cómo funciona:

- La actividad recurrente es la **plantilla**. Cada día a las 2:00 a. m. (hora de Cancún) el sistema genera las **instancias** de los próximos 14 días.
- Cada instancia tiene su propio folio, estado, subtareas (reiniciadas), comentarios y asignados copiados de la plantilla, y se gestiona de forma independiente.
- Las plantillas **no aparecen** en el listado normal ni en Mis pendientes. Se ven en **Actividades → Plantillas**, donde puedes editarlas, cancelarlas o restaurar una eliminada.
- **Editar la plantilla** solo afecta a las instancias futuras; las ya generadas se ajustan o cancelan a mano.

### 7.4 Asignación, estados y exportación

Las actividades usan los mismos estados y el mismo flujo de revisión que los tickets (sección 8). No tienen bolsa ni «tomar»: siempre se asignan explícitamente. También se pueden exportar a Excel.

---

## 8. Estados y flujo de revisión

```
Pendiente → En proceso → En revisión → Completado
                 ↑             │
                 └─ rechazo ───┘   (comentario obligatorio)

Cancelado: desde cualquier estado no final (jefe/coordinador)
Reabrir:   Completado / Cancelado → Pendiente (jefe/coordinador, con comentario)
```

Cada cambio guarda quién lo hizo, cuándo y el comentario, y queda visible en el **historial** del ticket o actividad.

| Quién | Puede |
|---|---|
| Empleado | Pendiente → En proceso → En revisión (solo lo suyo) |
| Coordinador / Jefe | Todo lo anterior, aprobar, rechazar, cancelar y reabrir |

---

## 9. Comentarios y archivos adjuntos

**Comentarios**: cualquiera que pueda ver el ticket o actividad puede comentar (texto plano, hasta 2,000 caracteres). No se editan ni se borran.

**Adjuntos**:

- Tipos permitidos: PDF, PNG, JPG/JPEG, TXT, DOCX y XLSX.
- Máximo 10 MB por archivo y 10 archivos por ticket o actividad.
- El sistema revisa el contenido real del archivo, no solo su extensión.
- Los archivos se descargan solo desde el sistema y con tu sesión iniciada.
- Puede eliminarlos quien los subió o un jefe/coordinador del alcance.

---

## 10. Correos entrantes

*(Jefe de zona, administrador y coordinador.)*

El buzón institucional recibe solicitudes por correo. Cada correo entra a la bandeja **Correo → Correos entrantes** para que una persona decida; **nada se crea de forma automática**.

Estados: **Pendiente de revisión**, **Convertido**, **Descartado**.

Para cada correo pendiente:

- **Convertir en actividad**: se abre el formulario de actividad con el asunto y cuerpo del correo; completa responsable, prioridad y fechas, y guarda. Los adjuntos del correo pasan a la actividad.
- **Descartar**: exige un **motivo**. El correo queda en el histórico y se puede consultar con el filtro *Descartado*.

Notas:

- Los correos nunca se borran.
- Se ignoran respuestas automáticas y duplicados.
- El contenido del correo es solo texto: nunca se ejecuta.
- El jefe y el administrador reciben una notificación cuando llegan correos nuevos.

---

## 11. Panel de seguimiento

*(Jefe: global. Coordinador: solo su equipo.)*

**Seguimiento → Panel de seguimiento**.

- **Tarjetas**: Abiertos, En revisión, Vencidos (estas tres son la foto de hoy) y Completados en el periodo.
- **Gráficas**: por estado, por prioridad y tendencia de creados contra completados. Cada gráfica trae una tabla equivalente en «Ver datos».
- **Carga por persona**: abiertos, en revisión y vencidos por empleado.
- **Tiempo promedio de cierre** por categoría y por equipo.
- **Filtros**: periodo (por defecto últimos 30 días, máximo 366), equipo (solo jefe), persona y categoría.

Las cifras pueden tardar hasta 1 minuto en actualizarse (la pantalla indica la hora de cálculo). Cuenta tickets y actividades; las plantillas de recurrencia no cuentan.

---

## 12. Administración (jefe de zona)

### Usuarios

**Usuarios** lista las cuentas con su estado.

- **Aprobar**: asigna **rol** y **equipo** (obligatorio para coordinador y empleado). El usuario ya puede entrar.
- **Rechazar**: pasa la cuenta a inactiva con un motivo; se puede aprobar después.
- **Activar / Inactivar**: inactivar cierra sus sesiones. Sus asignaciones se conservan y deben reasignarse.
- **Cambiar rol o equipo**.

Reglas: el sistema siempre conserva al menos un jefe activo, y el jefe no puede inactivarse ni quitarse su propio rol.

### Equipos

Crea, edita y elimina equipos. Un equipo nuevo nace sin coordinador; se asigna al editarlo, eligiendo entre integrantes con rol coordinador. Un coordinador solo coordina un equipo. No se puede eliminar un equipo con integrantes o con tickets.

### Categorías

Crea y edita categorías para clasificar trabajo. Una categoría con registros no se elimina: se **desactiva** (los registros conservan su categoría).

### Bitácora de auditoría

**Seguimiento → Bitácora**: registro de solo lectura de quién hizo qué y cuándo (accesos, aprobaciones, cambios de estado, asignaciones, exportaciones, etc.). Filtra por usuario, evento, tipo de registro, fechas y texto. Nunca muestra contraseñas ni secretos.

---

## 13. Mi perfil y seguridad

En **Mi perfil** puedes:

- Cambiar tu nombre y tu contraseña (esto cierra tus otras sesiones).
- Activar o desactivar la **verificación en dos pasos** (no se puede desactivar si tu rol la exige).
- **Regenerar los códigos de recuperación** (los anteriores dejan de servir).

El correo no se puede editar: es tu identidad en el sistema.

Buenas prácticas: no compartas tu cuenta, cierra sesión en equipos ajenos y avisa al jefe de zona si sospechas que alguien accedió a tu cuenta.

---

## 14. Preguntas frecuentes

**Me registré y no puedo entrar.** Tu cuenta espera aprobación del jefe de zona. Contáctalo.

**Perdí mi teléfono con la app de 2FA.** Usa un código de recuperación. Si no los tienes, pide al administrador del sistema que restablezca tu 2FA.

**No veo un ticket que sé que existe.** No está en tu alcance (otro equipo) o ya no eres creador ni asignado. Si te lo asignaron, búscalo en **Mis pendientes**.

**No puedo marcar «Completado».** Es intencional: pasa el trabajo a **En revisión** y un coordinador o jefe lo aprueba.

**Mi ticket fue rechazado.** Regresó a **En proceso**; lee el comentario del rechazo, corrige y vuelve a enviarlo a revisión.

**No puedo pasar una actividad a «En revisión».** Tiene subtareas pendientes; márcalas como hechas primero.

**No me deja subir un archivo.** Revisa que sea PDF, PNG, JPG, TXT, DOCX o XLSX, de 10 MB o menos, y que no se haya alcanzado el máximo de 10 archivos.

**Mandé un ticket a otro equipo y ya no lo veo en la lista.** Lo verás en el listado si eres el creador; si te lo asignaron, en **Mis pendientes**. Ábrelo también con el enlace directo.

**Veo «Demasiados intentos».** Espera unos minutos; hay límites de uso para proteger el sistema.

---

*Este manual describe la versión actual del sistema. Si notas diferencias con lo que ves en pantalla, avisa al jefe de zona.*
