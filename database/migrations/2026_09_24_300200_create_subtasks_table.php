<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Subtareas de una actividad. El avance de la actividad se calcula con COUNT sobre esta tabla
     * (índice (activity_id, done)), nunca cargando las filas.
     */
    public function up(): void
    {
        Schema::create('subtasks', function (Blueprint $table) {
            $table->id();
            // Las subtareas no existen sin su actividad: si la actividad se borra físicamente, se van con ella.
            $table->foreignId('activity_id')->constrained('activities')->cascadeOnDelete();
            $table->string('title');
            // Responsable opcional; si el usuario desapareciera, la subtarea queda sin responsable.
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('done')->default(false);
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            $table->index(['activity_id', 'done']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subtasks');
    }
};
