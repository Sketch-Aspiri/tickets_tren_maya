<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Adjunto guardado en el disco privado con nombre aleatorio (`path`). Solo se sirve por
 * AttachmentController, que verifica permiso; `path` nunca se serializa ni se muestra.
 */
class Attachment extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
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
            'user_id' => 'integer',
            'size' => 'integer',
        ];
    }

    /**
     * Solo png/jpg se previsualizan en linea; se decide por la extension guardada por el servidor (lista blanca
     * validada contra el MIME real al subir), nunca por el nombre original.
     */
    public function previewMime(): ?string
    {
        return match (strtolower(pathinfo($this->path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => null,
        };
    }

    public function isPreviewableImage(): bool
    {
        return $this->previewMime() !== null;
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
