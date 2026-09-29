<?php

declare(strict_types=1);

namespace App\Services;

use App\Exports\ActivitiesExport;
use App\Exports\CloudInfoSheet;
use App\Exports\CloudWorkbookExport;
use App\Exports\TicketsExport;
use App\Models\Activity;
use App\Models\Ticket;
use App\Services\Cloud\CloudFileUploader;
use App\Support\LocalTime;
use Illuminate\Database\Eloquent\Collection;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Genera el Excel global (todos los tickets y actividades, alcance equivalente al del jefe de zona, sin depender de
 * un usuario) y lo sube a la nube reemplazando el anterior. Reutiliza las hojas de la exportacion manual (mismas
 * columnas, sin descripciones y con proteccion contra formulas).
 */
final class CloudSheetSyncService
{
    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(
        private readonly CloudFileUploader $uploader,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{tickets: int, activities: int, truncated: bool}
     */
    public function sync(): array
    {
        $max = max(1, (int) config('tickets.cloud_sync.max_rows'));
        $tickets = $this->tickets($max + 1);
        $activities = $this->activities($max + 1);
        $truncated = $tickets->count() > $max || $activities->count() > $max;
        $tickets = $tickets->take($max);
        $activities = $activities->take($max);

        $contents = Excel::raw(new CloudWorkbookExport(
            new TicketsExport($tickets),
            new ActivitiesExport($activities),
            new CloudInfoSheet($this->infoRows($tickets->count(), $activities->count(), $truncated)),
        ), ExcelWriter::XLSX);

        $this->uploader->upload($contents, self::XLSX_MIME);

        $result = ['tickets' => $tickets->count(), 'activities' => $activities->count(), 'truncated' => $truncated];
        $this->audit->record('exports', 'cloud_synced', null, null, [], [], $result);

        return $result;
    }

    /**
     * @return Collection<int, Ticket>
     */
    private function tickets(int $limit): Collection
    {
        return Ticket::query()->with(TicketListingService::EAGER_LOADS)->orderBy('folio')->limit($limit)->get();
    }

    /**
     * @return Collection<int, Activity>
     */
    private function activities(int $limit): Collection
    {
        return Activity::query()
            ->withoutTemplates()
            ->withProgress()
            ->with(ActivityListingService::EAGER_LOADS)
            ->orderBy('folio')
            ->limit($limit)
            ->get();
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function infoRows(int $tickets, int $activities, bool $truncated): array
    {
        $rows = [
            [__('tickets.cloud_sync.updated_at'), LocalTime::now()->format('d/m/Y H:i').' ('.config('app.display_timezone').')'],
            [__('tickets.cloud_sync.ticket_count'), (string) $tickets],
            [__('tickets.cloud_sync.activity_count'), (string) $activities],
            [__('tickets.cloud_sync.read_only'), ''],
        ];

        if ($truncated) {
            $rows[] = [__('tickets.cloud_sync.truncated', ['max' => (int) config('tickets.cloud_sync.max_rows')]), ''];
        }

        return $rows;
    }
}
