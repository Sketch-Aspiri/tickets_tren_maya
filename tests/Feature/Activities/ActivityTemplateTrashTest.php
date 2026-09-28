<?php

declare(strict_types=1);

namespace Tests\Feature\Activities;

use App\Models\Activity;
use App\Models\Team;
use Spatie\Activitylog\Models\Activity as ActivityLogEntry;
use Tests\Concerns\BuildsActivityScenario;
use Tests\DatabaseTestCase;

/**
 * Plantillas de recurrencia por separado del listado principal (nunca aparecen ahi) con su propia
 * "papelera": editar reutiliza `activities.edit`, eliminar reutiliza `activities.destroy` sin cambios, y
 * restaurar es exclusivo de plantillas (no hay papelera general para tickets/actividades normales).
 */
class ActivityTemplateTrashTest extends DatabaseTestCase
{
    use BuildsActivityScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    private function makeTemplate(Team $team, array $attributes = []): Activity
    {
        return $this->makeActivity($team, [
            'recurrence_rule' => ['frequency' => 'weekly', 'interval' => 1, 'days_of_week' => [1], 'day_of_month' => null, 'ends_at' => null],
            'start_date' => '2030-01-01',
            ...$attributes,
        ]);
    }

    // --- Indice ------------------------------------------------------------------------------------------

    public function test_index_shows_only_templates_within_the_users_scope(): void
    {
        $templateA = $this->makeTemplate($this->teamA);
        $templateB = $this->makeTemplate($this->teamB);
        $single = $this->makeActivity($this->teamA);
        $instance = $this->makeActivity($this->teamA, ['parent_activity_id' => $templateA->id, 'occurrence_date' => '2030-01-07']);

        $jefeIds = $this->signIn($this->jefe)->get('/activities/templates')->assertOk()
            ->viewData('templates')->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$templateA->id, $templateB->id], $jefeIds);
        $this->assertNotContains($single->id, $jefeIds);
        $this->assertNotContains($instance->id, $jefeIds);

        $coordAIds = $this->signIn($this->coordA)->get('/activities/templates')->assertOk()
            ->viewData('templates')->pluck('id')->all();
        $this->assertSame([$templateA->id], $coordAIds, 'el coordinador solo ve las plantillas de su equipo');
    }

    public function test_employees_cannot_reach_the_templates_index(): void
    {
        $this->signIn($this->empA1)->get('/activities/templates')->assertForbidden();
    }

    // --- Papelera: eliminar (ruta existente) -> restaurar ------------------------------------------------

    public function test_a_template_can_be_edited_deleted_and_restored_with_its_recurrence_rule_intact(): void
    {
        $rule = ['frequency' => 'monthly', 'interval' => 2, 'days_of_week' => [], 'day_of_month' => 15, 'ends_at' => null];
        $template = $this->makeTemplate($this->teamA, ['recurrence_rule' => $rule, 'title' => 'Reporte mensual']);

        // Editar: reutiliza la ruta existente `activities.edit`/`activities.update`, sin cambios.
        $this->signIn($this->coordA)->get("/activities/{$template->id}/edit")->assertOk();

        // Eliminar: reutiliza `activities.destroy` (ActivityService::delete ya acepta plantillas Pendientes).
        $this->delete("/activities/{$template->id}")
            ->assertRedirect(route('activities.index'))
            ->assertSessionHas('status', 'activity-deleted');
        $this->assertSoftDeleted($template);

        // Ya no aparece entre las activas, pero si en la papelera.
        $activeIds = $this->get('/activities/templates')->assertOk()->viewData('templates')->pluck('id')->all();
        $this->assertNotContains($template->id, $activeIds);

        $trashedIds = $this->get('/activities/templates?trashed=1')->assertOk()->viewData('templates')->pluck('id')->all();
        $this->assertSame([$template->id], $trashedIds);

        // Restaurar.
        $this->post("/activities/templates/{$template->id}/restore")
            ->assertRedirect(route('activities.templates.index', ['trashed' => 1]))
            ->assertSessionHas('status', 'activity-restored');

        $restored = Activity::query()->findOrFail($template->id);
        $this->assertNotSoftDeleted($restored);
        $this->assertTrue($restored->isTemplate());
        $this->assertSame($rule, $restored->recurrence_rule);

        $this->assertNotNull(
            ActivityLogEntry::query()->where('subject_type', 'activity')->where('subject_id', $template->id)->where('event', 'restored')->first(),
            'spatie/laravel-activitylog registra el evento restored automaticamente (SoftDeletes + LogsActivity)',
        );
    }

    public function test_restoring_a_non_template_activity_is_rejected(): void
    {
        $activity = $this->makeActivity($this->teamA);
        $activity->delete();

        // Visible y en el alcance del coordinador, pero no es plantilla: la Policy la rechaza (403) antes
        // de llegar a ActivityService::restore().
        $this->signIn($this->coordA)
            ->post("/activities/templates/{$activity->id}/restore")
            ->assertForbidden();

        $this->assertSoftDeleted($activity);
    }

    public function test_an_employee_or_other_team_coordinator_cannot_restore_a_template_outside_their_scope(): void
    {
        $template = $this->makeTemplate($this->teamA);
        $template->delete();

        $this->signIn($this->empA1)->post("/activities/templates/{$template->id}/restore")->assertNotFound();
        $this->signIn($this->coordB)->post("/activities/templates/{$template->id}/restore")->assertNotFound();

        $this->assertSoftDeleted($template);
    }
}
