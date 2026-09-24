<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Comment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Comentarios de texto plano. El cuerpo es contenido no confiable: se guarda tal cual (sin HTML
 * interpretado) y las vistas lo imprimen siempre escapado con `{{ }}`.
 */
final class CommentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function add(User $actor, Ticket $ticket, string $body): Comment
    {
        return DB::transaction(function () use ($actor, $ticket, $body): Comment {
            $comment = new Comment(['body' => trim($body), 'user_id' => $actor->getKey()]);
            $ticket->comments()->save($comment);

            $this->audit->record('tickets', 'comment_added', $ticket, $actor, [], [], [
                'folio' => $ticket->folio,
                'comment_id' => $comment->getKey(),
            ]);

            return $comment;
        });
    }
}
