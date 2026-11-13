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
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Descarta un correo de la bandeja con un motivo obligatorio (spam, duplicado, sin tarea). El correo no se borra: queda en el historico de descartados.')]
#[IsDestructive]
#[Name('descartar_correo')]
class DiscardEmailTool extends Tool
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
                'motivo' => ['required', 'string', 'min:3', 'max:500'],
            ]);

            $this->service->discard($client, (int) $data['correo_id'], $data['motivo']);

            return Response::json(['resultado' => 'correo_descartado']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'correo_id' => $schema->integer()->min(1)->required()->description('Id del correo.'),
            'motivo' => $schema->string()->required()->description('Motivo del descarte (3 a 500 caracteres).'),
        ];
    }
}
