<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Team;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use PDOException;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\DatabaseTestCase;

class UserIntegrityTest extends DatabaseTestCase
{
    private User $jefe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jefe = User::factory()->jefe()->create();
    }

    protected function tearDown(): void
    {
        Activity::flushEventListeners();

        parent::tearDown();
    }

    private function makeAuditFail(?\Throwable $exception = null): void
    {
        $exception ??= new RuntimeException('audit down');

        Activity::creating(function () use ($exception): void {
            throw $exception;
        });
    }

    // --- M6: el jefe no conserva team_id ------------------------------------------

    public function test_approving_a_jefe_never_persists_a_team(): void
    {
        $pending = User::factory()->pending()->create();
        $team = Team::factory()->create();

        $this->signIn($this->jefe)->post("/users/{$pending->id}/approve", ['role' => 'jefe_zona', 'team_id' => $team->id])
            ->assertSessionHasNoErrors();

        $fresh = $pending->fresh();
        $this->assertSame(UserRole::JefeZona, $fresh->roleEnum());
        $this->assertNull($fresh->team_id);
    }

    public function test_updating_a_user_to_jefe_clears_the_team_and_does_not_block_deleting_it(): void
    {
        $team = Team::factory()->create();
        $empleado = User::factory()->empleado()->create(['team_id' => $team->id]);

        $this->signIn($this->jefe)->put("/users/{$empleado->id}", ['role' => 'jefe_zona', 'team_id' => $team->id])
            ->assertSessionHasNoErrors();

        $this->assertNull($empleado->fresh()->team_id);
        $this->signIn($this->jefe)->delete("/teams/{$team->id}")->assertRedirect(route('teams.index'));
        $this->assertModelMissing($team);
    }

    public function test_service_also_clears_the_team_for_roles_that_do_not_need_one(): void
    {
        $team = Team::factory()->create();
        $pending = User::factory()->pending()->create();

        app(UserService::class)->approve($this->jefe, $pending, UserRole::JefeZona, $team->id);

        $this->assertNull($pending->fresh()->team_id);
    }

    // --- M5: atomicidad de estado + auditoria ---------------------------------------

    public function test_reject_is_rolled_back_when_the_audit_entry_cannot_be_written(): void
    {
        $pending = User::factory()->pending()->create();
        $this->makeAuditFail();

        try {
            app(UserService::class)->reject($this->jefe, $pending);
            $this->fail('Se esperaba que la auditoria fallida abortara la operacion.');
        } catch (RuntimeException) {
            $this->assertSame(UserStatus::Pending, $pending->fresh()->status);
        }
    }

    public function test_activate_is_rolled_back_when_the_audit_entry_cannot_be_written(): void
    {
        $inactive = User::factory()->empleado()->inactive()->create();
        $this->makeAuditFail();

        try {
            app(UserService::class)->activate($this->jefe, $inactive);
            $this->fail('Se esperaba que la auditoria fallida abortara la operacion.');
        } catch (RuntimeException) {
            $this->assertSame(UserStatus::Inactive, $inactive->fresh()->status);
        }
    }

    public function test_deactivate_and_approve_remain_atomic(): void
    {
        $empleado = User::factory()->empleado()->create();
        $pending = User::factory()->pending()->create();
        $team = Team::factory()->create();
        $this->makeAuditFail();

        foreach ([
            fn () => app(UserService::class)->deactivate($this->jefe, $empleado),
            fn () => app(UserService::class)->approve($this->jefe, $pending, UserRole::Empleado, $team->id),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Se esperaba que la auditoria fallida abortara la operacion.');
            } catch (RuntimeException) {
                // esperado
            }
        }

        $this->assertTrue($empleado->fresh()->isActive());
        $this->assertTrue($pending->fresh()->isPending());
        $this->assertCount(0, $pending->fresh()->roles);
    }

    // --- Ultimo jefe / interbloqueo --------------------------------------------------

    public function test_a_database_deadlock_becomes_a_retry_message_instead_of_a_server_error(): void
    {
        $other = User::factory()->jefe()->create();
        $this->makeAuditFail(new PDOException('SQLSTATE[40001]: Deadlock found when trying to get lock; try restarting transaction'));

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage(__('users.errors.concurrent_change'));

        app(UserService::class)->deactivate($this->jefe, $other);
    }

    public function test_non_concurrency_database_errors_are_not_swallowed(): void
    {
        $other = User::factory()->jefe()->create();
        $this->makeAuditFail(new PDOException('disk I/O error'));

        $this->expectException(PDOException::class);

        app(UserService::class)->deactivate($this->jefe, $other);
    }

    public function test_the_last_jefe_rule_counts_only_other_active_jefes(): void
    {
        $inactiveJefe = User::factory()->jefe()->inactive()->create();
        $second = User::factory()->jefe()->create();

        // Con dos activos se puede inactivar a uno; el que queda ya no.
        app(UserService::class)->deactivate($this->jefe, $second);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage(__('users.errors.last_jefe'));

        app(UserService::class)->deactivate($inactiveJefe, $this->jefe);
    }

    // --- Scope activeWithRole ----------------------------------------------------------

    public function test_active_with_role_scope_returns_only_active_users_with_that_role(): void
    {
        $coordinador = User::factory()->coordinador()->create();
        User::factory()->coordinador()->inactive()->create();
        User::factory()->empleado()->create();

        $ids = User::query()->activeWithRole(UserRole::Coordinador)->pluck('id')->all();

        $this->assertSame([$coordinador->id], $ids);
        $this->assertSame([$this->jefe->id], User::query()->activeWithRole(UserRole::JefeZona)->pluck('id')->all());
    }

    // --- Otros ---------------------------------------------------------------------------

    public function test_business_rule_exceptions_are_not_reported(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        $this->assertFalse($handler->shouldReport(BusinessRuleException::because('users.errors.last_jefe')));
        $this->assertTrue($handler->shouldReport(new RuntimeException('boom')));
    }

    public function test_password_fields_reject_values_over_255_characters(): void
    {
        $long = str_repeat('Aa1', 90);

        $this->post('/register', ['name' => 'Nombre Valido', 'email' => 'long@example.com', 'password' => $long, 'password_confirmation' => $long])
            ->assertInvalid(['password']);

        $this->post('/reset-password', ['token' => 'x', 'email' => 'long@example.com', 'password' => $long, 'password_confirmation' => $long])
            ->assertInvalid(['password']);

        $empleado = User::factory()->empleado()->create();
        $this->signIn($empleado)->put('/password', ['current_password' => 'Password-12345', 'password' => $long, 'password_confirmation' => $long])
            ->assertInvalid(['password'], 'updatePassword');
    }
}
