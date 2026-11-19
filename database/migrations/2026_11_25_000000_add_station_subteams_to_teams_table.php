<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            // Subequipo de estacion («RH Tulum» cuelga de «RRHH»): equipo central, area comun y codigo de la
            // estacion del Dashboard. Los tres son NULL en un equipo normal.
            $table->foreignId('parent_team_id')->nullable()->after('coordination_id')->constrained('teams')->restrictOnDelete();
            $table->string('area', 32)->nullable()->after('name');
            $table->string('station_code', 8)->nullable()->after('area');
            $table->unique(['parent_team_id', 'station_code']);
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->dropUnique(['parent_team_id', 'station_code']);
            $table->dropConstrainedForeignId('parent_team_id');
            $table->dropColumn(['area', 'station_code']);
        });
    }
};
