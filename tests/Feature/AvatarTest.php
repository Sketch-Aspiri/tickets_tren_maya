<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\ChatService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\DatabaseTestCase;

class AvatarTest extends DatabaseTestCase
{
    private User $ana;

    private User $beto;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->ana = User::factory()->empleado()->create(['name' => 'Ana Foto']);
        $this->beto = User::factory()->empleado()->create();
    }

    private function upload(User $user, UploadedFile $file): TestResponse
    {
        return $this->signIn($user)->post(route('profile.avatar.update'), ['avatar' => $file]);
    }

    public function test_user_uploads_avatar_and_it_is_reencoded_as_square_jpeg(): void
    {
        $this->upload($this->ana, UploadedFile::fake()->image('yo.png', 400, 300))
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status', 'avatar-updated');

        $path = $this->ana->fresh()->avatar_path;
        $this->assertNotNull($path);
        $this->assertStringStartsWith('avatars/', $path);
        $this->assertStringEndsWith('.jpg', $path);
        Storage::disk('local')->assertExists($path);

        [$width, $height, $type] = getimagesizefromstring(Storage::disk('local')->get($path));
        $this->assertSame([256, 256, IMAGETYPE_JPEG], [$width, $height, $type]);
        $this->assertTrue(Activity::query()->where('event', 'avatar_updated')->where('subject_id', $this->ana->id)->exists());
    }

    public function test_replacing_avatar_deletes_the_previous_file(): void
    {
        $this->upload($this->ana, UploadedFile::fake()->image('a.jpg', 100, 100));
        $first = $this->ana->fresh()->avatar_path;

        $this->upload($this->ana, UploadedFile::fake()->image('b.jpg', 100, 100));

        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($this->ana->fresh()->avatar_path);
    }

    public function test_user_can_remove_avatar(): void
    {
        $this->upload($this->ana, UploadedFile::fake()->image('a.jpg', 100, 100));
        $path = $this->ana->fresh()->avatar_path;

        $this->signIn($this->ana)->delete(route('profile.avatar.destroy'))->assertSessionHas('status', 'avatar-removed');

        $this->assertNull($this->ana->fresh()->avatar_path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_rejects_non_images_disguised_files_svg_and_tiny_images(): void
    {
        $this->upload($this->ana, UploadedFile::fake()->createWithContent('x.jpg', '<?php echo 1;'))->assertSessionHasErrors('avatar');
        $this->upload($this->ana, UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'))->assertSessionHasErrors('avatar');
        $this->upload($this->ana, UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'))->assertSessionHasErrors('avatar');
        $this->upload($this->ana, UploadedFile::fake()->image('mini.png', 10, 10))->assertSessionHasErrors('avatar');
        $this->signIn($this->ana)->post(route('profile.avatar.update'), [])->assertSessionHasErrors('avatar');

        $this->assertNull($this->ana->fresh()->avatar_path);
    }

    public function test_extra_fields_cannot_set_avatar_path_through_profile_update(): void
    {
        $this->signIn($this->ana)->patch(route('profile.update'), ['name' => 'Ana Nueva', 'avatar_path' => 'tickets/1/secreto.pdf']);

        $this->assertNull($this->ana->fresh()->avatar_path);
    }

    public function test_active_users_can_view_avatar_and_path_is_never_exposed(): void
    {
        $this->upload($this->ana, UploadedFile::fake()->image('a.png', 64, 64));
        $ana = $this->ana->fresh();

        $response = $this->signIn($this->beto)->get($ana->avatarUrl())->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertArrayNotHasKey('avatar_path', $ana->toArray());
        $this->assertStringNotContainsString((string) $ana->avatar_path, (string) $ana->avatarUrl());
    }

    public function test_avatar_is_404_without_photo_and_forbidden_for_guests_and_pending_users(): void
    {
        $this->signIn($this->beto)->get(route('avatars.show', $this->ana))->assertNotFound();

        $this->upload($this->ana, UploadedFile::fake()->image('a.png', 64, 64));
        $url = $this->ana->fresh()->avatarUrl();

        $this->app['auth']->forgetGuards();
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->pending()->create())->get($url)->assertRedirect();
    }

    public function test_avatar_of_an_inactive_user_is_not_served_to_others(): void
    {
        $this->upload($this->ana, UploadedFile::fake()->image('a.png', 64, 64));
        $url = $this->ana->fresh()->avatarUrl();
        $this->ana->forceFill(['status' => 'inactive'])->save();

        $this->signIn($this->beto)->get($url)->assertNotFound();
    }

    public function test_avatar_appears_in_chat_messages_and_conversation_list(): void
    {
        $this->upload($this->ana, UploadedFile::fake()->image('a.png', 64, 64));
        $conversation = app(ChatService::class)->startDirect($this->ana, $this->beto);
        $this->signIn($this->ana)->postJson(route('chat.messages.store', $conversation), ['body' => 'hola'])->assertCreated();

        $avatarUrl = e($this->ana->fresh()->avatarUrl());

        $this->assertStringContainsString($avatarUrl, $this->signIn($this->beto)->getJson(route('chat.messages.index', [$conversation, 'after' => 0]))->json('data.html'));
        $this->assertStringContainsString($avatarUrl, $this->signIn($this->beto)->getJson(route('chat.list'))->json('data.html'));
    }

    public function test_user_without_photo_shows_initial(): void
    {
        $this->signIn($this->ana)->get(route('profile.edit'))->assertOk()->assertSee('Foto de perfil');
        $this->assertNull($this->ana->avatarUrl());
    }
}
