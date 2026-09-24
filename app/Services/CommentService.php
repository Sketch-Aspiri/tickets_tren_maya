<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Activity;
use App\Models\Comment;
use App\Models\Ticket;
use App\Models\User;
use App\Support\WorkflowSubject;
use Illuminate\Support\Facades\DB;

/**
 * Comentarios de texto plano (tickets y actividades). El cuerpo es contenido no confiable: se guarda tal
 * cual (sin HTML interpretado) y las vistas lo imprimen siempre escapado con `{{ }}`.
 */
final class CommentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function add(User $actor, Ticket|Activity $subject, string $body): Comment
    {
        return DB::transaction(function () use ($actor, $subject, $body): Comment {
            $comment = new Comment(['body' => trim($body), 'user_id' => $actor->getKey()]);
            $subject->comments()->save($comment);

            $this->audit->record(WorkflowSubject::group($subject), 'comment_added', $subject, $actor, [], [], [
                'folio' => $subject->folio,
                'comment_id' => $comment->getKey(),
            ]);

            return $comment;
        });
    }
}
