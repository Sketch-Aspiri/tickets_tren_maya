<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RecurrenceService;
use Illuminate\Console\Command;

/**
 * Genera las instancias de las actividades recurrentes hasta el horizonte configurado. Es idempotente
 * (puede ejecutarse varias veces al día sin duplicar) y se programa una vez al día en routes/console.php.
 */
class GenerateRecurringActivitiesCommand extends Command
{
    protected $signature = 'activities:generate-recurring';

    protected $description = 'Genera las instancias de las actividades recurrentes hasta el horizonte configurado (idempotente)';

    public function handle(RecurrenceService $recurrence): int
    {
        $result = $recurrence->generateAll();

        $this->info(__('activities.command.done', ['created' => $result['created'], 'templates' => $result['templates']]));

        if ($result['failed'] > 0) {
            $this->error(__('activities.command.failed', ['failed' => $result['failed']]));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
