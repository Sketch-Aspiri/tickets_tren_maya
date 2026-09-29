<?php

declare(strict_types=1);

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Hoja "Info" del Excel en la nube: cuando se actualizo, cuantas filas trae y el aviso de que es de solo lectura.
 * Solo contiene textos del sistema (nada de usuarios).
 */
final class CloudInfoSheet implements FromArray, ShouldAutoSize, WithTitle
{
    /**
     * @param  list<array{0: string, 1: string}>  $rows
     */
    public function __construct(private readonly array $rows) {}

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function array(): array
    {
        return $this->rows;
    }

    public function title(): string
    {
        return __('tickets.cloud_sync.info_sheet');
    }
}
