<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coordinadores ADICIONALES de un equipo (decision 96). `teams.coordinator_id` sigue siendo el coordinador
 * PRINCIPAL (responsable de las actividades automaticas y de lo que nadie asigna); esta tabla guarda los demas, que
 * comparten la gestion del equipo y reciben sus avisos. Un usuario no puede ser principal y adicional del mismo equipo
 * (lo garantiza TeamService). Empieza vacia: nada cambia hasta que alguien agregue un coordinador adicional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_coordinator', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['team_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_coordinator');
    }
};
