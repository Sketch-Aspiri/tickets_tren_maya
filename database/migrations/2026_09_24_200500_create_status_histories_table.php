<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_histories', function (Blueprint $table) {
            $table->id();
            $table->string('historable_type');
            $table->unsignedBigInteger('historable_id');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['historable_type', 'historable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_histories');
    }
};
