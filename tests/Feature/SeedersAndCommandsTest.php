<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PermissionName;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use Database\Factories\UserFactory;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\DatabaseTestCase;

class SeedersAndCommandsTest extends DatabaseTestCase
{
    public function test_roles_and_permissions_seeder_creates_the_three_roles(): void
    {
        $this->assertEqualsCanonicalizing(UserRole::values(), Role::query()->pluck('name')->all());
        $this->assertSame(count(PermissionName::cases()), Permission::query()->count());
    }

    public function test_only_jefe_zona_holds_the_management_permissions(): void
    {
        $jefe = Role::findByName(UserRole::JefeZona->value);
        $this->assertTrue($jefe->hasPermissionTo(PermissionName::UsersManage->value));
        $this->assertTrue($jefe->hasPermissionTo(PermissionName::UsersApprove->value));
        $this->assertTrue($jefe->hasPermissionTo(PermissionName::TeamsManage->value));

        $this->assertTrue($jefe->hasPermissionTo(PermissionName::CategoriesManage->value));

        // Sprint 2: coordinador y empleado ya tienen permisos de tickets, pero ninguno de administracion.
        $administration = [
            PermissionName::UsersManage,
            PermissionName::UsersApprove,
            PermissionName::TeamsManage,
            PermissionName::CategoriesManage,
        ];

        foreach ([UserRole::Coordinador, UserRole::Empleado] as $role) {
            $granted = Role::findByName($role->value)->permissions->pluck('name')->all();

            foreach ($administration as $permission) {
                $this->assertNotContains($permission->value, $granted);
            }
        }
    }

    public function test_ticket_permission_matrix_by_role(): void
    {
        $granted = fn (UserRole $role): array => Role::findByName($role->value)->permissions->pluck('name')->all();

        // Sprint 3: las actividades suman permisos (el detalle por rol esta en ActivityModelTest).
        $operational = ['tickets.view', 'tickets.create', 'tickets.work', 'activities.view', 'activities.work'];
        $managerial = [...$operational, 'tickets.assign', 'tickets.review', 'tickets.manage', 'activities.create', 'activities.assign', 'activities.review', 'activities.manage'];

        $this->assertEqualsCanonicalizing($operational, $granted(UserRole::Empleado));
        $this->assertEqualsCanonicalizing($managerial, $granted(UserRole::Coordinador));
        $this->assertEqualsCanonicalizing(array_column(PermissionName::cases(), 'value'), $granted(UserRole::JefeZona));
    }

    public function test_seeder_is_idempotent_with_ticket_permissions_and_keeps_extra_grants_out(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(count(PermissionName::cases()), Permission::query()->count());
        $this->assertCount(5, Role::findByName(UserRole::Empleado->value)->permissions);
        $this->assertCount(12, Role::findByName(UserRole::Coordinador->value)->permissions);
    }

    public function test_roles_and_permissions_seeder_is_idempotent_and_keeps_user_assignments(): void
    {
        $user = User::factory()->jefe()->create();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(3, Role::query()->count());
        $this->assertSame(count(PermissionName::cases()), Permission::query()->count());
        $this->assertTrue($user->fresh()->hasRole(UserRole::JefeZona->value));
    }

    public function test_demo_seeder_is_idempotent(): void
    {
        $this->seed(DemoDataSeeder::class);
        $users = User::query()->count();
        $teams = Team::query()->count();
        $this->seed(DemoDataSeeder::class);

        $this->assertGreaterThan(0, $users);
        $this->assertSame($users, User::query()->count());
        $this->assertSame($teams, Team::query()->count());
        $this->assertNotNull(Team::query()->whereNotNull('coordinator_id')->first());
    }

    public function test_demo_seeder_creates_categories_and_tickets_in_several_states_and_is_idempotent(): void
    {
        $this->seed(DemoDataSeeder::class);

        $tickets = Ticket::query()->get();
        $categories = Category::query()->count();

        $this->assertGreaterThanOrEqual(4, $categories);
        $this->assertGreaterThanOrEqual(13, $tickets->count());
        $this->assertEqualsCanonicalizing(TicketStatus::values(), $tickets->pluck('status')->map->value->unique()->values()->all());
        $this->assertSame($tickets->count(), $tickets->pluck('folio')->unique()->count());
        $this->assertGreaterThan(1, $tickets->pluck('team_id')->unique()->count());
        $this->assertGreaterThan(0, Ticket::query()->overdue()->count());
        $this->assertGreaterThan(0, Ticket::query()->unassigned()->count());

        $this->seed(DemoDataSeeder::class);

        $this->assertSame($tickets->count(), Ticket::query()->count());
        $this->assertSame($categories, Category::query()->count());
    }

    public function test_demo_seeder_coordinators_respect_the_team_membership_invariant(): void
    {
        $this->seed(DemoDataSeeder::class);

        foreach (Team::query()->whereNotNull('coordinator_id')->get() as $team) {
            $this->assertSame($team->id, $team->coordinator->team_id);
        }
    }

    public function test_demo_seeder_only_runs_in_local_and_testing_even_when_called_directly(): void
    {
        foreach (['production', 'staging', 'development'] as $environment) {
            $this->app['env'] = $environment;

            (new DemoDataSeeder)->run();
            $this->artisan('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true])->assertSuccessful();

            $this->assertSame(0, User::query()->count(), $environment);
        }

        $this->app['env'] = 'local';
        (new DemoDataSeeder)->run();
        $this->assertGreaterThan(0, User::query()->count());
    }

    public function test_database_seeder_skips_demo_data_outside_local_and_testing(): void
    {
        foreach (['production', 'staging'] as $environment) {
            $this->app['env'] = $environment;

            $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

            $this->assertSame(0, User::query()->count(), $environment);
        }
    }

    public function test_staging_tells_the_operator_to_create_the_jefe_with_the_console_command(): void
    {
        $this->app['env'] = 'staging';

        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])
            ->expectsOutputToContain('users:create-jefe')
            ->assertSuccessful();
    }

    public function test_database_seeder_seeds_demo_data_in_testing_and_local(): void
    {
        $this->assertSame('testing', app()->environment());

        $this->seed(DatabaseSeeder::class);

        $this->assertGreaterThan(0, User::query()->count());
    }

    public function test_demo_seeder_rejects_a_weak_configured_password(): void
    {
        config(['tickets.demo_password' => 'weak']);

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->seed(DemoDataSeeder::class);
        } finally {
            $this->assertSame(0, User::query()->count());
        }
    }

    public function test_demo_seeder_accepts_a_strong_configured_password_and_applies_it(): void
    {
        config(['tickets.demo_password' => 'Demo-Strong-Passw0rd']);

        $this->seed(DemoDataSeeder::class);

        $this->assertTrue(Hash::check('Demo-Strong-Passw0rd', User::query()->where('email', 'jefe@demo.test')->firstOrFail()->password));
    }

    public function test_demo_seeder_prints_the_generated_password_only_when_it_was_applied(): void
    {
        $this->artisan('db:seed', ['--class' => DemoDataSeeder::class])
            ->expectsOutputToContain('Contrasena generada')
            ->assertSuccessful();

        $this->artisan('db:seed', ['--class' => DemoDataSeeder::class])
            ->doesntExpectOutputToContain('Contrasena generada')
            ->expectsOutputToContain('ya existian')
            ->assertSuccessful();
    }

    public function test_create_jefe_command_creates_an_active_jefe_who_must_set_up_two_factor(): void
    {
        $this->artisan('users:create-jefe', ['email' => 'Jefe@Example.com', '--name' => 'Jefe Inicial'])
            ->expectsQuestion('Contrasena (no se muestra)', 'Initial-Passw0rd')
            ->assertSuccessful();

        $jefe = User::query()->where('email', 'jefe@example.com')->firstOrFail();
        $this->assertTrue($jefe->isActive());
        $this->assertTrue($jefe->hasSystemRole(UserRole::JefeZona));
        $this->assertTrue(Hash::check('Initial-Passw0rd', $jefe->password));
        $this->assertNull($jefe->team_id);

        $this->actingAs($jefe)->get('/dashboard')->assertRedirect(route('two-factor.setup'));
        $this->assertDatabaseHas('activity_log', ['event' => 'created_by_console', 'subject_id' => $jefe->id]);
    }

    public function test_create_jefe_command_rejects_weak_passwords_and_duplicate_emails(): void
    {
        $this->artisan('users:create-jefe', ['email' => 'jefe@example.com', '--name' => 'Jefe'])
            ->expectsQuestion('Contrasena (no se muestra)', 'weak')
            ->assertFailed();

        User::factory()->create(['email' => 'taken@example.com']);
        $this->artisan('users:create-jefe', ['email' => 'taken@example.com', '--name' => 'Jefe'])
            ->expectsQuestion('Contrasena (no se muestra)', UserFactory::DEFAULT_PASSWORD)
            ->assertFailed();

        $this->assertSame(1, User::query()->count());
    }
}
