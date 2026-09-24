<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\LocalTime;
use Illuminate\Support\Facades\DB;

/**
 * Folios consecutivos por año (`TM-2026-0001`, `ACT-2026-0001` en el Sprint 3).
 *
 * Seguro ante concurrencia: la fila del contador (prefijo + año) se bloquea con `lockForUpdate`
 * dentro de una transacción y se incrementa. Como el incremento va en la MISMA transacción que
 * crea el registro (TicketService::create), un rollback devuelve el número y no quedan huecos.
 * El año es el de la zona horaria de negocio (America/Cancun).
 */
final class FolioGenerator
{
    private const TABLE = 'folio_sequences';

    public function next(string $prefix, ?int $year = null): string
    {
        $year ??= LocalTime::year();

        return DB::transaction(function () use ($prefix, $year): string {
            // Crea el contador del año si no existe (una carrera en la creación la resuelve el índice único).
            DB::table(self::TABLE)->insertOrIgnore([
                'prefix' => $prefix,
                'year' => $year,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = DB::table(self::TABLE)
                ->where('prefix', $prefix)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            $number = (int) $sequence->last_number + 1;

            DB::table(self::TABLE)->where('id', $sequence->id)->update([
                'last_number' => $number,
                'updated_at' => now(),
            ]);

            return sprintf('%s-%d-%04d', $prefix, $year, $number);
        });
    }
}
