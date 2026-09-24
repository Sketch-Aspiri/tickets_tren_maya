<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ExportFormat;
use App\Models\User;
use App\Support\LocalTime;
use Closure;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Logica comun de las exportaciones a Excel (tickets y actividades): tope de filas con aviso de recorte, nombre
 * de archivo generado por el servidor, cabeceras sin cache y evento `exported` en la bitacora (solo si el archivo
 * se genero). Cada tipo aporta solo como obtener sus filas y como armar su hoja.
 */
final class ExportDownloader
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  string  $type  Tipo de exportacion (`tickets`, `activities`): prefijo del archivo y dato de bitacora.
     * @param  array<string, mixed>  $filters  Filtros ya validados por el Form Request del listado.
     * @param  Closure(int): Collection<int, mixed>  $fetch  Recibe el limite (+1) y devuelve las filas ya acotadas por alcance y filtros.
     * @param  Closure(Collection<int, mixed>): object  $sheet  Arma el objeto de exportacion (usa `Exportable`).
     */
    public function download(User $actor, string $type, array $filters, ExportFormat $format, Closure $fetch, Closure $sheet): BinaryFileResponse
    {
        $limit = (int) config('tickets.export.max_rows');

        // Una fila de mas para saber si el resultado se recorto.
        $rows = $fetch($limit + 1);
        $truncated = $rows->count() > $limit;
        $rows = $rows->take($limit)->values();

        /** @var Exportable $export */
        $export = $sheet($rows);

        $response = Excel::download(
            $export,
            $type.'-'.LocalTime::now()->format('Ymd-His').'.'.$format->extension(),
            $format->writerType(),
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'],
        );

        $this->audit->record('exports', 'exported', null, $actor, extra: [
            'type' => $type,
            'format' => $format->value,
            'rows' => $rows->count(),
            'truncated' => $truncated,
            'filters' => array_filter($filters, fn (mixed $value): bool => $value !== null && $value !== false && $value !== ''),
        ]);

        return $response;
    }
}
