<?php

declare(strict_types=1);

namespace Tests\Feature\Push;

use App\Models\User;
use App\Notifications\AvisoPush;
use App\Rules\PushServiceEndpoint;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\DatabaseTestCase;

class PushSubscriptionTest extends DatabaseTestCase
{
    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';

    /**
     * @return array<string, mixed>
     */
    private function payload(string $endpoint = self::ENDPOINT): array
    {
        return [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg'],
            'contentEncoding' => 'aes128gcm',
        ];
    }

    public function test_guest_cannot_subscribe_or_unsubscribe(): void
    {
        $this->postJson(route('push.subscribe'), $this->payload())->assertUnauthorized();
        $this->postJson(route('push.unsubscribe'), ['endpoint' => self::ENDPOINT])->assertUnauthorized();
    }

    public function test_user_can_subscribe_and_unsubscribe(): void
    {
        $user = User::factory()->empleado()->create();

        $this->signIn($user)->postJson(route('push.subscribe'), $this->payload())->assertCreated();
        $this->assertSame(1, $user->pushSubscriptions()->count());

        $this->signIn($user)->postJson(route('push.subscribe'), $this->payload())->assertOk();
        $this->assertSame(1, $user->pushSubscriptions()->count());

        $this->signIn($user)->postJson(route('push.unsubscribe'), ['endpoint' => self::ENDPOINT])->assertOk();
        $this->assertSame(0, $user->pushSubscriptions()->count());
    }

    public function test_user_cannot_delete_another_users_subscription(): void
    {
        $owner = User::factory()->empleado()->create();
        $other = User::factory()->empleado()->create();
        $owner->updatePushSubscription(self::ENDPOINT, 'key', 'token');

        $this->signIn($other)->postJson(route('push.unsubscribe'), ['endpoint' => self::ENDPOINT])->assertOk();

        $this->assertSame(1, $owner->pushSubscriptions()->count());
    }

    public function test_subscription_requires_valid_data(): void
    {
        $user = User::factory()->empleado()->create();

        $this->signIn($user)->postJson(route('push.subscribe'), [])->assertUnprocessable()
            ->assertJsonValidationErrors(['endpoint', 'keys.p256dh', 'keys.auth']);
    }

    public function test_endpoint_must_belong_to_a_known_push_service(): void
    {
        $user = User::factory()->empleado()->create();

        foreach ([
            'http://fcm.googleapis.com/fcm/send/x',
            'https://127.0.0.1/hook',
            'https://169.254.169.254/latest',
            'https://evilfcm.googleapis.com.attacker.test/x',
            'https://fcm.googleapis.com.attacker.test/x',
            'https://user@fcm.googleapis.com/x',
            'https://fcm.googleapis.com:8443/x',
        ] as $endpoint) {
            $this->signIn($user)->postJson(route('push.subscribe'), $this->payload($endpoint))
                ->assertUnprocessable()->assertJsonValidationErrors(['endpoint']);
        }

        $this->assertSame(0, $user->pushSubscriptions()->count());
        $this->assertTrue(PushServiceEndpoint::isAllowed('https://updates.push.services.mozilla.com/wpush/v2/x'));
        $this->assertTrue(PushServiceEndpoint::isAllowed('https://web.push.apple.com/x'));
        $this->assertTrue(PushServiceEndpoint::isAllowed('https://wns2-par02p.notify.windows.com/x'));
    }

    public function test_user_cannot_exceed_the_device_limit(): void
    {
        $user = User::factory()->empleado()->create();

        for ($i = 0; $i < 10; $i++) {
            $user->updatePushSubscription(self::ENDPOINT.$i, 'key', 'token');
        }

        $this->signIn($user)->postJson(route('push.subscribe'), $this->payload(self::ENDPOINT.'extra'))->assertUnprocessable();
        $this->assertSame(10, $user->pushSubscriptions()->count());
    }

    public function test_aviso_push_builds_a_web_push_message(): void
    {
        $user = User::factory()->empleado()->create();
        $notification = new AvisoPush('Titulo', 'Cuerpo', '/tickets/1');

        $this->assertSame([WebPushChannel::class], $notification->via($user));

        $message = $notification->toWebPush($user, $notification)->toArray();

        $this->assertSame('Titulo', $message['title']);
        $this->assertSame('Cuerpo', $message['body']);
        $this->assertSame('/tickets/1', $message['data']['url']);
    }

    public function test_head_links_manifest_and_public_files_exist(): void
    {
        config(['webpush.vapid.public_key' => 'clave-publica-de-prueba']);
        $user = User::factory()->empleado()->create();

        $this->signIn($user)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('rel="manifest"', false)
            ->assertSee('x-data="pushToggle"', false)
            ->assertSee('name="vapid-public-key"', false);

        $manifest = json_decode((string) file_get_contents(public_path('manifest.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/', $manifest['scope']);

        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        }

        $this->assertFileExists(public_path('sw.js'));
        $this->assertFileExists(public_path('offline.html'));
    }
}
