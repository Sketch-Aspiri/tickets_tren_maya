<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Actividades creadas desde el Dashboard por el puente (decision 92): `external_ref` identifica el hecho de
 * origen (p. ej. `dashboard:solicitud:12:observada:1790000000`) y es UNICO, asi que reenviar el mismo evento no
 * crea otra actividad. Las actividades normales lo dejan nulo (los nulos no chocan en el indice unico).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->string('external_ref', 120)->nullable()->unique()->after('occurrence_date');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->dropUnique(['external_ref']);
            $table->dropColumn('external_ref');
        });
    }
};
