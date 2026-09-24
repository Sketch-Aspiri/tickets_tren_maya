<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity as ActivityLogEntry;
use Tests\DatabaseTestCase;

/**
 * Matriz de la habilidad `view-dashboard` (DashboardPolicy) y de `viewAny` sobre la bitacora (AuditLogPolicy),
 * por rol y por estado de cuenta.
 */
class DashboardPolicyTest extends DatabaseTestCase
{
    /**
     * @return array<string, User>
     */
    private function actors(): array
    {
        return [
            'jefe' => User::factory()->jefe()->create(),
            'coordinador' => User::factory()->coordinador()->create(),
            'empleado' => User::factory()->empleado()->create(),
            'jefe inactivo' => User::factory()->jefe()->inactive()->create(),
            'coordinador pendiente' => User::factory()->coordinador()->pending()->create(),
            'activo sin rol' => User::factory()->active()->create(),
            'pendiente sin rol' => User::factory()->pending()->create(),
        ];
    }

    public function test_dashboard_policy_matrix(): void
    {
        $expected = [
            'jefe' => true,
            'coordinador' => true,
            'empleado' => false,
            'jefe inactivo' => false,
            'coordinador pendiente' => false,
            'activo sin rol' => false,
            'pendiente sin rol' => false,
        ];

        foreach ($this->actors() as $label => $user) {
            $this->assertSame($expected[$label], Gate::forUser($user)->allows('view-dashboard'), $label);
        }
    }

    public function test_audit_log_policy_matrix(): void
    {
        $expected = [
            'jefe' => true,
            'coordinador' => false,
            'empleado' => false,
            'jefe inactivo' => false,
            'coordinador pendiente' => false,
            'activo sin rol' => false,
            'pendiente sin rol' => false,
        ];

        foreach ($this->actors() as $label => $user) {
            $this->assertSame($expected[$label], Gate::forUser($user)->allows('viewAny', ActivityLogEntry::class), $label);
        }
    }

    public function test_there_is_no_write_ability_on_the_audit_log(): void
    {
        $jefe = User::factory()->jefe()->create();

        foreach (['create', 'update', 'delete', 'restore', 'forceDelete'] as $ability) {
            $this->assertFalse(Gate::forUser($jefe)->allows($ability, ActivityLogEntry::class), $ability);
        }
    }
}
