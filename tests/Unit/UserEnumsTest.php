<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use PHPUnit\Framework\TestCase;

class UserEnumsTest extends TestCase
{
    public function test_two_factor_is_mandatory_for_administrador_jefe_and_coordinador_only(): void
    {
        $this->assertTrue(UserRole::Administrador->requiresTwoFactor());
        $this->assertTrue(UserRole::JefeZona->requiresTwoFactor());
        $this->assertTrue(UserRole::Coordinador->requiresTwoFactor());
        $this->assertFalse(UserRole::Empleado->requiresTwoFactor());
    }

    public function test_team_is_required_only_for_coordinador_and_empleado(): void
    {
        $this->assertFalse(UserRole::Administrador->requiresTeam());
        $this->assertFalse(UserRole::JefeZona->requiresTeam());
        $this->assertTrue(UserRole::Coordinador->requiresTeam());
        $this->assertTrue(UserRole::Empleado->requiresTeam());
    }

    public function test_only_administrador_and_jefe_see_every_team(): void
    {
        $this->assertTrue(UserRole::Administrador->hasGlobalScope());
        $this->assertTrue(UserRole::JefeZona->hasGlobalScope());
        $this->assertFalse(UserRole::Coordinador->hasGlobalScope());
        $this->assertFalse(UserRole::Empleado->hasGlobalScope());
    }

    public function test_enum_values_match_the_documented_names(): void
    {
        $this->assertSame(['administrador', 'jefe_zona', 'coordinador', 'empleado'], UserRole::values());
        $this->assertSame(['pending', 'active', 'inactive'], UserStatus::values());
    }
}
