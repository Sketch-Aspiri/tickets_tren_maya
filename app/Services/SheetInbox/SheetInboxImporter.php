<?php

declare(strict_types=1);

namespace App\Services\SheetInbox;

use App\Enums\IncomingEmailStatus;
use App\Models\IncomingEmail;
use App\Models\SpreadsheetSource;
use App\Models\User;
use App\Notifications\NewIncomingEmailToReview;
use App\Services\AuditLogger;
use App\Support\SpanishDateTime;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Notification;

/**
 * Cada fila nueva de la hoja entra a la bandeja "Correos entrantes" de su coordinación, en espera de revisión
 * humana (se descarta o se convierte en Actividad, igual que un correo). Nada se crea automáticamente. Una fila se
 * reconoce por la columna clave (o, sin ella, por el contenido de toda la fila): volver a leerla no la duplica.
 */
final class SheetInboxImporter
{
    /** Remitente de relleno (dominio reservado .invalid) cuando la hoja no trae un correo válido. */
    public const PLACEHOLDER_EMAIL = 'excel@bandeja.invalid';

    public function __construct(
        private readonly MicrosoftPersonalAuth $auth,
        private readonly SheetWorkbookReader $reader,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{created: int, skipped: int}
     */
    public function import(SpreadsheetSource $source): array
    {
        try {
            $rows = $this->reader->rows($source, $this->auth->accessToken($source));
            $result = $this->store($source, $rows, max(1, (int) config('tickets.sheet_inbox.max_rows_per_run')));
        } catch (SheetInboxException $exception) {
            $source->forceFill(['last_synced_at' => now(), 'last_error' => mb_substr($exception->getMessage(), 0, 500)])->save();
            $this->audit->record('sheet_sources', 'sync_failed', $source->coordination, null, extra: ['reason' => $exception->getMessage()]);

            throw $exception;
        }

        return $this->finish($source, $result);
    }

    /**
     * Importa un libro .xlsx subido a mano (sin cuenta de Microsoft): mismas columnas, mismo dedupe y misma revisión
     * humana que la lectura automática. El contenido es dato no confiable: solo se leen valores, nunca fórmulas.
     *
     * @return array{created: int, skipped: int}
     */
    public function importUploaded(SpreadsheetSource $source, string $bytes, User $actor): array
    {
        try {
            $result = $this->store($source, $this->reader->parse($bytes, $source), max(1, (int) config('tickets.sheet_inbox.max_rows_per_upload')));
        } catch (SheetInboxException $exception) {
            $source->forceFill(['last_error' => mb_substr($exception->getMessage(), 0, 500)])->save();
            $this->audit->record('sheet_sources', 'upload_failed', $source->coordination, $actor, extra: ['reason' => $exception->getMessage()]);

            throw $exception;
        }

        $this->audit->record('sheet_sources', 'uploaded', $source->coordination, $actor, extra: $result + ['bytes' => strlen($bytes)]);

        return $this->finish($source, $result);
    }

    /**
     * @param  array{created: int, skipped: int}  $result
     * @return array{created: int, skipped: int}
     */
    private function finish(SpreadsheetSource $source, array $result): array
    {
        $source->forceFill(['last_synced_at' => now(), 'last_error' => null])->save();

        if ($result['created'] > 0) {
            Notification::send($source->coordination->approvers(), new NewIncomingEmailToReview($result['created']));
        }

        return $result;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array{created: int, skipped: int}
     */
    private function store(SpreadsheetSource $source, array $rows, int $limit): array
    {
        $created = 0;
        $skipped = 0;
        $perMinute = [];

        foreach ($rows as $row) {
            if ($created >= $limit) {
                break;
            }

            $subject = $this->value($row, $source->subject_column);
            $dateText = $this->value($row, $source->received_at_column);

            // Sin asunto ni fecha no es una solicitud (restos de listas laterales o filas a medio llenar): se omite.
            if ($subject === '' && $dateText === '') {
                $skipped++;

                continue;
            }

            $messageId = 'sheet:'.$source->getKey().':'.hash('sha256', $this->key($source, $row));

            if (IncomingEmail::query()->where('coordination_id', $source->coordination_id)->where('message_id', $messageId)->exists()) {
                $skipped++;

                continue;
            }

            $receivedAt = $this->receivedAt($dateText, $perMinute);

            $email = new IncomingEmail([
                'coordination_id' => $source->coordination_id,
                'message_id' => $messageId,
                'from_email' => $this->email($source, $row),
                'from_name' => mb_substr($this->value($row, $source->requester_name_column) ?: (string) $source->name, 0, 255),
                'subject' => mb_substr($subject ?: __('sheet_sources.untitled_row'), 0, 500),
                'body' => mb_substr($this->body($source, $row), 0, 10000),
                'received_at' => $receivedAt,
            ]);
            $email->forceFill(['status' => IncomingEmailStatus::PendingReview])->save();
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Fecha de recepción de la hoja (o la hora actual si no hay columna de fecha o no se entiende). Varias filas del
     * mismo minuto conservan el orden de la hoja sumando segundos, para que el listado no las mezcle.
     *
     * @param  array<string, int>  $perMinute
     */
    private function receivedAt(string $dateText, array &$perMinute): CarbonInterface
    {
        $date = SpanishDateTime::parse($dateText);

        if ($date === null) {
            return now();
        }

        $minute = $date->format('YmdHi');
        $perMinute[$minute] = ($perMinute[$minute] ?? -1) + 1;

        return $date->addSeconds(min(59, $perMinute[$minute]));
    }

    /**
     * @param  array<string, string>  $row
     */
    private function key(SpreadsheetSource $source, array $row): string
    {
        $key = $this->value($row, $source->key_column);

        return $key !== '' ? 'key:'.$key : 'row:'.json_encode($row, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function body(SpreadsheetSource $source, array $row): string
    {
        $columns = array_values(array_filter((array) $source->body_columns));

        // Sin columnas elegidas, todas menos el asunto, como "Encabezado: valor".
        if ($columns === []) {
            $columns = array_values(array_diff(array_keys($row), [(string) $source->subject_column]));
        }

        $lines = [];

        foreach ($columns as $column) {
            $value = $this->value($row, $column);

            if ($value !== '') {
                $lines[] = $column.': '.$value;
            }
        }

        return $lines === [] ? __('sheet_sources.empty_body') : implode("\n", $lines);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function email(SpreadsheetSource $source, array $row): string
    {
        $email = $this->value($row, $source->requester_email_column);

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($email) <= 255 ? mb_strtolower($email) : self::PLACEHOLDER_EMAIL;
    }

    /**
     * Valor de una columna por su encabezado (sin distinguir mayúsculas ni espacios de los extremos).
     *
     * @param  array<string, string>  $row
     */
    private function value(array $row, ?string $column): string
    {
        if ($column === null || trim($column) === '') {
            return '';
        }

        $wanted = mb_strtolower(trim($column));

        foreach ($row as $header => $value) {
            if (mb_strtolower($header) === $wanted) {
                return $value;
            }
        }

        return '';
    }
}
