<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Una sola entrada de cron en el servidor (`schedule:run` cada minuto); las tareas se declaran aqui.
// Instancias de actividades recurrentes: una vez al dia, de madrugada en hora de negocio (America/Cancun).
Schedule::command('activities:generate-recurring')
    ->dailyAt((string) config('tickets.recurrence.schedule_at'))
    ->timezone((string) config('app.display_timezone'))
    ->withoutOverlapping();

// Ingesta de correo entrante (bandeja "Correos entrantes"): cada `mail_ingestion.interval_minutes` minutos.
Schedule::command('emails:ingest')
    ->cron('*/'.(int) config('mail_ingestion.interval_minutes').' * * * *')
    ->withoutOverlapping();

// Excel en la nube (OneDrive/SharePoint): cada hora, solo si esta activado (CLOUD_SYNC_ENABLED=true; se evalua
// en cada corrida, asi un cambio de configuracion no requiere tocar el cron).
Schedule::command('exports:sync-cloud')
    ->hourly()
    ->when(fn (): bool => (bool) config('tickets.cloud_sync.enabled'))
    ->withoutOverlapping();
