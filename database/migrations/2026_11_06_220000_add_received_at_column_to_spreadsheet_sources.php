<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Columna del Excel con la fecha de recepción de cada solicitud: así la bandeja conserva el orden real del
     * archivo en lugar de la hora en que se importó.
     */
    public function up(): void
    {
        Schema::table('spreadsheet_sources', function (Blueprint $table): void {
            $table->string('received_at_column', 100)->nullable()->after('requester_email_column');
        });
    }

    public function down(): void
    {
        Schema::table('spreadsheet_sources', function (Blueprint $table): void {
            $table->dropColumn('received_at_column');
        });
    }
};
