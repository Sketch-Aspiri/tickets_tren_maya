<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contador de folios por prefijo (TM, ACT) y año. Se incrementa bajo `lockForUpdate` dentro de la
     * misma transacción que crea el registro (App\Services\FolioGenerator).
     */
    public function up(): void
    {
        Schema::create('folio_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('prefix', 10);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['prefix', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folio_sequences');
    }
};
