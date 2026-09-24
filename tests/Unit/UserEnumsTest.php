<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use PHPUnit\Framework\TestCase;

class UserEnumsTest extends TestCase
{
    public function test_two_factor_is_mandatory_for_jefe_and_coordinador_only(): void
    {
        $this->assertTrue(UserRole::JefeZona->requiresTwoFactor());
        $this->assertTrue(UserRole::Coordinador->requiresTwoFactor());
        $this->assertFalse(UserRole::Empleado->requiresTwoFactor());
    }

    public function test_team_is_required_for_everyone_except_jefe_de_zona(): void
    {
        $this->assertFalse(UserRole::JefeZona->requiresTeam());
        $this->assertTrue(UserRole::Coordinador->requiresTeam());
        $this->assertTrue(UserRole::Empleado->requiresTeam());
    }

    public function test_enum_values_match_the_documented_names(): void
    {
        $this->assertSame(['jefe_zona', 'coordinador', 'empleado'], UserRole::values());
        $this->assertSame(['pending', 'active', 'inactive'], UserStatus::values());
    }
}
