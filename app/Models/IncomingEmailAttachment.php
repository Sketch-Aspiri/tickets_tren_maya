<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Adjunto de un correo entrante AÚN NO revisado, guardado en el disco privado con nombre aleatorio
 * (`path`). No es sujeto polimórfico (no está en MorphMap) ni se sirve por la ruta de descarga de
 * adjuntos de tickets/actividades. Al convertir el correo, se copia a un `Attachment` real.
 */
class IncomingEmailAttachment extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'original_name',
        'path',
        'mime',
        'size',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<IncomingEmail, $this>
     */
    public function incomingEmail(): BelongsTo
    {
        return $this->belongsTo(IncomingEmail::class);
    }
}
