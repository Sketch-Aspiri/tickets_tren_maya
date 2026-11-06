<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hoja de Excel (OneDrive personal, leída con la cuenta Microsoft que se conecte) que alimenta la bandeja de
     * "Correos entrantes" de una coordinación: una por coordinación. Los tokens van cifrados (cast `encrypted`).
     */
    public function up(): void
    {
        Schema::create('spreadsheet_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coordination_id')->unique()->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->text('share_url');
            $table->string('worksheet', 100)->nullable();
            $table->unsignedSmallInteger('header_row')->default(1);
            $table->string('subject_column', 100);
            $table->json('body_columns')->nullable();
            $table->string('requester_name_column', 100)->nullable();
            $table->string('requester_email_column', 100)->nullable();
            $table->string('key_column', 100)->nullable();
            $table->boolean('active')->default(true);
            $table->string('account_name', 255)->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spreadsheet_sources');
    }
};
