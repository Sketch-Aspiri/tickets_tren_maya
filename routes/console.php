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
