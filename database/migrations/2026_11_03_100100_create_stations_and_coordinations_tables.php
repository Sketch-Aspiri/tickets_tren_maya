<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jerarquia organizacional: Estacion/Edificio -> Coordinacion -> Equipo. `coordination_user` liga a los
     * jefes de zona con las coordinaciones que supervisan (uno o varios por jefe).
     */
    public function up(): void
    {
        Schema::create('stations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('coordinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained('stations')->restrictOnDelete();
            $table->string('name');
            $table->string('code', 30)->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['station_id', 'name']);
        });

        Schema::create('coordination_user', function (Blueprint $table) {
            $table->foreignId('coordination_id')->constrained('coordinations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['coordination_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coordination_user');
        Schema::dropIfExists('coordinations');
        Schema::dropIfExists('stations');
    }
};
