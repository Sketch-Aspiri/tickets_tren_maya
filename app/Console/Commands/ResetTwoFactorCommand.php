<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Recuperacion desde consola cuando un usuario pierde su dispositivo y sus codigos de recuperacion.
 * Es deliberadamente un comando y no una ruta web. Quien lo ejecuta ya tiene acceso al servidor.
 */
class ResetTwoFactorCommand extends Command
{
    protected $signature = 'users:reset-2fa {email : Correo del usuario cuyo 2FA se restablece}';

    protected $description = 'Restablece el 2FA de un usuario (secreto, codigos de recuperacion y sesiones); debera configurarlo de nuevo';

    public function handle(TwoFactorService $twoFactor): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error(__('two_factor.command.not_found', ['email' => $email]));

            return self::FAILURE;
        }

        if (! $this->confirm(__('two_factor.command.confirm', ['email' => $email]))) {
            $this->warn(__('two_factor.command.cancelled'));

            return self::FAILURE;
        }

        if (! $twoFactor->reset($user)) {
            $this->info(__('two_factor.command.nothing', ['email' => $email]));

            return self::SUCCESS;
        }

        $this->info(__('two_factor.command.done', ['email' => $email]));

        return self::SUCCESS;
    }
}
