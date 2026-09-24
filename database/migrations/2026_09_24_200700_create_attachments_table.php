<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->string('attachable_type');
            $table->unsignedBigInteger('attachable_id');
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('original_name');
            // Ruta relativa en el disco privado, con nombre aleatorio. Nunca se muestra al usuario.
            $table->string('path')->unique();
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['attachable_type', 'attachable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
