<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ActsAsMcpClient;
use App\Models\User;
use App\Services\AssignmentService;
use App\Services\Mcp\EmailActivityService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Busca personas a las que SI se puede asignar una actividad en un equipo, por nombre (sin acentos ni mayusculas). Devuelve id y nombre; nunca correos. Si hay mas de un resultado posible, el responsable es ambiguo: no adivines.')]
#[IsReadOnly]
#[Name('buscar_personas')]
class SearchUsersTool extends Tool
{
    use ActsAsMcpClient;

    public function __construct(
        private readonly EmailActivityService $service,
        private readonly AssignmentService $assignments,
    ) {}

    public function handle(Request $request): Response
    {
        return $this->run(function ($client) use ($request): Response {
            $data = $request->validate([
                'nombre' => ['required', 'string', 'min:2', 'max:80'],
                'equipo_id' => ['required', 'integer', 'min:1'],
            ]);

            $actor = $this->service->actor($client);
            $needle = $this->normalize($data['nombre']);

            $matches = $this->assignments->assignableUsers($actor, null, (int) $data['equipo_id'])
                ->filter(fn (User $user): bool => str_contains($this->normalize($user->name), $needle))
                ->take(10);

            return Response::json(['personas' => $matches->map(fn (User $user): array => [
                'id' => $user->getKey(),
                'nombre' => $user->name,
            ])->values()->all()]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'nombre' => $schema->string()->required()->description('Parte del nombre de la persona (2 a 80 caracteres).'),
            'equipo_id' => $schema->integer()->min(1)->required()->description('Equipo de la actividad (de listar_equipos).'),
        ];
    }

    private function normalize(string $text): string
    {
        return mb_strtolower(Str::ascii($text));
    }
}
