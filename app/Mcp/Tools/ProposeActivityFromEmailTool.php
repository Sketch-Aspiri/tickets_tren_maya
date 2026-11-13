<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ActsAsMcpClient;
use App\Services\Mcp\EmailActivityService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Description('Propone crear una actividad a partir de un correo: tu das responsable, equipo, fecha limite, confianza y razonamiento; el titulo y la descripcion salen del correo. Segun la configuracion queda como PROPUESTA para que una persona la confirme, o se crea sola si es de alta confianza y el remitente esta autorizado. Si el responsable o la fecha de la primera linea son ambiguos, NO propongas: deja el correo para revision.')]
#[Name('proponer_actividad_desde_correo')]
class ProposeActivityFromEmailTool extends Tool
{
    use ActsAsMcpClient;

    public function __construct(private readonly EmailActivityService $service) {}

    public function shouldRegister(): bool
    {
        return $this->clientCanWrite();
    }

    public function handle(Request $request): Response
    {
        return $this->run(function ($client) use ($request): Response {
            $data = $request->validate([
                'correo_id' => ['required', 'integer', 'min:1'],
                'responsable_id' => ['required', 'integer', 'min:1'],
                'equipo_id' => ['required', 'integer', 'min:1'],
                'fecha_limite' => ['required', 'string', 'size:10'],
                'confianza' => ['required', 'integer', 'min:0', 'max:100'],
                'razonamiento' => ['nullable', 'string', 'max:2000'],
            ]);

            $result = $this->service->propose(
                $client,
                (int) $data['correo_id'],
                (int) $data['responsable_id'],
                (int) $data['equipo_id'],
                $data['fecha_limite'],
                (int) $data['confianza'],
                $data['razonamiento'] ?? null,
            );

            return Response::json([
                'resultado' => $result['created'] ? 'actividad_creada' : 'propuesta_registrada',
                'folio' => $result['activity']?->folio,
                'propuesta_id' => $result['proposal']->getKey(),
                'mensaje' => $result['created'] ? __('mcp.result.created') : __('mcp.result.proposed'),
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'correo_id' => $schema->integer()->min(1)->required()->description('Id del correo.'),
            'responsable_id' => $schema->integer()->min(1)->required()->description('Id de la persona responsable (de buscar_personas).'),
            'equipo_id' => $schema->integer()->min(1)->required()->description('Id del equipo (de listar_equipos), de la coordinacion del correo.'),
            'fecha_limite' => $schema->string()->required()->description('Fecha limite AAAA-MM-DD, no pasada.'),
            'confianza' => $schema->integer()->min(0)->max(100)->required()->description('Tu confianza (0-100) en responsable y fecha. Menos de 90 si hubo cualquier duda.'),
            'razonamiento' => $schema->string()->description('Breve: que leiste en la primera linea y como llegaste al responsable y la fecha.'),
        ];
    }
}
