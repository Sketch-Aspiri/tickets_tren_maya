<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inicio de sesion unico con el Dashboard (decision 88): la cuenta queda vinculada la primera vez que su
 * dueno inicia sesion aqui con contrasena llegando del Dashboard con el mismo correo (prueba que controla
 * las dos cuentas). Sin vinculo, el correo por si solo nunca abre una sesion: el registro de Tickets no
 * verifica correos, asi que alguien podria registrarse con el correo de otra persona.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('sso_linked_at')->nullable()->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('sso_linked_at');
        });
    }
};
