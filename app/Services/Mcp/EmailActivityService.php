<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Enums\EmailProposalStatus;
use App\Enums\IncomingEmailStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Activity;
use App\Models\EmailActivityProposal;
use App\Models\IncomingEmail;
use App\Models\McpClient;
use App\Models\Team;
use App\Models\User;
use App\Services\AssignmentService;
use App\Services\AuditLogger;
use App\Services\IncomingEmailReviewService;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Correo entrante -> actividad por la IA (decision 94). La IA (cliente MCP) solo aporta responsable, equipo, fecha
 * limite, confianza y su razonamiento; este servicio NO confia en nada de eso:
 * - el correo debe estar en el alcance de la cuenta con la que actua el cliente y seguir pendiente;
 * - el equipo debe ser de la coordinacion del correo y del alcance de trabajo de la cuenta;
 * - el responsable debe ser asignable a ese equipo (misma regla que `AssignmentService`);
 * - la fecha debe ser valida, no pasada y dentro del tope;
 * - el titulo y la descripcion de la actividad SIEMPRE salen del correo, nunca de lo que diga la IA.
 *
 * En modo `propuesta` (por defecto) solo se guarda la propuesta y una persona la confirma en la bandeja. En modo
 * `automatico_confiable` la actividad se crea sola solo si el cliente puede escribir, la confianza alcanza el
 * umbral, el remitente esta autorizado y no se rebasa el tope por hora; en cualquier otro caso queda propuesta.
 */
final class EmailActivityService
{
    public const MODE_PROPOSAL = 'propuesta';

    public const MODE_AUTOMATIC = 'automatico_confiable';

    public function __construct(
        private readonly AssignmentService $assignments,
        private readonly IncomingEmailReviewService $review,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{proposal: EmailActivityProposal, activity: Activity|null, created: bool}
     */
    public function propose(McpClient $client, int $emailId, int $responsibleId, int $teamId, string $dueDate, int $confidence, ?string $rationale): array
    {
        $actor = $this->actor($client, write: true);
        $email = $this->pendingEmail($actor, $emailId, 'convert');

        $team = $this->workableTeam($actor, $email, $teamId);
        $this->assertAssignable($actor, $team, $responsibleId);
        $due = $this->validDate($dueDate);

        if ($confidence < 0 || $confidence > 100) {
            throw BusinessRuleException::because('mcp.errors.confidence_range');
        }

        $proposal = DB::transaction(function () use ($client, $email, $responsibleId, $team, $due, $confidence, $rationale): EmailActivityProposal {
            // Bloquea el correo para que dos propuestas simultaneas no dejen dos "pendientes".
            $locked = IncomingEmail::query()->lockForUpdate()->findOrFail($email->getKey());

            // Una persona pudo convertirlo o descartarlo entre la comprobacion y el bloqueo: sin esto quedaria
            // una propuesta pendiente huerfana sobre un correo ya cerrado.
            if ($locked->status !== IncomingEmailStatus::PendingReview) {
                throw BusinessRuleException::because('emails.errors.already_reviewed');
            }

            EmailActivityProposal::query()
                ->where('incoming_email_id', $email->getKey())
                ->where('status', EmailProposalStatus::Pending->value)
                ->get()
                ->each(fn (EmailActivityProposal $old) => $old->forceFill(['status' => EmailProposalStatus::Superseded])->save());

            $proposal = new EmailActivityProposal;
            $proposal->forceFill([
                'incoming_email_id' => $email->getKey(),
                'mcp_client_id' => $client->getKey(),
                'responsible_id' => $responsibleId,
                'team_id' => $team->getKey(),
                'due_date' => $due->toDateString(),
                'confidence' => $confidence,
                'rationale' => $this->cleanText($rationale, 500),
                'status' => EmailProposalStatus::Pending,
            ])->save();

            return $proposal;
        });

        $this->audit->record('mcp', 'email_proposal_created', $email, $actor, [], [], [
            'client' => $client->name,
            'proposal_id' => $proposal->getKey(),
            'confidence' => $confidence,
            'team_id' => $team->getKey(),
            'responsible_id' => $responsibleId,
        ]);

        $activity = $this->shouldAutoCreate($client, $email, $confidence)
            ? $this->autoCreate($client, $actor, $email, $proposal)
            : null;

        return ['proposal' => $proposal->refresh(), 'activity' => $activity, 'created' => $activity !== null];
    }

    public function discard(McpClient $client, int $emailId, string $reason): IncomingEmail
    {
        $actor = $this->actor($client, write: true);
        $email = $this->pendingEmail($actor, $emailId, 'discard');
        $reason = $this->cleanText($reason, 500) ?? '';

        if (mb_strlen($reason) < 3) {
            throw BusinessRuleException::because('mcp.errors.reason_required');
        }

        // Tope por hora: un correo malicioso no debe poder hacer que la IA descarte la bandeja entera.
        $allowed = RateLimiter::attempt(
            'mcp-email-discard:'.$client->getKey(),
            (int) config('tickets_mcp.correo.descartes_por_hora'),
            static fn (): bool => true,
            3600,
        );

        if ($allowed !== true) {
            throw BusinessRuleException::because('mcp.errors.discard_limit');
        }

        $discarded = $this->review->discard($actor, $email, $reason);
        $this->audit->record('mcp', 'email_discarded', $discarded, $actor, [], [], ['client' => $client->name, 'reason' => $reason]);

        return $discarded;
    }

    /**
     * Cuenta con la que actua el cliente; para escribir, el cliente debe tener permiso de escritura.
     */
    public function actor(McpClient $client, bool $write = false): User
    {
        if ($write && ! $client->can_write) {
            throw BusinessRuleException::because('mcp.errors.read_only');
        }

        $user = $client->user;

        if ($user === null || ! $user->canAccessApplication()) {
            throw BusinessRuleException::because('mcp.errors.account_inactive');
        }

        return $user;
    }

    /**
     * Las herramientas de listado tambien respetan el permiso de la cuenta (no solo su alcance): si a un rol se le
     * quita `emails.view` o `activities.manage`, su cliente MCP deja de poder listar.
     *
     * @param  class-string  $model
     */
    public function assertCan(User $actor, string $ability, string $model): void
    {
        if (! Gate::forUser($actor)->allows($ability, $model)) {
            throw BusinessRuleException::because('mcp.errors.no_permission');
        }
    }

    /**
     * Correo (en cualquier estado) dentro del alcance de la cuenta. Fuera de alcance responde como si no existiera.
     */
    public function viewableEmail(User $actor, int $emailId): IncomingEmail
    {
        $email = IncomingEmail::query()->visibleTo($actor)->with(['coordination:id,name', 'attachments'])->find($emailId);

        if ($email === null || ! Gate::forUser($actor)->allows('view', $email)) {
            throw BusinessRuleException::because('mcp.errors.email_not_found');
        }

        return $email;
    }

    /**
     * Correo dentro del alcance de la cuenta y todavia pendiente. Fuera de alcance se responde igual que si no
     * existiera (no se revela su existencia).
     */
    public function pendingEmail(User $actor, int $emailId, string $ability): IncomingEmail
    {
        $email = IncomingEmail::query()->visibleTo($actor)->find($emailId);

        if ($email === null || ! Gate::forUser($actor)->allows('view', $email)) {
            throw BusinessRuleException::because('mcp.errors.email_not_found');
        }

        if (! Gate::forUser($actor)->allows($ability, $email)) {
            throw BusinessRuleException::because($email->status === IncomingEmailStatus::PendingReview ? 'mcp.errors.no_permission' : 'emails.errors.already_reviewed');
        }

        return $email;
    }

    private function workableTeam(User $actor, IncomingEmail $email, int $teamId): Team
    {
        $team = Team::query()->find($teamId);

        if ($team === null
            || (int) $team->coordination_id !== (int) $email->coordination_id
            || (! $actor->seesAllTeams() && ! in_array((int) $team->getKey(), $actor->workingTeamIds(), true))) {
            throw BusinessRuleException::because('mcp.errors.team_invalid');
        }

        return $team;
    }

    private function assertAssignable(User $actor, Team $team, int $responsibleId): void
    {
        if (! $this->assignments->assignableUsers($actor, null, (int) $team->getKey())->contains('id', $responsibleId)) {
            throw BusinessRuleException::because('mcp.errors.responsible_invalid');
        }
    }

    private function validDate(string $value): CarbonImmutable
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $value, (string) config('app.display_timezone'))
            : false;

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw BusinessRuleException::because('mcp.errors.date_format');
        }

        $today = CarbonImmutable::createFromFormat('!Y-m-d', LocalTime::today(), (string) config('app.display_timezone'));

        if ($date->lt($today)) {
            throw BusinessRuleException::because('mcp.errors.date_past');
        }

        if ($date->gt($today->addDays((int) config('tickets_mcp.correo.dias_max')))) {
            throw BusinessRuleException::because('mcp.errors.date_far');
        }

        return $date;
    }

    private function shouldAutoCreate(McpClient $client, IncomingEmail $email, int $confidence): bool
    {
        $config = (array) config('tickets_mcp.correo');

        // El orden va de lo barato a lo caro; el tope por hora se consume AL FINAL y de forma atomica
        // (`attempt` incrementa antes de decidir), asi peticiones simultaneas no lo rebasan.
        return ($config['modo'] ?? self::MODE_PROPOSAL) === self::MODE_AUTOMATIC
            && $client->can_write
            && $confidence >= (int) $config['umbral_confianza']
            && in_array(mb_strtolower(trim((string) $email->from_email)), (array) $config['remitentes_autorizados'], true)
            // El From no esta autenticado (no se revisa SPF/DKIM/DMARC): un correo con adjuntos jamas se crea solo.
            && (! (bool) $config['auto_sin_adjuntos'] || ! $email->attachments()->exists())
            && RateLimiter::attempt($this->limiterKey($client), (int) $config['creaciones_por_hora'], static fn (): bool => true, 3600) === true;
    }

    private function autoCreate(McpClient $client, User $actor, IncomingEmail $email, EmailActivityProposal $proposal): ?Activity
    {
        try {
            $activity = $this->review->convert($actor, $email, [
                'title' => $this->activityTitle($email),
                'description' => $this->activityDescription($email),
                'priority' => 'medium',
                'team_id' => $proposal->team_id,
                'responsible_id' => $proposal->responsible_id,
                'due_date' => $proposal->due_date->toDateString(),
                'collaborator_ids' => [],
            ]);
        } catch (BusinessRuleException) {
            // Una regla de negocio impide crearla (p. ej. demasiados adjuntos): queda como propuesta.
            return null;
        } catch (Throwable $e) {
            // Un fallo real tampoco debe perderse: se reporta y la propuesta queda para revision humana.
            report($e);

            return null;
        }

        // `convert` la dejo como aceptada por una persona; aqui la decidio la IA, asi que no hay quien decidiera.
        $proposal->forceFill([
            'status' => EmailProposalStatus::AutoCreated,
            'unchanged' => true,
            'decided_by' => null,
            'decided_at' => null,
        ])->save();
        $this->audit->record('mcp', 'activity_auto_created', $email, $actor, [], [], [
            'client' => $client->name,
            'activity_folio' => $activity->folio,
            'proposal_id' => $proposal->getKey(),
            'confidence' => $proposal->confidence,
        ]);

        return $activity;
    }

    private function limiterKey(McpClient $client): string
    {
        return 'mcp-email-create:'.$client->getKey();
    }

    private function activityTitle(IncomingEmail $email): string
    {
        $title = trim((string) $email->subject);

        return mb_substr($title !== '' ? $title : (string) __('mcp.email_without_subject'), 0, 255);
    }

    private function activityDescription(IncomingEmail $email): string
    {
        $body = trim((string) $email->body);

        return mb_substr($body !== '' ? $body : $this->activityTitle($email), 0, 5000);
    }

    /**
     * Texto libre de la IA: sin caracteres de control ni de formato Unicode y acotado.
     */
    private function cleanText(?string $text, int $max): ?string
    {
        if ($text === null) {
            return null;
        }

        $clean = trim((string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $text));

        return $clean === '' ? null : Str::limit($clean, $max, '');
    }
}
