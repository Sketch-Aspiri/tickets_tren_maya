<?php

declare(strict_types=1);

namespace Tests\Feature\Org;

use App\Contracts\HostResolver;
use App\Models\Coordination;
use App\Models\CoordinationMailbox;
use Tests\DatabaseTestCase;
use Tests\Support\FakeHostResolver;

class SeedMailboxFromEnvTest extends DatabaseTestCase
{
    private Coordination $coordination;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coordination = Coordination::factory()->create(['code' => 'TI-MER']);
        $this->app->instance(HostResolver::class, new FakeHostResolver(['imap.ejemplo.mx' => ['93.184.216.34']]));
        config([
            'imap.accounts.default.host' => 'imap.ejemplo.mx',
            'imap.accounts.default.port' => 993,
            'imap.accounts.default.encryption' => 'ssl',
            'imap.accounts.default.username' => 'tickets@ejemplo.mx',
            'imap.accounts.default.password' => 'Clave-Del-Env-123',
        ]);
    }

    public function test_it_creates_the_mailbox_from_the_env_accounts_and_never_prints_the_password(): void
    {
        $this->artisan('mailboxes:seed-from-env', ['coordination' => 'ti-mer'])
            ->doesntExpectOutputToContain('Clave-Del-Env-123')
            ->assertSuccessful();

        $mailbox = CoordinationMailbox::query()->where('coordination_id', $this->coordination->id)->firstOrFail();
        $this->assertSame('imap.ejemplo.mx', $mailbox->host);
        $this->assertSame('Clave-Del-Env-123', $mailbox->password);
    }

    public function test_it_refuses_to_replace_an_existing_mailbox_without_force(): void
    {
        $this->artisan('mailboxes:seed-from-env', ['coordination' => (string) $this->coordination->id])->assertSuccessful();

        $this->artisan('mailboxes:seed-from-env', ['coordination' => (string) $this->coordination->id])->assertFailed();
        $this->artisan('mailboxes:seed-from-env', ['coordination' => (string) $this->coordination->id, '--force' => true])->assertSuccessful();
        $this->assertSame(1, CoordinationMailbox::query()->count());
    }

    public function test_it_rejects_a_private_host_and_unknown_coordinations(): void
    {
        config(['imap.accounts.default.host' => '10.0.0.5']);

        $this->artisan('mailboxes:seed-from-env', ['coordination' => 'TI-MER'])->assertFailed();
        $this->artisan('mailboxes:seed-from-env', ['coordination' => 'NO-EXISTE'])->assertFailed();
        $this->assertSame(0, CoordinationMailbox::query()->count());
    }
}
