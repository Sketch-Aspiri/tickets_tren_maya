<?php

declare(strict_types=1);

namespace App\Services\Ecosystem;

use App\Models\User;

/**
 * "Reportar una falla" desde el Dashboard (decision 89). El Dashboard guarda el contexto (pagina, rol, fecha,
 * navegador) bajo un id aleatorio y manda al navegador a `/tickets/create?prefill=<id>`; aqui se recupera por el
 * puente, una sola vez y solo si es de la misma persona (mismo correo). El contexto nunca viaja en la URL.
 *
 * Lo que llega es dato no confiable: se reduce a texto plano acotado y el formulario lo imprime escapado; la
 * persona lo revisa y lo envia por el flujo normal (StoreTicketRequest), nada se crea solo.
 */
final class SupportPrefillService
{
    public function __construct(private readonly BridgeClient $bridge) {}

    /**
     * @return array{title: string, description: string}|null
     */
    public function fetch(User $user, string $id): ?array
    {
        $data = $this->bridge->get('/interno/contextos/'.$id, ['correo' => mb_strtolower((string) $user->email)]);

        if (! is_array($data) || ! is_string($data['titulo'] ?? null) || ! is_string($data['descripcion'] ?? null)) {
            return null;
        }

        $title = $this->plain($data['titulo'], 255, singleLine: true);
        $description = $this->plain($data['descripcion'], 5000);

        return $title === '' ? null : ['title' => $title, 'description' => $description];
    }

    private function plain(string $text, int $max, bool $singleLine = false): string
    {
        // Sin caracteres de control (salvo saltos de linea y tabuladores en la descripcion).
        $text = (string) preg_replace($singleLine ? '/[\x00-\x1F\x7F]+/u' : '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $text);

        return mb_substr(trim($text), 0, $max);
    }
}
