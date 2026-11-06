<?php

declare(strict_types=1);

namespace Tests\Feature\Emails;

use App\Contracts\HostResolver;
use App\Models\Coordination;
use App\Models\IncomingEmail;
use App\Models\SpreadsheetSource;
use App\Models\Station;
use App\Models\Team;
use App\Models\User;
use App\Notifications\NewIncomingEmailToReview;
use App\Services\SheetInbox\MicrosoftPersonalAuth;
use App\Services\SheetInbox\SheetInboxImporter;
use App\Services\SheetInbox\SheetWorkbookReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\DatabaseTestCase;

/**
 * "Mi bandeja": cada jefe/coordinador configura y sincroniza la bandeja de sus coordinaciones (buzón y hoja de
 * Excel de OneDrive con inicio de sesión de Microsoft). Todo contra Microsoft va simulado con Http::fake.
 */
class InboxSettingsAndSheetSourceTest extends DatabaseTestCase
{
    private const SHARE = 'https://1drv.ms/x/c/abc/IQBexample?e=xyz';

    private Coordination $own;

    private Coordination $other;

    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tickets.sheet_inbox.client_id' => '00000000-0000-0000-0000-000000000001',
            'tickets.sheet_inbox.client_secret' => 'secreto-de-prueba',
            'tickets.sheet_inbox.tenant' => 'consumers',
        ]);

        $station = Station::factory()->create(['name' => 'Zonal']);
        $this->own = Coordination::factory()->create(['station_id' => $station->id, 'name' => 'Propia']);
        $this->other = Coordination::factory()->create(['station_id' => $station->id, 'name' => 'Ajena']);
        $team = Team::factory()->create(['coordination_id' => $this->own->id]);
        $this->coordinator = User::factory()->coordinador()->inTeams($team)->create(['team_id' => $team->id]);

        // Anti-SSRF del buzón: el host de prueba resuelve a una IP pública.
        $this->app->instance(HostResolver::class, new class implements HostResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }

    private function source(array $attributes = []): SpreadsheetSource
    {
        return SpreadsheetSource::query()->create([
            'coordination_id' => $this->own->id,
            'name' => 'Solicitudes',
            'share_url' => self::SHARE,
            'header_row' => 1,
            'subject_column' => 'Asunto',
            'body_columns' => ['Detalle', 'Área'],
            'requester_name_column' => 'Nombre',
            'requester_email_column' => 'Correo',
            'key_column' => 'Folio',
            'active' => true,
            ...$attributes,
        ]);
    }

    private function connected(): SpreadsheetSource
    {
        $source = $this->source();
        $source->forceFill(['access_token' => 'token-vigente', 'refresh_token' => 'refresh-1', 'token_expires_at' => now()->addHour(), 'account_name' => 'persona@outlook.com'])->save();

        return $source;
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function xlsx(array $rows): string
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'xls');
        (new Xlsx($book))->save($path);
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private function fakeGraph(string $bytes): void
    {
        $item = 'https://graph.microsoft.com/v1.0/shares/'.SheetWorkbookReader::shareId(self::SHARE).'/driveItem';

        Http::fake([
            $item.'/content' => Http::response($bytes, 200, ['Content-Type' => 'application/octet-stream']),
            $item.'*' => Http::response(['name' => 'Bandeja.xlsx', 'size' => strlen($bytes), 'file' => []]),
        ]);
    }

    public function test_coordinator_sees_only_his_coordinations_and_gets_404_on_others(): void
    {
        $this->signIn($this->coordinator)->get(route('inbox-settings.index'))
            ->assertOk()->assertSee('Zonal › Propia')->assertDontSee('Zonal › Ajena');

        $this->signIn($this->coordinator)->get(route('mailboxes.edit', $this->own))->assertOk();
        $this->signIn($this->coordinator)->get(route('mailboxes.edit', $this->other))->assertNotFound();
        $this->signIn($this->coordinator)->get(route('sheet-sources.edit', $this->other))->assertNotFound();
        $this->signIn($this->coordinator)->post(route('mailboxes.sync', $this->other))->assertNotFound();
        // El listado global de buzones sigue siendo solo del administrador.
        $this->signIn($this->coordinator)->get(route('mailboxes.index'))->assertForbidden();
    }

    public function test_employee_has_no_access(): void
    {
        $employee = User::factory()->empleado()->create();

        $this->signIn($employee)->get(route('inbox-settings.index'))->assertForbidden();
    }

    public function test_coordinator_saves_his_mailbox_credentials_and_returns_to_his_inbox(): void
    {
        $this->signIn($this->coordinator)->put(route('mailboxes.update', $this->own), [
            'host' => 'imap.example.com', 'port' => 993, 'encryption' => 'ssl', 'username' => 'bandeja@example.com',
            'password' => 'Secreta-123', 'folder' => 'INBOX', 'active' => 1,
        ])->assertRedirect(route('inbox-settings.index'));

        $this->assertSame('bandeja@example.com', $this->own->fresh()->mailbox->username);
        // La contraseña se guarda cifrada (nunca en claro en la base).
        $this->assertStringNotContainsString('Secreta-123', (string) DB::table('coordination_mailboxes')->value('password'));
    }

    public function test_share_url_must_be_a_onedrive_link(): void
    {
        $payload = ['name' => 'X', 'header_row' => 1, 'subject_column' => 'Asunto'];

        foreach (['http://1drv.ms/x/abc', 'https://evil.example.com/x', 'https://user@onedrive.live.com/x', 'https://1drv.ms:8443/x'] as $url) {
            $this->signIn($this->coordinator)->put(route('sheet-sources.update', $this->own), [...$payload, 'share_url' => $url])->assertInvalid('share_url');
        }

        $this->signIn($this->coordinator)->put(route('sheet-sources.update', $this->own), [...$payload, 'share_url' => self::SHARE, 'body_columns_text' => "Detalle\r\nÁrea\r\n"])
            ->assertRedirect(route('inbox-settings.index'));
        $this->assertSame(['Detalle', 'Área'], $this->own->fresh()->spreadsheetSource->body_columns);
    }

    public function test_connect_redirects_to_microsoft_with_pkce_and_a_one_time_state(): void
    {
        $this->source();

        $response = $this->signIn($this->coordinator)->post(route('sheet-sources.connect', $this->own));
        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://login.microsoftonline.com/consumers/oauth2/v2.0/authorize?', $location);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(route('sheet-sources.callback'), $query['redirect_uri']);
        $this->assertStringContainsString('Files.Read.All', $query['scope']);
        $this->assertSame($query['state'], session('sheet_oauth.state'));
        $this->assertSame(MicrosoftPersonalAuth::challenge(session('sheet_oauth.verifier')), $query['code_challenge']);
    }

    public function test_callback_rejects_a_wrong_state_and_stores_encrypted_tokens_on_success(): void
    {
        $source = $this->source();
        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'acceso-nuevo', 'refresh_token' => 'refresh-nuevo', 'expires_in' => 3600]),
            'https://graph.microsoft.com/v1.0/me*' => Http::response(['userPrincipalName' => 'persona@outlook.com']),
        ]);

        $this->signIn($this->coordinator)->withSession(['sheet_oauth' => ['state' => 'correcto', 'verifier' => 'v', 'coordination_id' => $this->own->id]])
            ->get(route('sheet-sources.callback', ['state' => 'otro', 'code' => 'c']))->assertSessionHas('error');
        $this->assertFalse($source->fresh()->isConnected());

        $this->signIn($this->coordinator)->withSession(['sheet_oauth' => ['state' => 'correcto', 'verifier' => 'v', 'coordination_id' => $this->own->id]])
            ->get(route('sheet-sources.callback', ['state' => 'correcto', 'code' => 'c']))->assertSessionHas('status', 'sheet-source-connected');

        $fresh = $source->fresh();
        $this->assertTrue($fresh->isConnected());
        $this->assertSame('persona@outlook.com', $fresh->account_name);
        $this->assertStringNotContainsString('refresh-nuevo', (string) DB::table('spreadsheet_sources')->value('refresh_token'));
        $this->assertArrayNotHasKey('refresh_token', $fresh->toArray());
    }

    public function test_callback_cannot_connect_a_coordination_outside_the_users_scope(): void
    {
        SpreadsheetSource::query()->create(['coordination_id' => $this->other->id, 'name' => 'Ajena', 'share_url' => self::SHARE, 'header_row' => 1, 'subject_column' => 'Asunto', 'active' => true]);

        $this->signIn($this->coordinator)->withSession(['sheet_oauth' => ['state' => 's', 'verifier' => 'v', 'coordination_id' => $this->other->id]])
            ->get(route('sheet-sources.callback', ['state' => 's', 'code' => 'c']))->assertNotFound();
    }

    public function test_sync_imports_new_rows_into_the_review_inbox_without_duplicates(): void
    {
        Notification::fake();
        $boss = User::factory()->jefeOf($this->own)->create();
        $source = $this->connected();
        $this->fakeGraph($this->xlsx([
            ['Folio', 'Asunto', 'Detalle', 'Área', 'Nombre', 'Correo'],
            ['S-1', 'Falla de impresora', 'No imprime', 'Oficina 2', 'Ana', 'ana@example.com'],
            ['S-2', 'Sin acceso', 'Olvidé la contraseña', 'Taller', 'Beto', 'no-es-correo'],
            ['', '', '', '', '', ''],
        ]));

        $this->signIn($this->coordinator)->post(route('sheet-sources.sync', $this->own))->assertSessionHas('notice');

        $emails = IncomingEmail::query()->where('coordination_id', $this->own->id)->orderBy('id')->get();
        $this->assertCount(2, $emails);
        $this->assertSame('Falla de impresora', $emails[0]->subject);
        $this->assertSame("Detalle: No imprime\nÁrea: Oficina 2", $emails[0]->body);
        $this->assertSame('ana@example.com', $emails[0]->from_email);
        $this->assertSame(SheetInboxImporter::PLACEHOLDER_EMAIL, $emails[1]->from_email);
        Notification::assertSentTo($boss, NewIncomingEmailToReview::class);

        // Releer la misma hoja no duplica.
        $this->assertSame(['created' => 0, 'skipped' => 2], app(SheetInboxImporter::class)->import($source->fresh()));
        $this->assertNull($source->fresh()->last_error);
    }

    public function test_an_expired_token_is_refreshed(): void
    {
        $source = $this->connected();
        $source->forceFill(['token_expires_at' => now()->subMinute()])->save();
        $bytes = $this->xlsx([['Folio', 'Asunto'], ['1', 'Uno']]);
        $item = 'https://graph.microsoft.com/v1.0/shares/'.SheetWorkbookReader::shareId(self::SHARE).'/driveItem';

        Http::fake([
            'https://login.microsoftonline.com/*' => Http::response(['access_token' => 'renovado', 'expires_in' => 3600]),
            $item.'/content' => Http::response($bytes),
            $item.'*' => Http::response(['size' => strlen($bytes)]),
        ]);

        app(SheetInboxImporter::class)->import($source->fresh());
        $this->assertSame('renovado', $source->fresh()->access_token);
        // Sin refresh token nuevo se conserva el anterior.
        $this->assertSame('refresh-1', $source->fresh()->refresh_token);
    }

    public function test_a_revoked_permission_asks_to_reconnect(): void
    {
        $source = $this->connected();
        $source->forceFill(['token_expires_at' => now()->subMinute()])->save();
        Http::fake(['https://login.microsoftonline.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->signIn($this->coordinator)->post(route('sheet-sources.sync', $this->own))->assertSessionHas('error', __('sheet_sources.errors.reconnect'));
        $this->assertSame(__('sheet_sources.errors.reconnect'), $source->fresh()->last_error);
    }

    public function test_the_command_imports_every_connected_source(): void
    {
        $this->connected();
        $this->fakeGraph($this->xlsx([['Folio', 'Asunto'], ['A', 'Desde el comando']]));

        $this->artisan('sheets:ingest')->assertSuccessful();

        $this->assertDatabaseHas('incoming_emails', ['coordination_id' => $this->own->id, 'subject' => 'Desde el comando']);
    }
}
