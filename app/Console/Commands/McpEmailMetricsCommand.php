<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EmailProposalStatus;
use App\Models\EmailActivityProposal;
use Illuminate\Console\Command;

/**
 * Tasa de aciertos de la IA en las propuestas de actividad desde correo (decision 94): cuantas confirmo una
 * persona SIN cambiar nada. Es el dato para decidir cuando pasar de `propuesta` a `automatico_confiable`.
 * Solo lectura.
 */
class McpEmailMetricsCommand extends Command
{
    protected $signature = 'mcp:correo-metricas {--dias=30 : Ventana en dias}';

    protected $description = 'Muestra cuantas propuestas de la IA acepto una persona sin cambios';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('dias'));

        $counts = EmailActivityProposal::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->selectRaw('status, unchanged, count(*) as total')
            ->groupBy('status', 'unchanged')
            ->get();

        $total = static fn (EmailProposalStatus $status, ?bool $unchanged = null): int => (int) $counts
            ->filter(fn ($row): bool => $row->status === $status && ($unchanged === null || (bool) $row->unchanged === $unchanged))
            ->sum('total');

        $accepted = $total(EmailProposalStatus::Accepted);
        $unchanged = $total(EmailProposalStatus::Accepted, true);
        $decided = $accepted + $total(EmailProposalStatus::Rejected);

        $this->table(['Estado', 'Propuestas'], collect(EmailProposalStatus::cases())
            ->map(fn (EmailProposalStatus $status): array => [$status->value, $total($status)])->all());

        $this->line(sprintf('Ultimos %d dias: %d aceptadas, %d sin cambios, %d decididas por personas.', $days, $accepted, $unchanged, $decided));
        $this->line($accepted > 0
            ? sprintf('Aciertos (aceptadas sin cambios / aceptadas): %.0f %%', 100 * $unchanged / $accepted)
            : 'Aun no hay propuestas aceptadas para medir.');

        return self::SUCCESS;
    }
}
