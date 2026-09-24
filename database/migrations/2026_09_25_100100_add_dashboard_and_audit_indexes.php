<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indices que justifican las consultas agregadas del panel de seguimiento (Sprint 5):
     * - `(status, completed_at)`: completados en el periodo, tiempo de cierre y tendencia de completados.
     * - `(team_id, created_at)`: creados en el periodo dentro del alcance de un equipo (coordinador / filtro).
     * Y los del visor de bitacora: por fecha (rango) y por evento (filtro). `subject` y `causer` ya tienen
     * indice compuesto en la migracion de `activity_log`.
     */
    public function up(): void
    {
        foreach (['tickets', 'activities'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->index(['status', 'completed_at'], "{$tableName}_status_completed_at_index");
                $table->index(['team_id', 'created_at'], "{$tableName}_team_id_created_at_index");
            });
        }

        Schema::table('activity_log', function (Blueprint $table) {
            $table->index('created_at', 'activity_log_created_at_index');
            $table->index('event', 'activity_log_event_index');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex('activity_log_event_index');
            $table->dropIndex('activity_log_created_at_index');
        });

        foreach (['tickets', 'activities'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropIndex("{$tableName}_team_id_created_at_index");
                $table->dropIndex("{$tableName}_status_completed_at_index");
            });
        }
    }
};
