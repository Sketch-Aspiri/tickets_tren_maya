<?php

declare(strict_types=1);

namespace App\Services\Cloud;

use RuntimeException;

/**
 * Falla de la sincronizacion a la nube. El mensaje nunca incluye tokens, secretos ni el cuerpo de la respuesta.
 */
final class CloudSyncException extends RuntimeException {}
