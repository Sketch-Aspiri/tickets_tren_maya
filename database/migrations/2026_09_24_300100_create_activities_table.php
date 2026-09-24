<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Actividades (tareas planificadas). Una actividad con `recurrence_rule` es la PLANTILLA (madre) de
     * una serie; cada instancia generada es otra fila con `parent_activity_id` apuntando a la plantilla
     * y `occurrence_date` = fecha de esa ocurrencia. El índice único (parent_activity_id, occurrence_date)
     * hace idempotente la generación: dos ejecuciones (o dos procesos) no pueden duplicar una ocurrencia.
     * Las filas normales (sin padre) tienen `occurrence_date` nulo y el índice único no las restringe.
     */
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->string('folio', 20)->unique();
            $table->string('title');
            $table->text('description');
            $table->string('priority', 20);
            $table->string('status', 20);
            // Restrict: una categoría, un equipo o un usuario con actividades no se elimina (los usuarios solo se inactivan).
            $table->foreignId('category_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->foreignId('team_id')->constrained('teams')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->json('recurrence_rule')->nullable();
            $table->foreignId('parent_activity_id')->nullable()->constrained('activities')->restrictOnDelete();
            $table->date('occurrence_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // También cubre las búsquedas por padre (prefijo del índice).
            $table->unique(['parent_activity_id', 'occurrence_date']);
            $table->index('status');
            $table->index('priority');
            $table->index('due_date');
            // Listados por equipo y estado (alcance del coordinador, filtros, panel del Sprint 5).
            $table->index(['team_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
