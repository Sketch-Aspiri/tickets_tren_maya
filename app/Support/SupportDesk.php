<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Coordination;

/**
 * La coordinación «Soporte del sistema» (del edificio zonal): todos pueden abrirle tickets, y sus categorías se
 * ofrecen a cualquiera al crear un ticket. Se identifica por `tickets.support.coordination` (código o nombre, sin
 * distinguir mayúsculas). Si no existe o está inactiva, no hay soporte configurado y nada cambia.
 */
final class SupportDesk
{
    public static function coordination(): ?Coordination
    {
        return once(function (): ?Coordination {
            $identifier = trim((string) config('tickets.support.coordination'));

            if ($identifier === '') {
                return null;
            }

            return Coordination::query()
                ->where('active', true)
                ->where(fn ($query) => $query->whereRaw('lower(code) = ?', [mb_strtolower($identifier)])
                    ->orWhereRaw('lower(name) = ?', [mb_strtolower($identifier)]))
                ->orderBy('id')
                ->first();
        });
    }

    public static function coordinationId(): ?int
    {
        $id = self::coordination()?->getKey();

        return $id === null ? null : (int) $id;
    }
}
