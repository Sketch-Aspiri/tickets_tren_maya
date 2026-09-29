<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConversationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conversacion del chat interno: 1 a 1 (participantes en `conversation_user`) o canal de equipo
 * (membresia = `team_user`). `scopeVisibleTo` es la UNICA definicion de alcance de lectura.
 */
class Conversation extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'team_id',
        'direct_key',
        'last_message_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ConversationType::class,
            'team_id' => 'integer',
            'last_message_at' => 'datetime',
        ];
    }

    /**
     * Directos: solo sus participantes (ni el jefe ni el administrador leen chats ajenos).
     * Canal de equipo: los integrantes del equipo. Cuenta sin acceso: ninguna.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (! $user->canAccessApplication()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $scope) use ($user): void {
            $scope->where(function (Builder $direct) use ($user): void {
                $direct->where('conversations.type', ConversationType::Direct->value)
                    ->whereIn('conversations.id', fn ($sub) => $sub->select('conversation_id')
                        ->from('conversation_user')
                        ->where('user_id', $user->getKey()));
            })->orWhere(function (Builder $team) use ($user): void {
                $team->where('conversations.type', ConversationType::Team->value)
                    ->whereIn('conversations.team_id', $user->teamIdsQuery());
            });
        });
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_user');
    }

    /**
     * @return HasMany<ChatMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    /**
     * Nombre para mostrar a `$viewer`: el equipo, o la otra persona del chat 1 a 1.
     * Requiere `team` o `participants` cargados segun el tipo.
     */
    public function titleFor(User $viewer): string
    {
        if ($this->type === ConversationType::Team) {
            return (string) $this->team?->name;
        }

        return (string) $this->otherParticipant($viewer)?->name;
    }

    /**
     * En un chat 1 a 1, la otra persona (requiere `participants` cargados). Null en canales de equipo.
     */
    public function otherParticipant(User $viewer): ?User
    {
        if ($this->type !== ConversationType::Direct) {
            return null;
        }

        return $this->participants->firstWhere('id', '!=', $viewer->getKey());
    }
}
