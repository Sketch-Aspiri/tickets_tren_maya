<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ConversationType;
use App\Exceptions\BusinessRuleException;
use App\Models\Attachment;
use App\Models\ChatMessage;
use App\Models\Conversation;
use App\Models\User;
use App\Support\AttachmentInspector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Logica del chat interno. La autorizacion (ConversationPolicy) la hace el llamador; aqui se revalidan las
 * reglas de datos (destinatario activo, adjunto valido). No registra el contenido de los mensajes en la
 * bitacora (privacidad y volumen): solo la creacion de conversaciones.
 */
final class ChatService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AttachmentInspector $inspector,
    ) {}

    /**
     * Abre (o reutiliza) el chat 1 a 1 entre dos personas. Idempotente por `direct_key`.
     */
    public function startDirect(User $actor, User $other): Conversation
    {
        if ($actor->is($other) || ! $other->canAccessApplication()) {
            throw BusinessRuleException::because('chat.errors.recipient_invalid');
        }

        $ids = [(int) $actor->getKey(), (int) $other->getKey()];
        sort($ids);

        return DB::transaction(function () use ($actor, $ids): Conversation {
            $conversation = Conversation::query()->createOrFirst(
                ['direct_key' => $ids[0].'-'.$ids[1]],
                ['type' => ConversationType::Direct],
            );

            $conversation->participants()->syncWithoutDetaching($ids);

            if ($conversation->wasRecentlyCreated) {
                $this->audit->record('chat', 'conversation_created', $conversation, $actor, [], [], ['participants' => $ids]);
            }

            return $conversation;
        });
    }

    /**
     * Conversaciones visibles del usuario (crea perezosamente el canal de cada uno de sus equipos), con
     * `unread_count`, mas recientes primero.
     *
     * @return Collection<int, Conversation>
     */
    public function listFor(User $user): Collection
    {
        $this->ensureTeamChannels($user);

        $conversations = Conversation::query()
            ->visibleTo($user)
            ->with(['team:id,name', 'participants:id,name,avatar_path'])
            ->orderByRaw('conversations.last_message_at is null')
            ->orderByDesc('conversations.last_message_at')
            ->orderByDesc('conversations.id')
            ->get();

        $unread = $this->unreadByConversation($user);

        foreach ($conversations as $conversation) {
            $conversation->setAttribute('unread_count', (int) ($unread[$conversation->getKey()] ?? 0));
        }

        return $conversations;
    }

    public function unreadTotal(User $user): int
    {
        return (int) $this->unreadByConversation($user)->sum();
    }

    /**
     * Historial inicial: los ultimos N mensajes en orden cronologico.
     *
     * @return Collection<int, ChatMessage>
     */
    public function recent(Conversation $conversation): Collection
    {
        return $this->withRelations($conversation->messages()->getQuery())
            ->orderByDesc('chat_messages.id')
            ->limit((int) config('tickets.chat.initial_messages'))
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * Mensajes posteriores a `$afterId` (polling), en orden cronologico y acotados.
     *
     * @return Collection<int, ChatMessage>
     */
    public function messagesAfter(Conversation $conversation, int $afterId): Collection
    {
        return $this->withRelations($conversation->messages()->getQuery())
            ->where('chat_messages.id', '>', $afterId)
            ->orderBy('chat_messages.id')
            ->limit((int) config('tickets.chat.poll_max_messages'))
            ->get();
    }

    public function send(User $actor, Conversation $conversation, ?string $body, ?UploadedFile $file = null): ChatMessage
    {
        $text = trim((string) $body);

        if ($text === '' && $file === null) {
            throw BusinessRuleException::because('chat.errors.empty_message');
        }

        $this->assertRecipientStillActive($actor, $conversation);

        $extension = $file === null ? null : $this->acceptedExtension($file);
        $disk = Storage::disk((string) config('tickets.attachments.disk'));
        $path = null;

        try {
            return DB::transaction(function () use ($actor, $conversation, $text, $file, $extension, $disk, &$path): ChatMessage {
                $message = new ChatMessage(['body' => $text, 'user_id' => $actor->getKey()]);
                $conversation->messages()->save($message);

                if ($file !== null) {
                    $path = $disk->putFileAs('chat/'.$conversation->getKey(), $file, Str::random(40).'.'.$extension);

                    if ($path === false) {
                        throw BusinessRuleException::because('chat.errors.upload_failed');
                    }

                    $attachment = new Attachment([
                        'user_id' => $actor->getKey(),
                        'original_name' => $this->inspector->sanitizeName($file->getClientOriginalName()),
                        'path' => $path,
                        'mime' => (string) $file->getMimeType(),
                        'size' => (int) $file->getSize(),
                    ]);
                    $message->attachments()->save($attachment);

                    $this->audit->record('chat', 'attachment_added', $conversation, $actor, [], [], [
                        'attachment_id' => $attachment->getKey(),
                        'original_name' => $attachment->original_name,
                        'mime' => $attachment->mime,
                        'size' => $attachment->size,
                    ]);
                }

                $conversation->forceFill(['last_message_at' => $message->created_at])->save();
                $this->markRead($actor, $conversation, (int) $message->getKey());

                return $message->load(['user:id,name,avatar_path', 'attachments']);
            });
        } catch (Throwable $exception) {
            // Si algo fallo despues de escribir el archivo, no se deja huerfano en disco.
            if (is_string($path)) {
                $disk->delete($path);
            }

            throw $exception;
        }
    }

    /**
     * Marca como leido hasta `$lastId` (nunca retrocede).
     */
    public function markRead(User $user, Conversation $conversation, int $lastId): void
    {
        $key = ['conversation_id' => $conversation->getKey(), 'user_id' => $user->getKey()];
        // Monotono y atomico: crea la fila en 0 si falta y solo sube el marcador (nunca retrocede con peticiones concurrentes).
        DB::table('chat_reads')->insertOrIgnore([...$key, 'last_read_message_id' => 0]);
        DB::table('chat_reads')->where($key)->where('last_read_message_id', '<', $lastId)->update(['last_read_message_id' => $lastId]);
    }

    /**
     * En un chat 1 a 1 no se escribe a una cuenta que ya no esta activa (los mensajes se acumularian sin lector).
     */
    private function assertRecipientStillActive(User $actor, Conversation $conversation): void
    {
        if ($conversation->type !== ConversationType::Direct) {
            return;
        }

        $other = $conversation->participants()->whereKeyNot($actor->getKey())->first();

        if ($other === null || ! $other->canAccessApplication()) {
            throw BusinessRuleException::because('chat.errors.recipient_invalid');
        }
    }

    private function acceptedExtension(UploadedFile $file): string
    {
        $extension = $this->inspector->acceptedExtension($file);

        if ($extension === null) {
            throw BusinessRuleException::because('tickets.validation.attachment_type');
        }

        $maxKilobytes = (int) config('tickets.attachments.max_kilobytes');

        if ($file->getSize() > $maxKilobytes * 1024) {
            throw BusinessRuleException::because('tickets.validation.attachment_size', ['max' => $maxKilobytes / 1024]);
        }

        return $extension;
    }

    /**
     * @param  Builder<ChatMessage>  $query
     * @return Builder<ChatMessage>
     */
    private function withRelations(Builder $query): Builder
    {
        return $query->with(['user:id,name,avatar_path', 'attachments']);
    }

    private function ensureTeamChannels(User $user): void
    {
        $teamIds = $user->teamIds();

        if ($teamIds === []) {
            return;
        }

        $existing = Conversation::query()->whereIn('team_id', $teamIds)->pluck('team_id')->all();

        foreach (array_diff($teamIds, $existing) as $teamId) {
            Conversation::query()->createOrFirst(['team_id' => $teamId], ['type' => ConversationType::Team]);
        }
    }

    /**
     * @return SupportCollection<int, int>
     */
    private function unreadByConversation(User $user): SupportCollection
    {
        return ChatMessage::query()
            ->leftJoin('chat_reads', function ($join) use ($user): void {
                $join->on('chat_reads.conversation_id', '=', 'chat_messages.conversation_id')
                    ->where('chat_reads.user_id', '=', $user->getKey());
            })
            ->whereIn('chat_messages.conversation_id', Conversation::query()->visibleTo($user)->select('conversations.id'))
            ->where('chat_messages.user_id', '!=', $user->getKey())
            ->whereRaw('chat_messages.id > coalesce(chat_reads.last_read_message_id, 0)')
            ->groupBy('chat_messages.conversation_id')
            ->selectRaw('chat_messages.conversation_id as conversation_id, count(*) as unread')
            ->toBase()
            ->pluck('unread', 'conversation_id')
            ->map(fn ($count): int => (int) $count);
    }
}
