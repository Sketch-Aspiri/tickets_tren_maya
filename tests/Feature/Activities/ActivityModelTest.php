<?php

declare(strict_types=1);

namespace Tests\Feature\Activities;

use App\Enums\AssignmentRole;
use App\Enums\PermissionName;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\Subtask;
use App\Models\User;
use App\Support\MorphMap;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity as ActivityLogEntry;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsActivityScenario;
use Tests\DatabaseTestCase;

/**
 * Modelo, alcance de lectura (`visibleTo`), vencimiento, avance y permisos de las actividades.
 */
class ActivityModelTest extends DatabaseTestCase
{
    use BuildsActivityScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return list<int>
     */
    private function visibleIds(User $user): array
    {
        return Activity::query()->visibleTo($user)->orderBy('id')->pluck('id')->all();
    }

    // --- Morph map ---------------------------------------------------------------------------------------------------------------

    public function test_the_activity_alias_resolves_to_the_activity_model(): void
    {
        $this->assertSame(Activity::class, MorphMap::aliases()['activity']);
        $this->assertSame('activity', (new Activity)->getMorphClass());
    }

    // --- Alcance -------------------------------------------------------------------------------------------------------------------

    public function test_jefe_sees_every_activity(): void
    {
        $a = $this->makeActivity($this->teamA);
        $b = $this->makeActivity($this->teamB);

        $this->assertSame([$a->id, $b->id], $this->visibleIds($this->jefe));
    }

    public function test_coordinator_sees_own_team_plus_explicitly_assigned_activities_only(): void
    {
        $own = $this->makeActivity($this->teamA);
        $other = $this->makeActivity($this->teamB);
        $assignedAcross = $this->makeActivity($this->teamB, [], $this->coordA);

        $this->assertSame([$own->id, $assignedAcross->id], $this->visibleIds($this->coordA));
        $this->assertNotContains($other->id, $this->visibleIds($this->coordA));
    }

    public function test_employee_sees_only_what_is_assigned_to_them_or_has_a_subtask_for_them(): void
    {
        $responsible = $this->makeActivity($this->teamA, [], $this->empA1);
        $collaborating = $this->makeActivity($this->teamA);
        $this->assignTo($collaborating, $this->empA1, AssignmentRole::Colaborador);
        $withSubtask = $this->makeActivity($this->teamA);
        $this->makeSubtask($withSubtask, assignee: $this->empA1);

        $this->makeActivity($this->teamA);                       // del equipo pero no suya
        $this->makeActivity($this->teamA, [], $this->empA2);     // de un compañero
        $this->makeActivity($this->teamB, [], $this->empB1);     // de otro equipo

        $this->assertSame([$responsible->id, $collaborating->id, $withSubtask->id], $this->visibleIds($this->empA1));
    }

    public function test_employees_never_see_recurring_templates_even_if_assigned(): void
    {
        $template = $this->makeActivity($this->teamA, ['recurrence_rule' => ['frequency' => 'daily', 'interval' => 1]], $this->empA1);
        $instance = $this->makeActivity($this->teamA, ['parent_activity_id' => $template->id, 'occurrence_date' => '2026-10-01'], $this->empA1);

        $this->assertSame([$instance->id], $this->visibleIds($this->empA1));
        $this->assertContains($template->id, $this->visibleIds($this->coordA));
        $this->assertContains($template->id, $this->visibleIds($this->jefe));
    }

    public function test_users_without_application_access_see_nothing(): void
    {
        $this->makeActivity($this->teamA, [], $this->empA1);

        $pending = User::factory()->pending()->create();
        $inactive = User::factory()->empleado()->inactive()->create(['team_id' => $this->teamA->id]);
        $noRole = User::factory()->active()->create();

        foreach ([$pending, $inactive, $noRole] as $user) {
            $this->assertSame([], $this->visibleIds($user));
        }
    }

    // --- Plantilla / instancia -----------------------------------------------------------------------------------------------

    public function test_template_and_instance_are_told_apart_by_recurrence_rule_and_parent(): void
    {
        $plain = Activity::factory()->forTeam($this->teamA)->create();
        $template = Activity::factory()->forTeam($this->teamA)->recurring()->create();
        $instance = Activity::factory()->forTeam($this->teamA)->instanceOf($template, '2026-10-05')->create();

        $this->assertFalse($plain->isTemplate());
        $this->assertFalse($plain->isInstance());
        $this->assertTrue($template->isTemplate());
        $this->assertTrue($instance->isInstance());
        $this->assertFalse($instance->isTemplate());
        $this->assertSame([$template->id], Activity::query()->templates()->pluck('id')->all());
        $this->assertSame([$plain->id, $instance->id], Activity::query()->withoutTemplates()->orderBy('id')->pluck('id')->all());
        $this->assertSame($template->id, $instance->parent->id);
        $this->assertSame([$instance->id], $template->instances->pluck('id')->all());
    }

    public function test_an_occurrence_of_a_series_cannot_be_duplicated_at_database_level(): void
    {
        $template = Activity::factory()->forTeam($this->teamA)->recurring()->create();
        Activity::factory()->forTeam($this->teamA)->instanceOf($template, '2026-10-05')->create();

        $this->expectException(UniqueConstraintViolationException::class);

        Activity::factory()->forTeam($this->teamA)->instanceOf($template, '2026-10-05')->create();
    }

    // --- Vencimiento ---------------------------------------------------------------------------------------------------------------

    public function test_overdue_is_a_calculation_on_open_activities_in_business_time(): void
    {
        // 2026-09-25 03:00 UTC = 2026-09-24 22:00 en Cancún: "hoy" sigue siendo el 24.
        Carbon::setTestNow('2026-09-25 03:00:00');

        $dueToday = Activity::factory()->forTeam($this->teamA)->dueOn('2026-09-24')->create();
        $dueYesterday = Activity::factory()->forTeam($this->teamA)->dueOn('2026-09-23')->create();
        $doneLate = Activity::factory()->forTeam($this->teamA)->dueOn('2026-09-01')->completed()->create();
        $noDate = Activity::factory()->forTeam($this->teamA)->create();

        $this->assertSame([$dueYesterday->id], Activity::query()->overdue()->pluck('id')->all());
        $this->assertTrue($dueYesterday->isOverdue());
        $this->assertFalse($dueToday->isOverdue());
        $this->assertFalse($doneLate->isOverdue());
        $this->assertFalse($noDate->isOverdue());
        $this->assertNull(TicketStatus::tryFrom('overdue'));
    }

    // --- Avance --------------------------------------------------------------------------------------------------------------------

    public function test_progress_is_zero_without_subtasks_partial_floored_and_full_only_when_all_are_done(): void
    {
        $activity = $this->makeActivity($this->teamA);
        $this->assertSame(0, $activity->progressPercent());

        $first = $this->makeSubtask($activity, 'uno', true);
        $this->makeSubtask($activity, 'dos');
        $this->makeSubtask($activity, 'tres');
        $this->assertSame(33, $activity->fresh()->progressPercent());

        $this->makeSubtask($activity, 'cuatro', true);
        $this->assertSame(50, $activity->fresh()->progressPercent());

        $activity->subtasks()->where('done', false)->update(['done' => true]);
        $this->assertSame(100, $activity->fresh()->progressPercent());

        $first->delete();
        $this->assertSame(100, $activity->fresh()->progressPercent());
    }

    public function test_progress_never_rounds_up_to_one_hundred_while_something_is_pending(): void
    {
        $activity = $this->makeActivity($this->teamA);
        Subtask::factory()->count(199)->for($activity)->create(['done' => true]);
        Subtask::factory()->for($activity)->create(['done' => false]);

        $this->assertSame(99, $activity->fresh()->progressPercent());
        $this->assertSame(0, Activity::percentage(0, 0));
        $this->assertSame(100, Activity::percentage(3, 3));
    }

    public function test_with_progress_scope_loads_counts_in_one_query_for_a_whole_list(): void
    {
        $a = $this->makeActivity($this->teamA);
        $b = $this->makeActivity($this->teamA);
        $this->makeSubtask($a, 'x', true);
        $this->makeSubtask($a, 'y');
        $this->makeSubtask($b, 'z', true);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = Activity::query()->withProgress()->orderBy('id')->get();
        $percents = $rows->map(fn (Activity $row): int => $row->progressPercent())->all();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame([50, 100], $percents);
        $this->assertSame(1, $queries, 'el avance no dispara consultas por fila');
    }

    // --- Mass assignment y bitácora ------------------------------------------------------------------------------------------

    public function test_mass_assignment_only_accepts_form_fields(): void
    {
        $activity = new Activity([
            'title' => 'Uno',
            'description' => 'x',
            'priority' => 'high',
            'start_date' => '2026-10-01',
            'due_date' => '2026-10-02',
            'category_id' => null,
            'folio' => 'ACT-HACK',
            'status' => 'completed',
            'team_id' => 99,
            'created_by' => 99,
            'recurrence_rule' => ['frequency' => 'daily'],
            'parent_activity_id' => 5,
            'occurrence_date' => '2026-10-01',
            'completed_at' => now(),
        ]);

        $this->assertEqualsCanonicalizing(
            ['title', 'description', 'priority', 'category_id', 'start_date', 'due_date'],
            array_keys($activity->getAttributes()),
        );
    }

    public function test_activity_changes_are_written_to_the_audit_log_under_the_activity_alias(): void
    {
        $activity = Activity::factory()->forTeam($this->teamA)->create();
        $activity->update(['title' => 'Nuevo título']);

        $entries = ActivityLogEntry::query()->where('subject_type', 'activity')->where('subject_id', $activity->id)->orderBy('id')->get();

        $this->assertSame(['created', 'updated'], $entries->pluck('event')->all());
        $this->assertSame('activities', $entries->first()->log_name);
    }

    // --- Permisos Spatie -----------------------------------------------------------------------------------------------------------

    public function test_activity_permission_matrix_by_role(): void
    {
        $granted = fn (UserRole $role): array => Role::findByName($role->value)->permissions->pluck('name')->all();
        $activity = fn (PermissionName ...$names): array => array_map(fn (PermissionName $name): string => $name->value, $names);

        $employee = $activity(PermissionName::ActivitiesView, PermissionName::ActivitiesWork);
        $manager = [...$employee, ...$activity(PermissionName::ActivitiesCreate, PermissionName::ActivitiesAssign, PermissionName::ActivitiesReview, PermissionName::ActivitiesManage)];

        $only = fn (array $permissions): array => array_values(array_filter($permissions, fn (string $name): bool => str_starts_with($name, 'activities.')));

        $this->assertEqualsCanonicalizing($employee, $only($granted(UserRole::Empleado)));
        $this->assertEqualsCanonicalizing($manager, $only($granted(UserRole::Coordinador)));
        $this->assertEqualsCanonicalizing($manager, $only($granted(UserRole::JefeZona)));
    }

    public function test_seeder_stays_idempotent_with_the_new_permissions(): void
    {
        $before = Role::findByName(UserRole::Coordinador->value)->permissions()->count();
        $permissions = Permission::query()->count();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame($before, Role::findByName(UserRole::Coordinador->value)->permissions()->count());
        $this->assertSame($permissions, Permission::query()->count());
        $this->assertTrue($this->coordA->fresh()->hasRole(UserRole::Coordinador->value), 'los usuarios conservan su rol');
    }
}
