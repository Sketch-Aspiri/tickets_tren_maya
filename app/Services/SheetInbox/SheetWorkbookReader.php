<?php

declare(strict_types=1);

namespace App\Services\SheetInbox;

use App\Models\SpreadsheetSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use Throwable;

/**
 * Descarga el libro compartido con Microsoft Graph (`/shares/{id}`, URL fija: el enlace guardado solo se usa para
 * calcular el id, nunca se pide directamente) y devuelve las filas de la hoja como `encabezado => texto`. Solo
 * lectura de valores (sin fórmulas ni macros), con tope de tamaño. El contenido es dato, nunca instrucción.
 */
final class SheetWorkbookReader
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0/shares/';

    private const TIMEOUT_SECONDS = 60;

    /**
     * @return list<array<string, string>>
     */
    public function rows(SpreadsheetSource $source, string $accessToken): array
    {
        $item = self::GRAPH.self::shareId((string) $source->share_url).'/driveItem';
        $maxBytes = max(1, (int) config('tickets.sheet_inbox.max_file_kb')) * 1024;

        $meta = $this->get(fn () => Http::withToken($accessToken)->acceptJson()->timeout(self::TIMEOUT_SECONDS)->get($item, ['$select' => 'name,size,file']));

        if (! $meta->successful()) {
            throw SheetInboxException::because(in_array($meta->status(), [401, 403, 404], true)
                ? 'sheet_sources.errors.no_access'
                : 'sheet_sources.errors.download_failed', ['status' => $meta->status()]);
        }

        if ((int) $meta->json('size', 0) > $maxBytes) {
            throw SheetInboxException::because('sheet_sources.errors.too_big', ['max' => (int) config('tickets.sheet_inbox.max_file_kb')]);
        }

        $content = $this->get(fn () => Http::withToken($accessToken)->timeout(self::TIMEOUT_SECONDS)->get($item.'/content'));

        if (! $content->successful() || strlen($content->body()) > $maxBytes) {
            throw SheetInboxException::because('sheet_sources.errors.download_failed', ['status' => $content->status()]);
        }

        return $this->parse($content->body(), $source);
    }

    /**
     * Id de Microsoft Graph para un enlace compartido: "u!" + base64url del enlace.
     */
    public static function shareId(string $shareUrl): string
    {
        return 'u!'.rtrim(strtr(base64_encode($shareUrl), '+/', '-_'), '=');
    }

    /**
     * @return list<array<string, string>>
     */
    public function parse(string $bytes, SpreadsheetSource $source): array
    {
        $path = tempnam(sys_get_temp_dir(), 'sheet');

        try {
            file_put_contents($path, $bytes);
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $book = $reader->load($path);
        } catch (ReaderException|Throwable) {
            throw SheetInboxException::because('sheet_sources.errors.not_xlsx');
        } finally {
            @unlink($path);
        }

        $sheet = $source->worksheet ? $book->getSheetByName($source->worksheet) : $book->getSheet(0);

        if ($sheet === null) {
            throw SheetInboxException::because('sheet_sources.errors.no_sheet', ['sheet' => (string) $source->worksheet]);
        }

        $table = $sheet->toArray(null, true, true, false);
        $headerIndex = max(1, (int) $source->header_row) - 1;
        $headers = array_map(fn (mixed $value): string => trim((string) $value), $table[$headerIndex] ?? []);
        $rows = [];

        foreach (array_slice($table, $headerIndex + 1) as $values) {
            $row = [];

            foreach ($headers as $position => $header) {
                if ($header !== '') {
                    $row[$header] = trim((string) ($values[$position] ?? ''));
                }
            }

            if (implode('', $row) !== '') {
                $rows[] = $row;
            }
        }

        $book->disconnectWorksheets();

        return $rows;
    }

    /**
     * @param  callable(): Response  $request
     */
    private function get(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException) {
            throw SheetInboxException::because('sheet_sources.errors.network');
        }
    }
}
