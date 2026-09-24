<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\TicketStatus;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Builder;

/**
 * Comportamiento común de lo que recorre la máquina de estados (tickets y actividades, sección 6):
 * "abierto" y "vencido". Requiere las columnas `status` (cast a TicketStatus) y `due_date` (cast a date).
 * "Vencido" NUNCA es un estado: es un cálculo (fecha límite anterior a hoy en hora de negocio y estado no final).
 */
trait HasWorkflow
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query
            ->whereNotNull("{$table}.due_date")
            ->where("{$table}.due_date", '<', LocalTime::today())
            ->whereNotIn("{$table}.status", TicketStatus::finalValues());
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn($query->getModel()->getTable().'.status', TicketStatus::finalValues());
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->due_date->toDateString() < LocalTime::today()
            && ! $this->status->isFinal();
    }
}
