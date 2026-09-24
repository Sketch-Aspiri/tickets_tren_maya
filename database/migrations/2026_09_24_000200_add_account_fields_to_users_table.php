<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->after('password')->index();
            $table->foreignId('team_id')
                ->nullable()
                ->after('status')
                ->constrained('teams')
                ->nullOnDelete();

            // 2FA: el secreto se guarda cifrado (cast `encrypted`); los codigos de recuperacion, hasheados.
            $table->text('two_factor_secret')->nullable()->after('team_id');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            // Ultimo periodo TOTP aceptado: evita reutilizar un mismo codigo.
            $table->unsignedBigInteger('two_factor_last_timestamp')->nullable()->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('team_id');
            $table->dropIndex(['status']);
            $table->dropColumn([
                'status',
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'two_factor_last_timestamp',
            ]);
        });
    }
};
