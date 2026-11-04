<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_STATION = 'Estación principal';

    private const DEFAULT_COORDINATION = 'Coordinación principal';

    private const DEFAULT_CODE = 'PRINCIPAL';

    /**
     * `teams.coordination_id` (obligatoria), `categories.coordination_id` (nula = categoria global) y
     * `users.requested_coordination_id` (la elegida al registrarse).
     *
     * Si ya hay datos (equipos o usuarios) se crea una estacion y una coordinacion por defecto, se les asignan
     * todos los equipos y se liga a todos los jefes de zona activos, para que nadie pierda visibilidad el dia
     * del despliegue. En una base vacia no se crea nada.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('requested_coordination_id')->nullable()
                ->constrained('coordinations')->nullOnDelete();
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->unsignedBigInteger('coordination_id')->nullable()->after('id');
        });

        $defaultId = $this->createDefaultCoordination();

        if ($defaultId !== null) {
            DB::table('teams')->update(['coordination_id' => $defaultId]);
        }

        Schema::table('teams', function (Blueprint $table) {
            $table->dropUnique('teams_name_unique');
            $table->unsignedBigInteger('coordination_id')->nullable(false)->change();
            $table->foreign('coordination_id')->references('id')->on('coordinations')->restrictOnDelete();
            $table->unique(['coordination_id', 'name']);
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique('categories_name_unique');
            $table->foreignId('coordination_id')->nullable()->after('id')
                ->constrained('coordinations')->restrictOnDelete();
            $table->unique(['coordination_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_coordination_id');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['coordination_id', 'name']);
            $table->dropConstrainedForeignId('coordination_id');
            $table->unique('name');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropUnique(['coordination_id', 'name']);
            $table->dropForeign(['coordination_id']);
            $table->dropColumn('coordination_id');
            $table->unique('name');
        });
    }

    private function createDefaultCoordination(): ?int
    {
        if (! DB::table('teams')->exists() && ! DB::table('users')->exists()) {
            return null;
        }

        $now = now();
        $stationId = DB::table('stations')->insertGetId([
            'name' => self::DEFAULT_STATION,
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $coordinationId = DB::table('coordinations')->insertGetId([
            'station_id' => $stationId,
            'name' => self::DEFAULT_COORDINATION,
            'code' => self::DEFAULT_CODE,
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Las solicitudes de acceso pendientes llegan al jefe de la coordinacion por defecto.
        DB::table('users')->where('status', 'pending')->update(['requested_coordination_id' => $coordinationId]);

        $bossIds = DB::table('users')
            ->join('model_has_roles', function ($join): void {
                $join->on('model_has_roles.model_id', '=', 'users.id')
                    ->where('model_has_roles.model_type', 'App\Models\User');
            })
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'jefe_zona')
            ->where('users.status', 'active')
            ->pluck('users.id');

        foreach ($bossIds as $userId) {
            DB::table('coordination_user')->insert([
                'coordination_id' => $coordinationId,
                'user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $coordinationId;
    }
};
