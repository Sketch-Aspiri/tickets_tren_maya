<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Activity;
use App\Models\ChatMessage;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * Convierte el texto de los mensajes en HTML seguro: PRIMERO se escapa todo el texto y despues los folios
 * (TM-AAAA-0001 / ACT-AAAA-0001) se vuelven enlaces, solo si quien lee puede ver ese registro
 * (`visibleTo`, una consulta por lote). El texto del usuario nunca se imprime sin escapar.
 */
final class ChatMessageFormatter
{
    /**
     * @param  Collection<int, ChatMessage>  $messages
     * @return array<int, HtmlString> indexado por id de mensaje
     */
    public function render(Collection $messages, User $viewer): array
    {
        $pattern = $this->pattern();
        $folios = [];

        foreach ($messages as $message) {
            preg_match_all($pattern, (string) $message->body, $found);
            $folios = [...$folios, ...$found[0]];
        }

        $links = $this->resolveLinks(array_values(array_unique($folios)), $viewer);
        $rendered = [];

        foreach ($messages as $message) {
            $rendered[(int) $message->getKey()] = new HtmlString($this->linkify(e((string) $message->body), $links, $pattern));
        }

        return $rendered;
    }

    private function pattern(): string
    {
        $prefixes = implode('|', array_map('preg_quote', array_values((array) config('tickets.folio_prefixes'))));

        return '/\b(?:'.$prefixes.')-\d{4}-\d{4,}\b/';
    }

    /**
     * @param  list<string>  $folios
     * @return array<string, string> folio => url
     */
    private function resolveLinks(array $folios, User $viewer): array
    {
        if ($folios === []) {
            return [];
        }

        $links = [];

        foreach (Ticket::query()->visibleTo($viewer)->whereIn('folio', $folios)->get(['id', 'folio']) as $ticket) {
            $links[$ticket->folio] = route('tickets.show', $ticket);
        }

        foreach (Activity::query()->visibleTo($viewer)->whereIn('folio', $folios)->get(['id', 'folio']) as $activity) {
            $links[$activity->folio] = route('activities.show', $activity);
        }

        return $links;
    }

    /**
     * @param  array<string, string>  $links
     */
    private function linkify(string $escaped, array $links, string $pattern): string
    {
        if ($links === []) {
            return $escaped;
        }

        return (string) preg_replace_callback($pattern, function (array $match) use ($links): string {
            $folio = $match[0];

            return isset($links[$folio])
                ? '<a href="'.e($links[$folio]).'" class="font-medium text-brand-teal underline hover:text-brand-green">'.e($folio).'</a>'
                : $folio;
        }, $escaped);
    }
}
