<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Cliente del servidor MCP (decision 94). El token nunca se guarda en claro: solo su hash SHA-256.
 */
class McpClient extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'user_id',
        'can_write',
        'dashboard_access',
        'allowed_ips',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'can_write' => 'boolean',
            'allowed_ips' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public const DASHBOARD_NONE = 'none';

    public const DASHBOARD_READ = 'read';

    public const DASHBOARD_WRITE = 'write';

    /**
     * Si este cliente puede usar una herramienta del Dashboard (decision 97): las de lectura con `read` o `write`; las
     * que escriben solo con `write` Y con permiso de escritura general.
     */
    public function dashboardAllows(bool $writes): bool
    {
        return $writes
            ? $this->dashboard_access === self::DASHBOARD_WRITE && $this->can_write
            : in_array($this->dashboard_access, [self::DASHBOARD_READ, self::DASHBOARD_WRITE], true);
    }

    /**
     * Crea un token aleatorio de 256 bits con prefijo reconocible; devuelve [token en claro, hash].
     *
     * @return array{0: string, 1: string}
     */
    public static function newToken(): array
    {
        $plain = 'tmcp_'.Str::random(48);

        return [$plain, self::hashToken($plain)];
    }

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
