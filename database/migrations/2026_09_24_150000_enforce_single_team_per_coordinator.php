<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invariante: un coordinador coordina como maximo un equipo (y solo el suyo).
     * Primero se normalizan los datos existentes (se libera todo equipo cuyo coordinador no
     * pertenezca a el) y despues el indice unico impide reintroducir el problema.
     * La FK conserva su `nullOnDelete` original.
     */
    public function up(): void
    {
        DB::table('teams')
            ->whereNotNull('coordinator_id')
            ->whereNotExists(function ($query): void {
                $query->select('users.id')
                    ->from('users')
                    ->whereColumn('users.id', 'teams.coordinator_id')
                    ->whereColumn('users.team_id', 'teams.id');
            })
            ->update(['coordinator_id' => null]);

        Schema::table('teams', function (Blueprint $table) {
            $table->unique('coordinator_id', 'teams_coordinator_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropUnique('teams_coordinator_id_unique');
        });
    }
};
