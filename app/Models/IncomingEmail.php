<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IncomingEmailStatus;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Correo entrante en espera de revisión humana (jefe de zona, administrador o coordinador): asunto,
 * cuerpo y adjuntos ya ingeridos, pendientes de convertirse en Actividad o descartarse. Nunca se borra
 * (ni soft ni hard delete); "descartado" es un valor de `status`.
 *
 * `status`, `reviewed_by`, `reviewed_at`, `discard_reason` y `activity_id` se escriben SIEMPRE desde
 * IncomingEmailReviewService (forceFill), nunca por mass-assignment del controlador — por eso están
 * deliberadamente fuera de `$fillable`.
 */
final class IncomingEmail extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'coordination_id',
        'message_id',
        'from_email',
        'from_name',
        'subject',
        'body',
        'received_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'coordination_id' => 'integer',
            'received_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'status' => IncomingEmailStatus::class,
            'reviewed_by' => 'integer',
            'activity_id' => 'integer',
        ];
    }

    /**
     * Correos que `$actor` puede ver: todos (administrador) o los de las coordinaciones que supervisa (jefe de
     * zona) o a las que pertenece (coordinador). Cualquier otro rol: ninguno.
     *
     * @param  Builder<IncomingEmail>  $query
     * @return Builder<IncomingEmail>
     */
    public function scopeVisibleTo(Builder $query, User $actor): Builder
    {
        return match ($actor->roleEnum()) {
            UserRole::Administrador => $query,
            UserRole::JefeZona, UserRole::Coordinador => $query->whereIn('incoming_emails.coordination_id', $actor->coordinationIdsQuery()),
            default => $query->whereIn('incoming_emails.id', []),
        };
    }

    /**
     * @return BelongsTo<Coordination, $this>
     */
    public function coordination(): BelongsTo
    {
        return $this->belongsTo(Coordination::class);
    }

    /**
     * @return HasMany<IncomingEmailAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(IncomingEmailAttachment::class);
    }

    /**
     * Quien descartó o convirtió el correo.
     *
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Actividad generada al convertir el correo.
     *
     * @return BelongsTo<Activity, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'activity_id');
    }
}
