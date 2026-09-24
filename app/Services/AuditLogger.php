<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Fachada unica sobre spatie/laravel-activitylog para eventos explicitos
 * (los cambios simples de modelo se registran con el trait LogsActivity).
 * Nunca escribe contrasenas ni secretos, aunque el llamador los pase por error.
 */
final class AuditLogger
{
    private const FORBIDDEN_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'remember_token',
        'token',
        'secret',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'code',
        'recovery_code',
        'one_time_password',
    ];

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $extra
     */
    public function record(
        string $logName,
        string $event,
        ?Model $subject = null,
        ?User $causer = null,
        array $old = [],
        array $new = [],
        array $extra = [],
    ): void {
        $logger = activity($logName)->event($event);

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        if ($causer !== null) {
            $logger->causedBy($causer);
        }

        // Igual que el trait LogsActivity (v5): valores anteriores/nuevos en `attribute_changes`,
        // datos adicionales (IP, motivo...) en `properties`.
        if ($old !== [] || $new !== []) {
            $logger->withChanges([
                'old' => $this->redact($old),
                'attributes' => $this->redact($new),
            ]);
        }

        $logger->withProperties($this->redact($extra))->log($event);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function redact(array $data): array
    {
        return array_diff_key($data, array_flip(self::FORBIDDEN_KEYS));
    }
}
