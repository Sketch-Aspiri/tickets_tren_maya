<?php

declare(strict_types=1);

namespace App\Support;

use BackedEnum;
use Illuminate\Database\Eloquent\Builder;

/**
 * Piezas de consulta compartidas por los listados de tickets y actividades (búsqueda con LIKE escapado y
 * ordenamientos de negocio). Los nombres de columna vienen SIEMPRE del código (constantes / listas blancas);
 * el texto del usuario solo viaja como binding.
 */
final class ListingQuery
{
    /** Escape de LIKE portable (MySQL y SQLite): `!` como carácter de escape. */
    private const LIKE_ESCAPE = '!';

    /**
     * `columna LIKE %término% ESCAPE '!'` sobre cada columna (OR). `%`, `_` y `!` del término son literales.
     *
     * @param  Builder<*>  $query
     * @param  list<string>  $columns  Columnas calificadas de la tabla (p. ej. `activities.title`), definidas en código.
     * @return Builder<*>
     */
    public static function search(Builder $query, string $term, array $columns): Builder
    {
        $escape = self::LIKE_ESCAPE;
        $escaped = str_replace([$escape, '%', '_'], [$escape.$escape, $escape.'%', $escape.'_'], $term);
        $pattern = "%{$escaped}%";

        return $query->where(function (Builder $inner) use ($pattern, $escape, $columns): void {
            foreach ($columns as $column) {
                $inner->orWhereRaw("{$column} like ? escape '{$escape}'", [$pattern]);
            }
        });
    }

    /**
     * Ordena una fecha opcional dejando los nulos al final.
     *
     * @param  Builder<*>  $query
     */
    public static function orderByDateNullsLast(Builder $query, string $column, string $direction): void
    {
        $query->orderByRaw("{$column} is null")->orderBy($column, $direction === 'asc' ? 'asc' : 'desc');
    }

    /**
     * Ordena por el orden de declaración de un enum (no alfabético) con un CASE parametrizado.
     *
     * @param  Builder<*>  $query
     * @param  list<BackedEnum>  $cases
     */
    public static function orderByEnum(Builder $query, string $column, array $cases, string $direction): void
    {
        $whens = implode(' ', array_map(fn (int $index): string => 'when ? then '.($index + 1), array_keys($cases)));
        $bindings = array_map(fn (BackedEnum $case): string|int => $case->value, $cases);
        $dir = $direction === 'asc' ? 'asc' : 'desc';

        $query->orderByRaw("case {$column} {$whens} else 0 end {$dir}", $bindings);
    }
}
