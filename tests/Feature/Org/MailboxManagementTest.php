<?php

declare(strict_types=1);

namespace Tests\Feature\Org;

use App\Contracts\HostResolver;
use App\Contracts\MailboxResolver;
use App\Models\Coordination;
use App\Models\CoordinationMailbox;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Tests\DatabaseTestCase;
use Tests\Support\FakeHostResolver;
use Tests\Support\FakeMailboxResolver;

class MailboxManagementTest extends DatabaseTestCase
{
    private const SECRET = 'S3cret-Mailbox-Pass!';

    private User $admin;

    private Coordination $coordination;

    private FakeMailboxResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->administrador()->create();
        $this->coordination = Coordination::factory()->create();
        $this->resolver = new FakeMailboxResolver;
        $this->app->instance(MailboxResolver::class, $this->resolver);
        $this->app->instance(HostResolver::class, new FakeHostResolver([
            'imap.ejemplo.mx' => ['93.184.216.34'],
            'interno.ejemplo.mx' => ['10.0.0.5'],
            'mixto.ejemplo.mx' => ['93.184.216.34', '192.168.1.10'],
            'loop.ejemplo.mx' => ['127.0.0.1'],
        ]));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'host' => 'imap.ejemplo.mx',
            'port' => 993,
            'encryption' => 'ssl',
            'username' => 'tickets@ejemplo.mx',
            'password' => self::SECRET,
            'folder' => 'INBOX',
            'active' => '1',
        ], $overrides);
    }

    private function save(array $overrides = [])
    {
        return $this->signIn($this->admin)->put("/coordinations/{$this->coordination->id}/mailbox", $this->payload($overrides));
    }

    public function test_admin_creates_a_mailbox_and_the_password_is_stored_encrypted(): void
    {
        $this->save()->assertRedirect(route('mailboxes.index'))->assertSessionHas('status', 'mailbox-saved');

        $mailbox = CoordinationMailbox::query()->where('coordination_id', $this->coordination->id)->firstOrFail();
        $this->assertSame('imap.ejemplo.mx', $mailbox->host);
        $this->assertSame(self::SECRET, $mailbox->password);

        $raw = (string) DB::table('coordination_mailboxes')->value('password');
        $this->assertNotSame(self::SECRET, $raw);
        $this->assertStringNotContainsString(self::SECRET, $raw);
    }

    public function test_the_password_is_never_rendered_serialized_or_logged(): void
    {
        $this->save()->assertSessionHasNoErrors();
        $mailbox = CoordinationMailbox::query()->firstOrFail();

        $this->signIn($this->admin)->get("/coordinations/{$this->coordination->id}/mailbox")->assertOk()
            ->assertDontSee(self::SECRET)
            ->assertSee('tickets@ejemplo.mx');
        $this->signIn($this->admin)->get('/mailboxes')->assertOk()->assertDontSee(self::SECRET);

        $this->assertArrayNotHasKey('password', $mailbox->toArray());
        $this->assertStringNotContainsString(self::SECRET, Activity::query()->get()->toJson());
    }

    public function test_an_empty_password_keeps_the_current_one_on_update(): void
    {
        $this->save();

        $this->save(['password' => '', 'username' => 'otro@ejemplo.mx'])->assertSessionHasNoErrors();

        $mailbox = CoordinationMailbox::query()->firstOrFail();
        $this->assertSame('otro@ejemplo.mx', $mailbox->username);
        $this->assertSame(self::SECRET, $mailbox->password);
        $this->assertSame(1, CoordinationMailbox::query()->count());
    }

    public function test_a_password_is_required_when_creating(): void
    {
        $this->save(['password' => ''])->assertInvalid(['password']);

        $this->assertSame(0, CoordinationMailbox::query()->count());
    }

    public function test_changes_are_audited_without_secrets(): void
    {
        $this->save();
        $this->save(['password' => 'Nueva-Clave-12345', 'port' => 143]);

        $events = Activity::query()->where('log_name', 'mailboxes')->orderBy('id')->pluck('event')->all();
        $this->assertSame(['created', 'updated'], $events);
        $this->assertStringNotContainsString('Nueva-Clave-12345', Activity::query()->get()->toJson());
        $this->assertStringNotContainsString(self::SECRET, Activity::query()->get()->toJson());
    }

    public function test_the_host_must_be_public_to_prevent_ssrf(): void
    {
        foreach (['localhost', '127.0.0.1', '10.1.2.3', '192.168.0.9', '169.254.169.254', '::1', 'interno.ejemplo.mx', 'mixto.ejemplo.mx', 'loop.ejemplo.mx'] as $host) {
            $this->save(['host' => $host])->assertInvalid(['host']);
        }

        $this->assertSame(0, CoordinationMailbox::query()->count());
    }

    public function test_an_unresolvable_or_malformed_host_is_rejected_and_a_public_one_accepted(): void
    {
        $this->save(['host' => 'no-existe.ejemplo.mx'])->assertInvalid(['host']);
        $this->save(['host' => 'bad host!'])->assertInvalid(['host']);
        $this->save(['host' => 'http://imap.ejemplo.mx'])->assertInvalid(['host']);
        $this->save(['host' => '93.184.216.34'])->assertSessionHasNoErrors();
    }

    public function test_port_encryption_and_folder_are_validated(): void
    {
        $this->save(['port' => 0])->assertInvalid(['port']);
        $this->save(['port' => 70000])->assertInvalid(['port']);
        $this->save(['encryption' => 'rot13'])->assertInvalid(['encryption']);
        $this->save(['folder' => "INBOX\r\nA1 LOGOUT"])->assertInvalid(['folder']);
        $this->save(['username' => ''])->assertInvalid(['username']);
    }

    public function test_only_the_administrator_reaches_the_mailbox_screens(): void
    {
        $this->save();
        $routes = [
            ['get', '/mailboxes'],
            ['get', "/coordinations/{$this->coordination->id}/mailbox"],
            ['put', "/coordinations/{$this->coordination->id}/mailbox"],
            ['post', "/coordinations/{$this->coordination->id}/mailbox/test"],
            ['delete', "/coordinations/{$this->coordination->id}/mailbox"],
        ];

        foreach ([User::factory()->jefe()->create(), User::factory()->coordinador()->create(), User::factory()->empleado()->create()] as $user) {
            foreach ($routes as [$method, $uri]) {
                $this->signIn($user)->{$method}($uri, $this->payload())->assertForbidden();
            }
        }

        $this->assertSame(1, CoordinationMailbox::query()->count());
        $this->assertSame([], $this->resolver->testedCoordinationIds);
    }

    public function test_testing_the_connection_reports_success_or_a_failure_without_credentials(): void
    {
        $this->save();

        $this->signIn($this->admin)->post("/coordinations/{$this->coordination->id}/mailbox/test")
            ->assertRedirect(route('mailboxes.index'))->assertSessionHas('status', 'mailbox-test-ok');
        $this->assertSame([$this->coordination->id], $this->resolver->testedCoordinationIds);

        $this->resolver->failWith($this->coordination->id, 'Authentication failed');
        $response = $this->signIn($this->admin)->post("/coordinations/{$this->coordination->id}/mailbox/test");
        $response->assertSessionHas('error');
        $this->assertStringContainsString('Authentication failed', (string) session('error'));
        $this->assertStringNotContainsString(self::SECRET, (string) session('error'));
    }

    public function test_testing_without_a_mailbox_explains_it(): void
    {
        $this->signIn($this->admin)->from('/mailboxes')->post("/coordinations/{$this->coordination->id}/mailbox/test")
            ->assertSessionHas('error', __('mailboxes.errors.not_configured'));
    }

    public function test_the_test_endpoint_is_rate_limited(): void
    {
        $this->save();

        for ($i = 0; $i < (int) config('tickets.rate_limits.mailbox_test_per_minute'); $i++) {
            $this->signIn($this->admin)->post("/coordinations/{$this->coordination->id}/mailbox/test")->assertRedirect();
        }

        $this->signIn($this->admin)->post("/coordinations/{$this->coordination->id}/mailbox/test")->assertStatus(429);
    }

    public function test_admin_deletes_a_mailbox(): void
    {
        $this->save();

        $this->signIn($this->admin)->delete("/coordinations/{$this->coordination->id}/mailbox")
            ->assertRedirect(route('mailboxes.index'));

        $this->assertSame(0, CoordinationMailbox::query()->count());
    }

    public function test_the_index_shows_the_status_of_each_coordination(): void
    {
        $this->save();
        CoordinationMailbox::query()->firstOrFail()->forceFill(['last_error' => 'RuntimeException: boom'])->save();
        Coordination::factory()->create(['name' => 'Sin buzon']);

        $this->signIn($this->admin)->get('/mailboxes')->assertOk()
            ->assertSee('RuntimeException: boom')
            ->assertSee(__('mailboxes.status.none'));
    }

    public function test_the_mailbox_model_has_an_explicit_fillable_and_hides_the_password(): void
    {
        $mailbox = new CoordinationMailbox;

        $this->assertContains('password', $mailbox->getHidden());
        $this->assertNotSame([], $mailbox->getGuarded());
        $this->assertSame('coordination_mailbox', $mailbox->getMorphClass());
    }
}
