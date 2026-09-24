<?php

declare(strict_types=1);

namespace App\Enums;

use Maatwebsite\Excel\Excel;

/**
 * Formatos de exportacion permitidos. El formato SIEMPRE se elige de este enum validado en el servidor
 * (nunca un nombre de archivo, plantilla ni ruta que llegue del cliente). Hoy solo Excel basico (CLAUDE.md §11);
 * PDF y reportes avanzados quedan fuera del MVP.
 */
enum ExportFormat: string
{
    case Xlsx = 'xlsx';

    public function extension(): string
    {
        return $this->value;
    }

    /**
     * Tipo de escritor de maatwebsite/excel.
     */
    public function writerType(): string
    {
        return match ($this) {
            self::Xlsx => Excel::XLSX,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
