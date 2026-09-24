<?php

declare(strict_types=1);

namespace Tests\Feature\Activities;

use App\Models\Activity;
use App\Models\Attachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsActivityScenario;
use Tests\DatabaseTestCase;

/**
 * Límites por usuario autenticado en las rutas de escritura y descarga de actividades (RateLimiter::for).
 */
class ActivityRateLimitTest extends DatabaseTestCase
{
    use BuildsActivityScenario;

    private Activity $activity;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->buildScenario();
        $this->activity = $this->makeActivity($this->teamA, [], $this->empA1);
    }

    public function test_creating_activities_is_limited_per_hour_per_user(): void
    {
        config(['tickets.rate_limits.activity_create_per_hour' => 2]);
        $payload = ['title' => 'x', 'description' => 'd', 'priority' => 'low', 'responsible_id' => $this->empA1->id];

        $this->signIn($this->coordA)->post('/activities', $payload)->assertRedirect();
        $this->post('/activities', $payload)->assertRedirect();
        $this->post('/activities', $payload)->assertStatus(429);

        // El límite es por usuario: otro coordinador no lo comparte.
        $this->signIn($this->coordB)->post('/activities', [...$payload, 'responsible_id' => $this->empB1->id])->assertRedirect();
    }

    public function test_writes_are_limited_per_minute_per_user(): void
    {
        config(['tickets.rate_limits.activity_write_per_minute' => 2]);

        $this->signIn($this->coordA)->post("/activities/{$this->activity->id}/subtasks", ['title' => 'uno'])->assertRedirect();
        $this->post("/activities/{$this->activity->id}/subtasks", ['title' => 'dos'])->assertRedirect();
        $this->post("/activities/{$this->activity->id}/subtasks", ['title' => 'tres'])->assertStatus(429);

        $this->assertSame(2, $this->activity->subtasks()->count());
    }

    public function test_comments_are_limited_per_minute_per_user(): void
    {
        config(['tickets.rate_limits.activity_comment_per_minute' => 2]);

        $this->signIn($this->empA1)->post("/activities/{$this->activity->id}/comments", ['body' => 'uno'])->assertRedirect();
        $this->post("/activities/{$this->activity->id}/comments", ['body' => 'dos'])->assertRedirect();
        $this->post("/activities/{$this->activity->id}/comments", ['body' => 'tres'])->assertStatus(429);

        $this->assertSame(2, $this->activity->comments()->count());
    }

    public function test_uploads_are_limited_per_minute_per_user(): void
    {
        config(['tickets.rate_limits.activity_upload_per_minute' => 1]);
        $file = fn (): UploadedFile => UploadedFile::fake()->createWithContent('a.txt', 'texto');

        $this->signIn($this->empA1)->post("/activities/{$this->activity->id}/attachments", ['file' => $file()])->assertRedirect();
        $this->post("/activities/{$this->activity->id}/attachments", ['file' => $file()])->assertStatus(429);
    }

    public function test_downloads_are_limited_per_minute_per_user(): void
    {
        config(['tickets.rate_limits.attachment_download_per_minute' => 1]);
        $attachment = Attachment::factory()->for($this->activity, 'attachable')->create(['user_id' => $this->empA1->id]);
        Storage::disk('local')->put($attachment->path, 'x');

        $this->signIn($this->empA1)->get(route('attachments.download', $attachment))->assertOk();
        $this->get(route('attachments.download', $attachment))->assertStatus(429);
    }
}
