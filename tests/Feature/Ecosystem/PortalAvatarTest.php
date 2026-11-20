<?php

declare(strict_types=1);

namespace Tests\Feature\Ecosystem;

use App\Jobs\PushAvatarToPortal;
use App\Models\User;
use App\Services\AvatarService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\DatabaseTestCase;

/**
 * Foto de perfil unica (decision 98): el portal lee, pone y quita la foto por el puente firmado, y un cambio hecho
 * aqui se avisa al portal (sin eco cuando el cambio vino del portal).
 */
class PortalAvatarTest extends DatabaseTestCase
{
    private const SECRET = 'secreto-portal-a-tickets-de-prueba-0123456789ab';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('tickets.attachments.disk'));
        config([
            'ecosistema.puente.portal.secreto' => self::SECRET,
            'ecosistema.puente.portal.url' => 'https://portal.ejemplo.test',
            'ecosistema.puente.ips' => [],
        ]);
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(300, 200);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 10, 120, 90));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** @return array<string, string> */
    private function signed(string $method, string $path, string $body = '', array $query = [], string $secret = self::SECRET): array
    {
        $date = (string) now()->getTimestamp();
        $nonce = Str::random(40);
        ksort($query);
        $canonical = implode("\n", [$method, $path, http_build_query($query, '', '&', PHP_QUERY_RFC3986), 'portal', $date, $nonce, hash('sha256', $body)]);

        return ['X-Puente-Sistema' => 'portal', 'X-Puente-Fecha' => $date, 'X-Puente-Nonce' => $nonce, 'X-Puente-Firma' => hash_hmac('sha256', $canonical, $secret)];
    }

    private function send(string $method, array $body, string $secret = self::SECRET): TestResponse
    {
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $headers = $this->signed($method, '/interno/portal/avatar', $json, secret: $secret) + ['CONTENT_TYPE' => 'application/json', 'Accept' => 'application/json'];

        return $this->call($method, '/interno/portal/avatar', [], [], [], $this->transformHeadersToServerVars($headers), $json);
    }

    public function test_portal_sets_the_photo_without_echoing_it_back(): void
    {
        Queue::fake();
        $user = User::factory()->create(['email' => 'ana@ejemplo.test']);

        $this->send('PUT', ['email' => 'ANA@ejemplo.test', 'imagen' => base64_encode($this->png())])->assertOk()->assertJson(['estado' => 'ok']);

        $user->refresh();
        $this->assertNotNull($user->avatar_path);
        Queue::assertNotPushed(PushAvatarToPortal::class);

        $info = getimagesizefromstring((string) app(AvatarService::class)->bytes($user));
        $this->assertSame([256, 256, IMAGETYPE_JPEG], [$info[0], $info[1], $info[2]]);
    }

    public function test_portal_can_read_and_remove_the_photo(): void
    {
        Queue::fake();
        $user = User::factory()->create(['email' => 'ana@ejemplo.test']);
        app(AvatarService::class)->setFromBytes($user, $this->png(), propagate: false);

        $headers = $this->signed('GET', '/interno/portal/avatar', query: ['email' => 'ana@ejemplo.test']) + ['Accept' => 'application/json'];
        $get = $this->call('GET', '/interno/portal/avatar?email=ana%40ejemplo.test', [], [], [], $this->transformHeadersToServerVars($headers));
        $get->assertOk();
        $this->assertNotEmpty($get->json('imagen'));

        $this->send('DELETE', ['email' => 'ana@ejemplo.test'])->assertOk();
        $this->assertNull($user->refresh()->avatar_path);
        Queue::assertNotPushed(PushAvatarToPortal::class);
    }

    public function test_unknown_account_is_404_and_bad_images_are_rejected(): void
    {
        $user = User::factory()->create(['email' => 'ana@ejemplo.test']);

        $this->send('PUT', ['email' => 'nadie@ejemplo.test', 'imagen' => base64_encode($this->png())])->assertNotFound();
        $this->send('PUT', ['email' => 'ana@ejemplo.test', 'imagen' => base64_encode('<svg onload=alert(1)>')])->assertStatus(422);
        $this->send('PUT', ['email' => 'ana@ejemplo.test', 'imagen' => '***no-base64***'])->assertStatus(422);
        $this->assertNull($user->refresh()->avatar_path);
    }

    public function test_wrong_signature_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'ana@ejemplo.test']);

        $this->send('PUT', ['email' => 'ana@ejemplo.test', 'imagen' => base64_encode($this->png())], secret: 'otro-secreto-distinto-0123456789abcdef0123')->assertStatus(401);
        $this->assertNull($user->refresh()->avatar_path);
    }

    public function test_a_photo_changed_here_is_pushed_to_the_portal_signed_as_tickets(): void
    {
        Http::fake(['https://portal.ejemplo.test/*' => Http::response(['estado' => 'ok'])]);
        $user = User::factory()->create(['email' => 'ana@ejemplo.test']);

        app(AvatarService::class)->update($user, UploadedFile::fake()->createWithContent('f.png', $this->png()));

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request->url() === 'https://portal.ejemplo.test/interno/subsistemas/avatar'
            && $request->hasHeader('X-Puente-Firma')
            && $request->header('X-Puente-Sistema') === ['tickets']
            && ($request->data()['email'] ?? null) === 'ana@ejemplo.test'
            && ! empty($request->data()['imagen']));

        app(AvatarService::class)->remove($user->refresh());
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && ! isset($request->data()['imagen']));
    }
}
