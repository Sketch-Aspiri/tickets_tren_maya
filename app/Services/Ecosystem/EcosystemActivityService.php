<?php

declare(strict_types=1);

namespace App\Services\Ecosystem;

use App\Enums\AssignmentRole;
use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Enums\UserStatus;
use App\Exceptions\EcosystemActivityException;
use App\Models\Activity;
use App\Models\Team;
use App\Models\User;
use App\Notifications\WorkItemAssigned;
use App\Services\AuditLogger;
use App\Services\FolioGenerator;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Actividades que el Dashboard pide por el puente cuando algo ocurre alla (decision 92): una solicitud de insumos
 * observada, una factura cancelada en el SAT, una estacion sin asistencia... La actividad la crea el usuario de
 * sistema «Dashboard (automatico)» y se asigna al COORDINADOR del equipo destino, que decide quien la atiende.
 *
 * - Equipo destino: lo decide Tickets (config/ecosistema.php > actividades), nunca el Dashboard. Si el evento no
 *   tiene equipo, no se crea nada (`sin_equipo`).
 * - Idempotente por `external_ref` (unico en la base): reenviar el mismo evento devuelve la misma actividad.
 * - El usuario de sistema no tiene rol, esta inactivo y su contrasena es aleatoria y desconocida: no puede iniciar
 *   sesion; solo figura como creador.
 * - Lo que llega del Dashboard es dato no confiable: texto plano, acotado y siempre escapado al mostrarse.
 */
final class EcosystemActivityService
{
    public function __construct(
        private readonly FolioGenerator $folios,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * `deleted`: el `ref` ya tuvo una actividad que se elimino; no se vuelve a crear y no se devuelve su folio.
     *
     * @return array{activity: Activity, created: bool, deleted: bool}
     *
     * @throws EcosystemActivityException
     */
    public function create(string $event, ?string $stationCode, string $ref, string $title, string $description, ?string $url): array
    {
        // El `ref` se guarda con el evento por delante: el mismo `ref` con otro evento es otro hecho.
        $externalRef = $event.':'.$ref;
        $existing = $this->find($externalRef);

        if ($existing !== null) {
            return ['activity' => $existing, 'created' => false, 'deleted' => $existing->trashed()];
        }

        $this->assertUnderLimit($event);
        $team = $this->teamFor($event, $stationCode);
        $responsible = $this->responsibleOf($team);
        // Los demas coordinadores del equipo (decision 96) colaboran y reciben el aviso; el principal es el responsable.
        $collaborators = $team->coordinators()->reject(fn (User $user): bool => $user->is($responsible))->values();
        $system = $this->systemUser();
        $rules = (array) config('ecosistema.actividades.eventos.'.$event);

        try {
            $activity = DB::transaction(function () use ($event, $externalRef, $title, $description, $url, $team, $responsible, $collaborators, $system, $rules): Activity {
                $activity = new Activity([
                    'title' => $title,
                    'description' => Str::limit($description.($url !== null ? "\n\n".__('activities.ecosystem.origin', ['url' => $url]) : ''), 5000, ''),
                    'priority' => Priority::tryFrom((string) ($rules['prioridad'] ?? '')) ?? Priority::Medium,
                    'due_date' => CarbonImmutable::parse(LocalTime::today())->addDays(max(0, (int) ($rules['dias'] ?? 3)))->toDateString(),
                ]);
                $activity->forceFill([
                    'folio' => $this->folios->next((string) config('tickets.folio_prefixes.activity')),
                    'status' => TicketStatus::Pending,
                    'team_id' => $team->getKey(),
                    'created_by' => $system->getKey(),
                    'recurrence_rule' => null,
                    'completed_at' => null,
                    'external_ref' => $externalRef,
                ])->save();

                $activity->statusHistories()->create([
                    'from_status' => null,
                    'to_status' => TicketStatus::Pending,
                    'user_id' => $system->getKey(),
                    'comment' => null,
                ]);

                $activity->assignments()->create([
                    'user_id' => $responsible->getKey(),
                    'role' => AssignmentRole::Responsable,
                    'assigned_by' => $system->getKey(),
                ]);

                foreach ($collaborators as $collaborator) {
                    $activity->assignments()->create([
                        'user_id' => $collaborator->getKey(),
                        'role' => AssignmentRole::Colaborador,
                        'assigned_by' => $system->getKey(),
                    ]);
                }

                $this->audit->record('ecosystem', 'activity_created', $activity, null, extra: [
                    'event' => $event,
                    'ref' => $externalRef,
                    'folio' => $activity->folio,
                    'team_id' => $team->getKey(),
                    'responsible_id' => $responsible->getKey(),
                    'collaborator_ids' => $collaborators->pluck('id')->all(),
                ]);

                return $activity;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Otra peticion con el mismo `ref` llego primero: es la misma actividad.
            $existing = $this->find($externalRef);

            if ($existing === null) {
                throw $e;
            }

            return ['activity' => $existing, 'created' => false, 'deleted' => $existing->trashed()];
        }

        RateLimiter::hit($this->limitKey($event), 3600);

        $responsible->notify(new WorkItemAssigned('activity', (int) $activity->getKey(), (string) $activity->folio, (string) $activity->title, AssignmentRole::Responsable->value, $system->name));

        foreach ($collaborators as $collaborator) {
            $collaborator->notify(new WorkItemAssigned('activity', (int) $activity->getKey(), (string) $activity->folio, (string) $activity->title, AssignmentRole::Colaborador->value, $system->name));
        }

        return ['activity' => $activity, 'created' => true, 'deleted' => false];
    }

    private function limitKey(string $event): string
    {
        return 'ecosystem-activities:'.$event;
    }

    private function assertUnderLimit(string $event): void
    {
        if (RateLimiter::tooManyAttempts($this->limitKey($event), (int) config('ecosistema.actividades.limite_por_hora', 60))) {
            throw EcosystemActivityException::tooMany();
        }
    }

    private function find(string $ref): ?Activity
    {
        return Activity::withTrashed()->where('external_ref', $ref)->first();
    }

    /**
     * Equipo que recibe el evento: para eventos «de estacion» el de esa estacion (si esta configurado) y, si no, el
     * equipo general del evento; para eventos «de zona», el general. Se indica por id o por nombre EXACTO (si el
     * nombre se repite en varias coordinaciones no se adivina: `sin_equipo`).
     */
    private function teamFor(string $event, ?string $stationCode): Team
    {
        $rules = (array) config('ecosistema.actividades.eventos.'.$event);
        $target = null;

        if (($rules['alcance'] ?? 'zona') === 'estacion' && $stationCode !== null) {
            $target = ((array) config('ecosistema.actividades.estaciones'))[$stationCode] ?? null;
        }

        $target ??= $rules['equipo'] ?? null;

        if ($target === null || $target === '') {
            throw EcosystemActivityException::noTeam();
        }

        if (is_int($target) || (is_string($target) && ctype_digit($target))) {
            $team = Team::query()->find((int) $target);
        } else {
            $matches = Team::query()->where('name', (string) $target)->limit(2)->get();
            $team = $matches->count() === 1 ? $matches->first() : null;
        }

        return $team ?? throw EcosystemActivityException::noTeam();
    }

    private function responsibleOf(Team $team): User
    {
        $coordinator = $team->coordinator_id === null ? null : User::query()->find($team->coordinator_id);

        if ($coordinator === null || ! $coordinator->canAccessApplication() || ! $coordinator->belongsToTeam($team->getKey())) {
            throw EcosystemActivityException::noResponsible();
        }

        return $coordinator;
    }

    /**
     * Creador de las actividades del Dashboard. Se crea la primera vez que hace falta.
     */
    public function systemUser(): User
    {
        $email = (string) config('ecosistema.actividades.usuario_sistema.correo');

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            // Debe ser LA cuenta de sistema: inactiva, sin rol ni equipos. Si no (alguien la registro antes, o un
            // administrador la modifico) no se usa: seria una cuenta con nombre y contrasena que controla otra persona.
            if ($existing->status !== UserStatus::Inactive || $existing->roleEnum() !== null || $existing->teams()->exists()) {
                Log::alert('La cuenta de sistema del ecosistema no es valida; no se crean actividades del Dashboard.', ['user_id' => $existing->getKey()]);

                throw EcosystemActivityException::invalidSystemUser();
            }

            return $existing;
        }

        try {
            $user = new User;
            // Contrasena aleatoria que nadie conoce (el cast `hashed` la guarda cifrada); inactivo y sin rol.
            $user->forceFill([
                'name' => (string) config('ecosistema.actividades.usuario_sistema.nombre'),
                'email' => $email,
                'password' => Str::random(64),
                'status' => UserStatus::Inactive,
            ])->save();

            return $user;
        } catch (UniqueConstraintViolationException) {
            return User::query()->where('email', $email)->firstOrFail();
        }
    }
}
