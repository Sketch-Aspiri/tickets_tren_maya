<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cada correo entrante pertenece a una coordinacion (la de su buzon). Los correos existentes pasan a la
     * primera coordinacion (la de por defecto que crea la migracion anterior); en una base sin correos no hace
     * falta ninguna.
     */
    public function up(): void
    {
        Schema::table('incoming_emails', function (Blueprint $table) {
            $table->unsignedBigInteger('coordination_id')->nullable()->after('id');
        });

        $defaultId = DB::table('coordinations')->orderBy('id')->value('id');

        if ($defaultId !== null) {
            DB::table('incoming_emails')->update(['coordination_id' => $defaultId]);
        }

        Schema::table('incoming_emails', function (Blueprint $table) {
            $table->unsignedBigInteger('coordination_id')->nullable(false)->change();
            $table->foreign('coordination_id')->references('id')->on('coordinations')->restrictOnDelete();
            $table->index(['coordination_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('incoming_emails', function (Blueprint $table) {
            $table->dropIndex(['coordination_id', 'status']);
            $table->dropForeign(['coordination_id']);
            $table->dropColumn('coordination_id');
        });
    }
};
