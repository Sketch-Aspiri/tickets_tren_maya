<?php

declare(strict_types=1);

namespace App\Services\Cloud;

/**
 * Sube (reemplazando) un archivo a un almacenamiento en la nube. Interfaz propia para poder simularlo en pruebas.
 */
interface CloudFileUploader
{
    /**
     * @throws CloudSyncException si la configuracion es invalida o la subida falla definitivamente.
     */
    public function upload(string $contents, string $contentType): void;
}
