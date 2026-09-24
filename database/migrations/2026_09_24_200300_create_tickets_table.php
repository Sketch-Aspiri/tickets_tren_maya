<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('folio', 20)->unique();
            $table->string('title');
            $table->text('description');
            $table->string('priority', 20);
            $table->string('status', 20);
            // Una categoría con tickets no se elimina (restrict); tampoco un equipo ni un usuario.
            $table->foreignId('category_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->foreignId('team_id')->constrained('teams')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('source', 10)->default('web');
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

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
        Schema::dropIfExists('tickets');
    }
};
