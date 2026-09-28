<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bandeja de correos entrantes pendientes de revisión humana (jefe/administrador/coordinador). Un
     * correo nunca se borra: "descartado" es un valor de `status`, no un borrado (por eso no hay
     * softDeletes). `message_id` es el header `Message-ID` real o, si el correo no trae uno, un hash
     * determinista calculado por el servicio de ingesta; en ambos casos nunca es nulo, así una sola
     * columna unique basta para el dedup.
     */
    public function up(): void
    {
        Schema::create('incoming_emails', function (Blueprint $table) {
            $table->id();
            $table->string('message_id', 998)->unique();
            $table->string('from_email', 255);
            $table->string('from_name', 255)->nullable();
            $table->string('subject', 500);
            $table->text('body');
            $table->timestamp('received_at');
            $table->string('status', 20);
            // Restrict: un usuario con correos revisados no se elimina (los usuarios solo se inactivan).
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->foreign('reviewed_by')->references('id')->on('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('discard_reason')->nullable();
            // Restrict: la actividad generada por la conversión no se elimina mientras el correo la referencie.
            $table->unsignedBigInteger('activity_id')->nullable();
            $table->foreign('activity_id')->references('id')->on('activities')->restrictOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('received_at');
            $table->index('from_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_emails');
    }
};
