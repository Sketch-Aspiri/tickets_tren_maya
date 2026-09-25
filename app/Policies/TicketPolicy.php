<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionName;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\Concerns\ChecksPermissions;
use Closure;
use Illuminate\Auth\Access\Response;

/**
 * Autorización de tickets = PERMISO de Spatie (qué acciones tiene el rol) + ALCANCE (qué tickets ve
 * el usuario, `Ticket::scopeVisibleTo`). Un ticket fuera del alcance responde 404 (no revela que
 * existe); uno visible pero sin permiso para la acción responde 403.
 *
 * La MÁQUINA de estados (qué transiciones existen) vive en TicketStatus y se aplica en TicketService;
 * aquí solo se decide QUIÉN puede recorrer cada transición.
 */
class TicketPolicy
{
    use ChecksPermissions;

    public function viewAny(User $user): bool
    {
        return $this->hasPermission($user, PermissionName::TicketsView);
    }

    /**
     * Exportar el listado filtrado a Excel (jefe y coordinador). Las FILAS las acota `Ticket::visibleTo`, igual
     * que el listado; el permiso solo habilita la accion.
     */
    public function export(User $user): bool
    {
        return $this->hasPermission($user, PermissionName::ExportsCreate)
            && $this->hasPermission($user, PermissionName::TicketsView);
    }

    public function view(User $user, Ticket $ticket): Response|bool
    {
        return $this->scoped($user, $ticket, fn (): bool => true);
    }

    public function create(User $user): bool
    {
        return $this->hasPermission($user, PermissionName::TicketsCreate);
    }

    /**
     * Quien gestiona (jefe/coordinador del alcance) edita todo lo no final; el creador solo mientras
     * el ticket siga Pendiente.
     */
    public function update(User $user, Ticket $ticket): Response|bool
    {
        return $this->scoped($user, $ticket, function () use ($user, $ticket): bool {
            if ($ticket->status->isFinal()) {
                return false;
            }

            return $this->hasPermission($user, PermissionName::TicketsManage)
                || ($this->hasPermission($user, PermissionName::TicketsCreate)
                    && (int) $ticket->created_by === (int) $user->getKey()
                    && $ticket->status === TicketStatus::Pending);
        });
    }

    public function delete(User $user, Ticket $ticket): Response|bool
    {
        return $this->scoped($user, $ticket, fn (): bool => $this->hasPermission($user, PermissionName::TicketsManage));
    }

    /**
     * Cambiar el ticket al estado `$to`. Quién puede recorrer cada arista:
     * - cancelar y reabrir (volver a Pendiente): gestión (jefe / coordinador de su equipo);
     * - aprobar (-> Completado) y rechazar (En revisión -> En proceso): revisión (jefe / coordinador);
     * - avanzar hasta En revisión: quien gestiona, o el empleado solo en lo suyo (creado/asignado).
     */
    public function transition(User $user, Ticket $ticket, TicketStatus $to): Response|bool
    {
        return $this->scoped($user, $ticket, function () use ($user, $ticket, $to): bool {
            $from = $ticket->status;

            if (in_array($to, [TicketStatus::Cancelled, TicketStatus::Pending], true) || $from->isFinal()) {
                return $this->hasPermission($user, PermissionName::TicketsManage);
            }

            if ($to === TicketStatus::Completed || ($from === TicketStatus::InReview && $to === TicketStatus::InProgress)) {
                return $this->hasPermission($user, PermissionName::TicketsReview);
            }

            return $this->hasPermission($user, PermissionName::TicketsWork)
                && ($this->hasPermission($user, PermissionName::TicketsManage) || $ticket->isInvolving($user));
        });
    }

    /**
     * Asignar, reasignar, delegar y devolver a la bolsa (el alcance ya limita al coordinador a su equipo).
     */
    public function assign(User $user, Ticket $ticket): Response|bool
    {
        return $this->scoped($user, $ticket, fn (): bool => $this->hasPermission($user, PermissionName::TicketsAssign));
    }

    /**
     * "Tomar" un ticket de la bolsa: solo de un equipo AL QUE PERTENECE (un administrador o jefe sin ese equipo
     * no toma). Que siga sin asignar lo verifica AssignmentService bajo lock.
     */
    public function take(User $user, Ticket $ticket): Response|bool
    {
        return $this->scoped($user, $ticket, fn (): bool => $this->hasPermission($user, PermissionName::TicketsWork)
            && $user->belongsToTeam($ticket->team_id));
    }

    public function comment(User $user, Ticket $ticket): Response|bool
    {
        return $this->scoped($user, $ticket, fn (): bool => $this->hasPermission($user, PermissionName::TicketsWork));
    }

    public function attach(User $user, Ticket $ticket): Response|bool
    {
        return $this->comment($user, $ticket);
    }

    /**
     * @param  Closure(): bool  $rule
     */
    private function scoped(User $user, Ticket $ticket, Closure $rule): Response|bool
    {
        if (! $this->canSee($user, $ticket)) {
            return Response::denyAsNotFound();
        }

        return $rule();
    }

    /**
     * Misma consulta de alcance que los listados: una sola definición evita IDOR por divergencia.
     */
    private function canSee(User $user, Ticket $ticket): bool
    {
        return $this->hasPermission($user, PermissionName::TicketsView)
            && Ticket::query()->visibleTo($user)->whereKey($ticket->getKey())->exists();
    }
}
