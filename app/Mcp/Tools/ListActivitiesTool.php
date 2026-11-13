<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\AssignmentRole;
use App\Enums\TicketStatus;
use App\Mcp\Concerns\ActsAsMcpClient;
use App\Models\Activity;
use App\Services\Mcp\EmailActivityService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Lista actividades dentro de tu alcance (sin plantillas de recurrencia), con filtros opcionales por estado, responsable y fecha limite.')]
#[IsReadOnly]
#[Name('listar_actividades')]
class ListActivitiesTool extends Tool
{
    use ActsAsMcpClient;

    public function __construct(private readonly EmailActivityService $service) {}

    public function handle(Request $request): Response
    {
        return $this->run(function ($client) use ($request): Response {
            $data = $request->validate([
                'estado' => ['nullable', Rule::in(array_column(TicketStatus::cases(), 'value'))],
                'responsable_id' => ['nullable', 'integer', 'min:1'],
                'vence_antes_de' => ['nullable', 'date_format:Y-m-d'],
                'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            ]);

            $actor = $this->service->actor($client);
            $this->service->assertCan($actor, 'viewAny', Activity::class);
            $responsible = AssignmentRole::Responsable->value;

            $activities = Activity::query()
                ->visibleTo($actor)
                ->withoutTemplates()
                ->when($data['estado'] ?? null, fn ($query, string $status) => $query->where('status', $status))
                ->when($data['vence_antes_de'] ?? null, fn ($query, string $date) => $query->whereDate('due_date', '<=', $date))
                ->when($data['responsable_id'] ?? null, fn ($query, int $id) => $query->whereHas('assignments', fn ($q) => $q->where('user_id', $id)->where('role', $responsible)))
                ->with(['team:id,name', 'assignments' => fn ($q) => $q->where('role', $responsible), 'assignments.user:id,name'])
                ->orderByRaw('due_date IS NULL')
                ->orderBy('due_date')
                ->orderByDesc('id')
                ->limit((int) ($data['limit'] ?? 25))
                ->get();

            return Response::json(['actividades' => $activities->map(fn (Activity $activity): array => [
                'folio' => $activity->folio,
                'titulo' => $activity->title,
                'estado' => $activity->status->value,
                'prioridad' => $activity->priority->value,
                'fecha_limite' => $activity->due_date?->toDateString(),
                'equipo' => $activity->team?->name,
                'responsable' => $activity->assignments->first()?->user?->name,
            ])->all()]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'estado' => $schema->string()->enum(array_column(TicketStatus::cases(), 'value'))->description('Filtra por estado.'),
            'responsable_id' => $schema->integer()->min(1)->description('Filtra por el responsable (id de persona).'),
            'vence_antes_de' => $schema->string()->description('Solo con fecha limite en esta fecha o antes (AAAA-MM-DD).'),
            'limit' => $schema->integer()->min(1)->max(50)->description('Cuantas devolver (1 a 50, por defecto 25).'),
        ];
    }
}
