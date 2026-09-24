<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * Única definición de "adjunto aceptable". Nunca se confía en la extensión ni en el MIME que declara el
 * cliente: se exige que la extensión esté en la lista blanca (config/tickets.php) Y que el MIME
 * detectado por el CONTENIDO (finfo) corresponda a esa extensión. Para docx/xlsx se comprueba además la
 * estructura interna del paquete OOXML.
 */
final class AttachmentInspector
{
    /** Archivo mínimo que debe contener cada formato OOXML. */
    private const OOXML_MARKERS = [
        'docx' => 'word/document.xml',
        'xlsx' => 'xl/workbook.xml',
    ];

    /**
     * Extensión validada en minúsculas, o null si el archivo no es aceptable.
     */
    public function acceptedExtension(UploadedFile $file): ?string
    {
        if (! $file->isValid()) {
            return null;
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $allowed = (array) config('tickets.attachments.allowed');

        if (! isset($allowed[$extension])) {
            return null;
        }

        $detected = (string) $file->getMimeType();

        if (! in_array($detected, $allowed[$extension], true)) {
            return null;
        }

        // libmagic adivina docx/xlsx por el nombre de la primera entrada del zip, asi que el MIME "especifico"
        // no basta: un docx/xlsx SIEMPRE debe tener la estructura interna OOXML, sea cual sea el MIME detectado.
        if (isset(self::OOXML_MARKERS[$extension]) && ! $this->hasOoxmlStructure($file, $extension)) {
            return null;
        }

        return $extension;
    }

    /**
     * Nombre original apto para mostrarse y guardarse: sin rutas, sin caracteres de control ni de
     * marcado, sin puntos iniciales y con longitud acotada. Se imprime siempre con `{{ }}`.
     */
    public function sanitizeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\p{L}\p{N} ._()\-]/u', '', $name) ?? '';
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), ' .');
        $name = mb_substr($name, 0, 100);

        return $name === '' ? 'archivo' : $name;
    }

    private function hasOoxmlStructure(UploadedFile $file, string $extension): bool
    {
        $marker = self::OOXML_MARKERS[$extension] ?? null;

        if ($marker === null) {
            return false;
        }

        $zip = new ZipArchive;

        if ($zip->open($file->getRealPath()) !== true) {
            return false;
        }

        $isOoxml = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName($marker) !== false;
        $zip->close();

        return $isOoxml;
    }
}
