<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hoja de Excel que alimenta la bandeja de revisión de una coordinación. La configuración (enlace, hoja y mapeo
 * de columnas) es asignable; la conexión con Microsoft (cuenta, tokens) y el estado de sincronización solo los
 * escribe el sistema con `forceFill`, nunca la petición. Los tokens están cifrados y ocultos.
 */
final class SpreadsheetSource extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'coordination_id',
        'name',
        'share_url',
        'worksheet',
        'header_row',
        'subject_column',
        'body_columns',
        'requester_name_column',
        'requester_email_column',
        'received_at_column',
        'key_column',
        'active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['access_token', 'refresh_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'coordination_id' => 'integer',
            'header_row' => 'integer',
            'body_columns' => 'array',
            'active' => 'boolean',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Coordination, $this>
     */
    public function coordination(): BelongsTo
    {
        return $this->belongsTo(Coordination::class);
    }

    public function isConnected(): bool
    {
        return $this->refresh_token !== null && $this->refresh_token !== '';
    }
}
