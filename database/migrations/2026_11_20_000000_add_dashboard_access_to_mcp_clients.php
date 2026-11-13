<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acceso de cada cliente MCP a las herramientas del Dashboard (decision 97): `none` (por defecto: no las ve), `read`
 * (consultas) o `write` (consultas y captura de asistencia; exige ademas `can_write`). Los clientes que ya existen
 * quedan en `none` hasta que alguien les de acceso expresamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_clients', function (Blueprint $table): void {
            $table->string('dashboard_access', 10)->default('none')->after('can_write');
        });
    }

    public function down(): void
    {
        Schema::table('mcp_clients', function (Blueprint $table): void {
            $table->dropColumn('dashboard_access');
        });
    }
};
