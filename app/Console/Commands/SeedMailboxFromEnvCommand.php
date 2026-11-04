<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\HostResolver;
use App\Models\Coordination;
use App\Models\CoordinationMailbox;
use App\Rules\PublicMailHost;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Migracion unica del buzon que hoy vive en el `.env` (`IMAP_*`) a la base de datos, ligado a una coordinacion.
 * Despues de correrlo y comprobar la conexion en "Buzones", se pueden quitar las variables `IMAP_*` del `.env`.
 * La contrasena se lee de la configuracion, se guarda cifrada y nunca se imprime.
 */
class SeedMailboxFromEnvCommand extends Command
{
    protected $signature = 'mailboxes:seed-from-env {coordination : Id o codigo de la coordinacion} {--force : Reemplaza el buzon si ya existe}';

    protected $description = 'Crea el buzon de una coordinacion a partir de las variables IMAP_* del .env';

    public function handle(AuditLogger $audit): int
    {
        $key = (string) $this->argument('coordination');
        $coordination = Coordination::query()
            ->where(fn ($query) => $query->where('code', strtoupper($key))->orWhere('id', ctype_digit($key) ? (int) $key : 0))
            ->first();

        if ($coordination === null) {
            $this->error('No existe esa coordinación.');

            return self::FAILURE;
        }

        if ($coordination->mailbox !== null && ! $this->option('force')) {
            $this->error('La coordinación ya tiene un buzón. Usa --force para reemplazarlo.');

            return self::FAILURE;
        }

        /** @var array<string, mixed> $account */
        $account = (array) config('imap.accounts.default');
        $host = (string) ($account['host'] ?? '');
        $username = (string) ($account['username'] ?? '');
        $password = (string) ($account['password'] ?? '');
        $encryption = $account['encryption'] === false ? 'none' : (string) ($account['encryption'] ?? 'ssl');

        $validator = Validator::make(
            ['host' => $host, 'username' => $username, 'password' => $password, 'encryption' => $encryption],
            [
                'host' => ['required', new PublicMailHost(app(HostResolver::class))],
                'username' => ['required', 'string'],
                'password' => ['required', 'string'],
                'encryption' => ['required', 'in:'.implode(',', CoordinationMailbox::ENCRYPTIONS)],
            ],
        );

        if ($validator->fails()) {
            $this->error('Las variables IMAP_* no son válidas: '.implode(' ', $validator->errors()->all()));

            return self::FAILURE;
        }

        $mailbox = $coordination->mailbox ?? new CoordinationMailbox(['coordination_id' => $coordination->getKey()]);
        $mailbox->fill([
            'host' => strtolower($host),
            'port' => (int) ($account['port'] ?? 993),
            'encryption' => $encryption,
            'username' => $username,
            'password' => $password,
            'folder' => (string) config('mail_ingestion.folder', 'INBOX'),
            'active' => true,
        ])->save();

        $audit->record('mailboxes', 'seeded_from_env', $coordination, null, extra: ['host' => $mailbox->host, 'username' => $mailbox->username]);
        $this->info("Buzón creado para la coordinación «{$coordination->name}». Pruébalo en Buzones y luego quita IMAP_* del .env.");

        return self::SUCCESS;
    }
}
