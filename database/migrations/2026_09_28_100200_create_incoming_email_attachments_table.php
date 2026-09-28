<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adjuntos de un correo entrante AÚN NO revisado. Tabla propia (no polimórfica, no la tabla
     * `attachments` de tickets/actividades): un correo sin triar es contenido no confiable que no debe
     * exponerse por la misma ruta de descarga ya endurecida de trabajo real. Al convertir el correo en
     * Actividad, cada fila se copia (no se reutiliza) a un `Attachment` real.
     */
    public function up(): void
    {
        Schema::create('incoming_email_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incoming_email_id')->constrained('incoming_emails')->cascadeOnDelete();
            $table->string('original_name', 100);
            // Ruta relativa en el disco privado, con nombre aleatorio. Nunca se muestra al usuario.
            $table->string('path')->unique();
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_email_attachments');
    }
};
