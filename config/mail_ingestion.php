<?php

/*
|--------------------------------------------------------------------------
| Ingesta de correo entrante (bandeja "Correos entrantes")
|--------------------------------------------------------------------------
| Archivo separado de `config/tickets.php`: agrupa las opciones propias del dominio de ingesta de
| correo (carpeta, límites, paginación). Las credenciales de conexión IMAP en sí viven en el
| `config/imap.php` publicado por `webklex/laravel-imap` (env `IMAP_*`).
|
| `folder`: carpeta IMAP que lee el comando `emails:ingest`.
| `interval_minutes`: cada cuántos minutos corre el scheduler.
| `max_email_kb`: tamaño máximo (cuerpo + adjuntos) de un correo para ingerirlo.
| `max_attachments_per_email`: tope de adjuntos por correo (más que eso, se rechaza entero).
| `max_per_sender_per_hour`: tope de correos ingeridos por remitente por hora.
| `max_per_run`: tope global de mensajes procesados por corrida del comando `emails:ingest`, sin importar
|   el remitente (defensa en profundidad contra un remitente que rota direcciones para inundar la bandeja
|   de revision). Los mensajes por encima de este numero se quedan sin leer para la siguiente corrida.
| `per_page`: filas por página del listado de la bandeja.
| `lookback_days`: ventana de lectura. Se leen los correos recibidos en los últimos N días, leídos o no
|   (un correo abierto a mano en el buzón ya no se pierde); el dedup por Message-ID evita repetirlos. Si la
|   ingesta estuvo caída más de N días, lo anterior a la ventana no se recupera solo.
| `ignore_before`: fecha (AAAA-MM-DD) antes de la cual nunca se lee, aunque caiga en la ventana. Sirve para
|   ignorar el correo histórico que había en el buzón al cambiar a lectura por fecha.
*/

return [
    'folder' => env('MAIL_INGESTION_FOLDER', 'INBOX'),
    'interval_minutes' => (int) env('MAIL_INGESTION_INTERVAL_MINUTES', 5),
    'max_email_kb' => (int) env('MAIL_INGESTION_MAX_EMAIL_KB', 15360),
    'max_attachments_per_email' => (int) env('MAIL_INGESTION_MAX_ATTACHMENTS', 10),
    'max_per_sender_per_hour' => (int) env('MAIL_INGESTION_MAX_PER_SENDER_PER_HOUR', 20),
    'max_per_run' => (int) env('MAIL_INGESTION_MAX_PER_RUN', 200),
    'per_page' => 15,
    'lookback_days' => (int) env('MAIL_INGESTION_LOOKBACK_DAYS', 7),
    'ignore_before' => env('MAIL_INGESTION_IGNORE_BEFORE'),
];
