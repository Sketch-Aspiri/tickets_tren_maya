<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Models\Activity;
use App\Models\Category;
use App\Models\Subtask;
use App\Models\Team;
use App\Models\User;
use App\Services\ActivityService;
use App\Services\SubtaskService;
use App\Support\LocalTime;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Actividades de ejemplo (solo local y pruebas): una serie semanal recurrente con sus instancias y, en cada
 * equipo, actividades en distintos estados con subtareas y avance variado. Se crean con los MISMOS servicios
 * que usa la aplicación (folio ACT, historial, bitácora, asignación, recurrencia). Idempotente: si ya hay
 * actividades demo no crea más. Requiere los usuarios de DemoDataSeeder.
 */
class DemoActivitiesSeeder extends Seeder
{
    /** Prefijo que identifica las actividades demo. */
    private const DEMO_PREFIX = '[Demo] ';

    public function run(): void
    {
        if (! app()->environment(DemoDataSeeder::ALLOWED_ENVIRONMENTS)) {
            $this->command?->warn('DemoActivitiesSeeder solo corre en local.');

            return;
        }

        if (Activity::query()->where('title', 'like', self::DEMO_PREFIX.'%')->exists()) {
            return;
        }

        $categoryId = Category::query()->orderBy('id')->value('id');

        foreach (DemoDataSeeder::TEAM_NAMES as $index => $teamName) {
            $team = Team::query()->where('name', $teamName)->first();
            $coordinator = $team?->coordinator_id === null ? null : User::query()->find($team->coordinator_id);
            $employees = $team === null ? collect() : User::query()->memberOfAny([$team->id])->whereHas('roles', fn ($roles) => $roles->where('name', 'empleado'))->orderBy('email')->get();

            if ($coordinator === null || $employees->count() < 2) {
                continue;
            }

            $this->seedTeam($team, $coordinator, $employees->all(), $categoryId, $index);
        }
    }

    /**
     * @param  list<User>  $employees
     */
    private function seedTeam(Team $team, User $coordinator, array $employees, ?int $categoryId, int $index): void
    {
        [$first, $second] = $employees;
        $name = $team->name;

        $this->inProgressWithPartialProgress($coordinator, $first, $second, $categoryId, $name);
        $this->completed($coordinator, $second, $first, $name);
        $this->overdueWithoutSubtasks($coordinator, $first, $name);

        if ($index === 0) {
            $this->weeklySeries($coordinator, $first, $second, $categoryId, $name);
        }

        if ($index === 1) {
            $this->cancelled($coordinator, $second, $name);
        }
    }

    private function inProgressWithPartialProgress(User $coordinator, User $responsible, User $collaborator, ?int $categoryId, string $team): void
    {
        $activity = $this->create($coordinator, $responsible, "Preparar inspección de vías ({$team})", Priority::High, 5, $categoryId, [$collaborator]);
        $subtasks = app(SubtaskService::class);

        foreach (['Revisar el plan', 'Coordinar cuadrillas', 'Levantar evidencia'] as $title) {
            $subtasks->add($coordinator, $activity, $title, $collaborator->getKey());
        }

        app(ActivityService::class)->transition($responsible, $activity, TicketStatus::InProgress);
        $subtasks->setDone($responsible, $activity->subtasks()->orderBy('id')->firstOrFail(), true);
    }

    private function completed(User $coordinator, User $responsible, User $collaborator, string $team): void
    {
        $activity = $this->create($coordinator, $responsible, "Actualizar bitácora de mantenimiento ({$team})", Priority::Medium, 10, null, [$collaborator]);
        $subtasks = app(SubtaskService::class);
        $activities = app(ActivityService::class);

        $subtasks->add($coordinator, $activity, 'Capturar registros', null);
        $subtasks->add($coordinator, $activity, 'Validar con el coordinador', $responsible->getKey());

        $activities->transition($responsible, $activity, TicketStatus::InProgress);
        Subtask::query()->where('activity_id', $activity->getKey())->orderBy('id')->get()
            ->each(fn (Subtask $subtask) => $subtasks->setDone($responsible, $subtask, true));
        $activities->transition($responsible, $activity, TicketStatus::InReview, 'Listo para revisión');
        $activities->transition($coordinator, $activity, TicketStatus::Completed, 'Aprobado');
    }

    private function overdueWithoutSubtasks(User $coordinator, User $responsible, string $team): void
    {
        $activity = $this->create($coordinator, $responsible, "Entregar reporte de incidencias ({$team})", Priority::Urgent, 2, null, []);
        $activity->forceFill(['due_date' => now()->subDays(3)->toDateString()])->save();
    }

    private function cancelled(User $coordinator, User $responsible, string $team): void
    {
        $activity = $this->create($coordinator, $responsible, "Capacitación pospuesta ({$team})", Priority::Low, 15, null, []);

        app(ActivityService::class)->transition($coordinator, $activity, TicketStatus::Cancelled, 'Se pospone al siguiente periodo');
    }

    /**
     * Serie semanal (lunes y jueves) que arranca el lunes de esta semana: al crearla ya genera instancias.
     */
    private function weeklySeries(User $coordinator, User $responsible, User $collaborator, ?int $categoryId, string $team): void
    {
        $monday = LocalTime::now()->startOfWeek();
        $activity = app(ActivityService::class)->create($coordinator, [
            'title' => self::DEMO_PREFIX."Reporte semanal de avance ({$team})",
            'description' => 'Serie recurrente de ejemplo: cada instancia se trabaja de forma independiente.',
            'priority' => Priority::Medium->value,
            'category_id' => $categoryId,
            'start_date' => $monday->toDateString(),
            'due_date' => $monday->addDays(2)->toDateString(),
            'responsible_id' => $responsible->getKey(),
            'collaborator_ids' => [$collaborator->getKey()],
            'is_recurring' => true,
            'recurrence' => ['frequency' => 'weekly', 'interval' => 1, 'days_of_week' => [1, 4], 'ends_at' => null],
        ]);

        $subtasks = app(SubtaskService::class);

        // Subtareas de la plantilla: las heredaran las instancias que genere el comando programado.
        $subtasks->add($coordinator, $activity, 'Reunir datos del equipo', null);
        $subtasks->add($coordinator, $activity, 'Enviar el reporte', $responsible->getKey());

        // La primera instancia (ya generada) queda en marcha y con avance, para ver estados distintos en la misma serie.
        $instance = $activity->instances()->orderBy('occurrence_date')->first();

        if ($instance !== null) {
            $subtasks->add($coordinator, $instance, 'Reunir datos del equipo', null);
            $subtasks->add($coordinator, $instance, 'Enviar el reporte', $responsible->getKey());
            app(ActivityService::class)->transition($responsible, $instance, TicketStatus::InProgress);
            $subtasks->setDone($responsible, $instance->subtasks()->orderBy('id')->firstOrFail(), true);
        }
    }

    /**
     * @param  list<User>  $collaborators
     */
    private function create(User $coordinator, User $responsible, string $title, Priority $priority, int $dueInDays, ?int $categoryId, array $collaborators): Activity
    {
        return app(ActivityService::class)->create($coordinator, [
            'title' => self::DEMO_PREFIX.$title,
            'description' => Str::of('Actividad de ejemplo para probar listados, filtros, subtareas y flujos.')->toString(),
            'priority' => $priority->value,
            'category_id' => $categoryId,
            'due_date' => now()->addDays($dueInDays)->toDateString(),
            'responsible_id' => $responsible->getKey(),
            'collaborator_ids' => array_map(fn (User $user): int => $user->getKey(), $collaborators),
        ]);
    }
}
