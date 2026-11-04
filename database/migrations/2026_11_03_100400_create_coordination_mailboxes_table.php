<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un buzon IMAP por coordinacion (`coordination_id` unico). La contrasena se guarda cifrada (cast `encrypted`
     * del modelo). La deduplicacion por Message-ID pasa a ser por coordinacion: dos buzones pueden recibir el
     * mismo mensaje (p. ej. una copia) sin que uno bloquee al otro.
     */
    public function up(): void
    {
        Schema::create('coordination_mailboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coordination_id')->unique()->constrained('coordinations')->cascadeOnDelete();
            $table->string('host');
            $table->unsignedSmallInteger('port')->default(993);
            $table->string('encryption', 10)->default('ssl');
            $table->string('username');
            $table->text('password');
            $table->string('folder', 100)->default('INBOX');
            $table->boolean('active')->default(true);
            $table->timestamp('last_polled_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::table('incoming_emails', function (Blueprint $table) {
            $table->dropUnique(['message_id']);
            $table->unique(['coordination_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::table('incoming_emails', function (Blueprint $table) {
            $table->dropUnique(['coordination_id', 'message_id']);
            $table->unique('message_id');
        });

        Schema::dropIfExists('coordination_mailboxes');
    }
};
