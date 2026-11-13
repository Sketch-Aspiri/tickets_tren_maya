<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clientes del servidor MCP (decision 94): grokbot, el Claude del jefe, etc. Cada cliente actua COMO una cuenta
 * real (`user_id`), asi que las Policies y el alcance de equipos siguen siendo la unica fuente de autorizacion.
 * Solo se guarda el hash SHA-256 del token; el token en claro se muestra una vez al crearlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_clients', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80)->unique();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->boolean('can_write')->default(false);
            $table->json('allowed_ips')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_clients');
    }
};
