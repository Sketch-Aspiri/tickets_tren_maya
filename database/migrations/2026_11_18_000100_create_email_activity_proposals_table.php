<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Propuestas de actividad que hace la IA (cliente MCP) a partir de un correo entrante (decision 94). Una persona
 * las confirma, las corrige o las rechaza; `unchanged` dice si la confirmo sin cambiar nada y alimenta la metrica
 * que decide cuando pasar al modo automatico. Solo guardan datos validados por el servidor (responsable, equipo,
 * fecha); el titulo y la descripcion de la actividad siempre salen del correo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_activity_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incoming_email_id')->constrained()->restrictOnDelete();
            $table->foreignId('mcp_client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('responsible_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('team_id')->constrained()->restrictOnDelete();
            $table->date('due_date');
            $table->unsignedTinyInteger('confidence');
            $table->string('rationale', 500)->nullable();
            $table->string('status', 20)->default('pending');
            $table->boolean('unchanged')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['incoming_email_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_activity_proposals');
    }
};
