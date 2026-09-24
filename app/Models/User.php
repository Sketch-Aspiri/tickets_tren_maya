<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsActivity, Notifiable;

    /**
     * Solo lo que el propio usuario puede enviar al registrarse. `status`,
     * `team_id` y los campos de 2FA se asignan exclusivamente desde Services.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_last_timestamp',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_timestamp' => 'integer',
        ];
    }

    /**
     * Campos seguros para la bitacora: nunca contrasenas, tokens ni secretos 2FA.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'status', 'team_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('users');
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Usuarios activos con el rol dado (unica definicion de "activo con rol" para consultas).
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeActiveWithRole(Builder $query, UserRole $role): Builder
    {
        return $query
            ->role($role->value)
            ->where('status', UserStatus::Active->value);
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isPending(): bool
    {
        return $this->status === UserStatus::Pending;
    }

    public function isInactive(): bool
    {
        return $this->status === UserStatus::Inactive;
    }

    /**
     * Rol principal del usuario (un usuario tiene exactamente un rol del sistema).
     */
    public function roleEnum(): ?UserRole
    {
        $name = $this->roles->first()?->name;

        return $name === null ? null : UserRole::tryFrom($name);
    }

    public function hasSystemRole(UserRole $role): bool
    {
        return $this->roleEnum() === $role;
    }

    /**
     * Un usuario solo puede usar la aplicacion si esta activo y tiene rol.
     */
    public function canAccessApplication(): bool
    {
        return $this->isActive() && $this->roleEnum() !== null;
    }

    public function requiresTwoFactor(): bool
    {
        return $this->roleEnum()?->requiresTwoFactor() ?? false;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }
}
