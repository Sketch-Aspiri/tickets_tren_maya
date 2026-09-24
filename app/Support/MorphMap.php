<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Attachment;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Único lugar donde se definen los alias polimórficos (`Relation::enforceMorphMap`).
 *
 * - `ticket` / `activity`: alias cortos y estables para las tablas polimórficas (assignments,
 *   status_histories, comments, attachments) que en el Sprint 3 también servirán a las actividades.
 * - `App\Models\User` / `App\Models\Team` conservan como ALIAS su nombre de clase completo: son los
 *   valores que Spatie Permission, Activitylog y Notifications ya guardaron en la BD en el Sprint 1.
 *   Así no se reescriben filas existentes (por ejemplo `model_has_roles.model_type`).
 *
 * Al estar el mapa forzado, un modelo nuevo que sea objetivo polimórfico (sujeto de bitácora, etc.)
 * DEBE agregarse aquí, o Eloquent lanzará ClassMorphViolationException.
 */
final class MorphMap
{
    /**
     * @return array<string, class-string>
     */
    public static function aliases(): array
    {
        return [
            'ticket' => Ticket::class,
            // El modelo App\Models\Activity llega en el Sprint 3; el alias queda reservado desde ahora.
            'activity' => 'App\Models\Activity',
            'comment' => Comment::class,
            'attachment' => Attachment::class,
            'category' => Category::class,
            'App\Models\User' => User::class,
            'App\Models\Team' => Team::class,
        ];
    }

    public static function register(): void
    {
        Relation::enforceMorphMap(self::aliases());
    }
}
