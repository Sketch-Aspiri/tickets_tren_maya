<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Support\AuditPayload;
use App\Support\ListingQuery;
use App\Support\LocalTime;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Spatie\Activitylog\Models\Activity as ActivityLogEntry;

/**
 * Consulta de SOLO LECTURA de la bitácora de auditoría (visor del jefe). El tipo de sujeto se elige de una
 * lista blanca cuyos valores son los alias del morph map (nunca texto del usuario); el resto de filtros viajan
 * como bindings. Las filas con un tipo de sujeto/causante fuera del mapa se excluyen (no se pueden hidratar) y
 * la carga de `causer`/`subject` es por lotes (sin N+1).
 */
final class AuditLogService
{
    /**
     * Clave pública del filtro => alias del morph map guardado en `activity_log.subject_type`.
     *
     * @var array<string, string>
     */
    public const SUBJECT_TYPES = [
        'ticket' => 'ticket',
        'activity' => 'activity',
        'user' => 'App\Models\User',
        'team' => 'App\Models\Team',
        'category' => 'category',
    ];

    private const CAUSER_TYPE = 'App\Models\User';

    /**
     * @param  array{causer_id?: ?int, event?: ?string, subject?: ?string, from?: ?string, to?: ?string, q?: ?string}  $filters
     * @return LengthAwarePaginator<int, ActivityLogEntry>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $allowedSubjects = array_values(self::SUBJECT_TYPES);

        $query = ActivityLogEntry::query()
            ->with(['causer', 'subject'])
            ->where(fn (Builder $q) => $q->whereNull('subject_type')->orWhereIn('subject_type', $allowedSubjects))
            ->where(fn (Builder $q) => $q->whereNull('causer_type')->orWhere('causer_type', self::CAUSER_TYPE))
            ->when($filters['causer_id'] ?? null, fn (Builder $q, int $id) => $q->where('causer_type', self::CAUSER_TYPE)->where('causer_id', $id))
            ->when($filters['event'] ?? null, fn (Builder $q, string $event) => $q->where('event', $event))
            ->when($filters['subject'] ?? null, fn (Builder $q, string $key) => $q->where('subject_type', self::SUBJECT_TYPES[$key]))
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('created_at', '>=', LocalTime::storageBoundary($from)))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('created_at', '<', LocalTime::storageBoundary($to, 1)))
            ->when($filters['q'] ?? null, fn (Builder $q, string $term) => ListingQuery::search($q, $term, [
                'activity_log.description', 'activity_log.event', 'activity_log.log_name', 'activity_log.properties', 'activity_log.attribute_changes',
            ]))
            ->orderByDesc('activity_log.id');

        return $query->paginate((int) config('tickets.audit_per_page'))->withQueryString();
    }

    /**
     * Eventos distintos registrados (para el selector de filtro).
     *
     * @return list<string>
     */
    public function events(): array
    {
        return ActivityLogEntry::query()->whereNotNull('event')->distinct()->orderBy('event')->pluck('event')->all();
    }

    /**
     * Usuarios que han causado algún evento (para el selector de filtro).
     *
     * @return Collection<int, User>
     */
    public function causers(): Collection
    {
        $ids = ActivityLogEntry::query()->where('causer_type', self::CAUSER_TYPE)->distinct()->pluck('causer_id');

        return User::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Datos de una fila listos para la vista: todo texto plano, sin llaves sensibles.
     *
     * @return array{id: int, when: ?Carbon, event: string, event_label: string, log_name: string, description: string, causer: ?string, subject_type: ?string, subject: ?string, changes: list<array{field: string, old: string, new: string}>, details: list<array{key: string, value: string}>}
     */
    public function present(ActivityLogEntry $entry): array
    {
        $changes = AuditPayload::redact($entry->attribute_changes?->toArray() ?? []);
        $old = is_array($changes['old'] ?? null) ? $changes['old'] : [];
        $new = is_array($changes['attributes'] ?? null) ? $changes['attributes'] : [];

        return [
            'id' => (int) $entry->getKey(),
            'when' => $entry->created_at,
            'event' => (string) $entry->event,
            'event_label' => Lang::has('audit.events.'.$entry->event) ? __('audit.events.'.$entry->event) : (string) $entry->event,
            'log_name' => (string) $entry->log_name,
            'description' => (string) $entry->description,
            'causer' => $entry->causer instanceof User ? $entry->causer->name : null,
            'subject_type' => $entry->subject_type === null ? null : $this->subjectKey($entry->subject_type),
            'subject' => $this->subjectLabel($entry),
            'changes' => $this->changeRows($old, $new),
            'details' => $this->detailRows(AuditPayload::redact($entry->properties?->toArray() ?? [])),
        ];
    }

    /**
     * @param  array<int|string, mixed>  $old
     * @param  array<int|string, mixed>  $new
     * @return list<array{field: string, old: string, new: string}>
     */
    private function changeRows(array $old, array $new): array
    {
        $rows = [];

        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $field) {
            $rows[] = [
                'field' => (string) $field,
                'old' => AuditPayload::stringify($old[$field] ?? null),
                'new' => AuditPayload::stringify($new[$field] ?? null),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int|string, mixed>  $properties
     * @return list<array{key: string, value: string}>
     */
    private function detailRows(array $properties): array
    {
        $rows = [];

        foreach ($properties as $key => $value) {
            $rows[] = ['key' => (string) $key, 'value' => AuditPayload::stringify($value)];
        }

        return $rows;
    }

    private function subjectKey(string $subjectType): ?string
    {
        $key = array_search($subjectType, self::SUBJECT_TYPES, true);

        return $key === false ? null : $key;
    }

    /**
     * Nombre legible del sujeto (folio y título, nombre...). Si ya no existe: `#id`.
     */
    private function subjectLabel(ActivityLogEntry $entry): ?string
    {
        if ($entry->subject_type === null) {
            return null;
        }

        $subject = $entry->subject;

        return match (true) {
            $subject instanceof Ticket, $subject instanceof Activity => $subject->folio.' · '.$subject->title,
            $subject instanceof User, $subject instanceof Team, $subject instanceof Category => $subject->name,
            $subject instanceof Model => '#'.$subject->getKey(),
            default => '#'.$entry->subject_id,
        };
    }
}
