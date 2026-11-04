<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Coordinacion a la que pertenece cada entrada de la bitacora (la resuelve `AuditCoordinationResolver` al
     * escribirla), para que el jefe de zona vea solo la de sus coordinaciones. Sin llave foranea a proposito: la
     * bitacora es historica y no debe bloquear ni perder filas si una coordinacion se elimina. Las filas
     * existentes pasan a la primera coordinacion (la de por defecto).
     */
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->unsignedBigInteger('coordination_id')->nullable();
            $table->index('coordination_id');
        });

        $defaultId = DB::table('coordinations')->orderBy('id')->value('id');

        if ($defaultId !== null) {
            DB::table('activity_log')->update(['coordination_id' => $defaultId]);
        }
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['coordination_id']);
            $table->dropColumn('coordination_id');
        });
    }
};
