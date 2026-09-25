<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un usuario (de cualquier rol) puede pertenecer a varios equipos: `users.team_id` se reemplaza por la
     * tabla pivote `team_user`. Los datos existentes se copian antes de quitar la columna.
     *
     * Consecuencia: un coordinador ya no coordina "solo un equipo" (podia ser de varios), asi que el indice
     * unico de `teams.coordinator_id` pasa a ser un indice normal; cada equipo sigue teniendo un unico
     * coordinador.
     */
    public function up(): void
    {
        Schema::create('team_user', function (Blueprint $table) {
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['team_id', 'user_id']);
            $table->index('user_id');
        });

        DB::table('users')
            ->whereNotNull('team_id')
            ->orderBy('id')
            ->select(['id', 'team_id'])
            ->each(function (object $user): void {
                DB::table('team_user')->insert([
                    'team_id' => $user->team_id,
                    'user_id' => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('team_id');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropUnique('teams_coordinator_id_unique');
        });
    }

    /**
     * Vuelve al modelo de un solo equipo: conserva el de menor id de cada usuario y libera los equipos que
     * un coordinador tenia "de sobra" (para poder restaurar el indice unico).
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('status')->constrained('teams')->nullOnDelete();
        });

        DB::table('team_user')
            ->select('user_id', DB::raw('MIN(team_id) as team_id'))
            ->groupBy('user_id')
            ->get()
            ->each(fn (object $row) => DB::table('users')->where('id', $row->user_id)->update(['team_id' => $row->team_id]));

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

        Schema::dropIfExists('team_user');
    }
};
