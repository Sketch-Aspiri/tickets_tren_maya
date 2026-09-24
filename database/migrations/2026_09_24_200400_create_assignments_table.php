<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Polimórfica (ticket ahora, actividad en el Sprint 3). El índice único (assignable_type,
     * assignable_id, user_id) evita duplicados y también sirve para buscar por (type, id) al ser su prefijo.
     * "Un solo responsable" lo garantiza AssignmentService bajo lock del ticket.
     */
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->string('assignable_type');
            $table->unsignedBigInteger('assignable_id');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('role', 20);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['assignable_type', 'assignable_id', 'user_id']);
            $table->index(['user_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
    }
};
