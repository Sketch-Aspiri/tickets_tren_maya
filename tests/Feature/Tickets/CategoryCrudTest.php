<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Models\Category;
use App\Models\Ticket;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\BuildsTicketScenario;
use Tests\DatabaseTestCase;

class CategoryCrudTest extends DatabaseTestCase
{
    use BuildsTicketScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    // --- Autorizacion por rol ------------------------------------------------

    public function test_only_jefe_can_reach_category_management(): void
    {
        $category = Category::factory()->create();

        foreach ([$this->coordA, $this->empA1] as $actor) {
            $this->signIn($actor)->get('/categories')->assertForbidden();
            $this->signIn($actor)->get('/categories/create')->assertForbidden();
            $this->signIn($actor)->post('/categories', ['name' => 'X'])->assertForbidden();
            $this->signIn($actor)->get("/categories/{$category->id}/edit")->assertForbidden();
            $this->signIn($actor)->put("/categories/{$category->id}", ['name' => 'X'])->assertForbidden();
            $this->signIn($actor)->delete("/categories/{$category->id}")->assertForbidden();
        }

        $this->assertDatabaseMissing('categories', ['name' => 'X']);
        $this->assertSame(1, Category::query()->count());
    }

    // --- CRUD -----------------------------------------------------------------

    public function test_jefe_creates_lists_updates_and_deletes_a_category_with_audit(): void
    {
        $this->signIn($this->jefe)->get('/categories/create')->assertOk();

        $this->signIn($this->jefe)->post('/categories', ['name' => 'Redes', 'active' => '1'])
            ->assertRedirect(route('categories.index'))
            ->assertSessionHas('status', 'category-created');

        $category = Category::query()->where('name', 'Redes')->firstOrFail();
        $this->assertTrue($category->active);

        $this->signIn($this->jefe)->get('/categories')->assertOk()->assertSee('Redes');
        $this->signIn($this->jefe)->get("/categories/{$category->id}/edit")->assertOk()->assertSee('Redes');

        $this->signIn($this->jefe)->put("/categories/{$category->id}", ['name' => 'Redes y Telecom', 'active' => '0'])
            ->assertRedirect(route('categories.index'));

        $fresh = $category->fresh();
        $this->assertSame('Redes y Telecom', $fresh->name);
        $this->assertFalse($fresh->active);

        $this->signIn($this->jefe)->delete("/categories/{$category->id}")->assertRedirect(route('categories.index'));
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);

        $events = Activity::query()->where('subject_type', 'category')->where('subject_id', $category->id)->orderBy('id')->pluck('event')->all();
        $this->assertSame(['created', 'updated', 'deleted'], $events);
        $this->assertSame($this->jefe->id, Activity::query()->where('subject_type', 'category')->where('event', 'updated')->firstOrFail()->causer_id);
    }

    public function test_omitting_active_on_update_keeps_the_current_state(): void
    {
        $category = Category::factory()->inactive()->create(['name' => 'Vieja']);

        $this->signIn($this->jefe)->put("/categories/{$category->id}", ['name' => 'Nueva'])->assertSessionHasNoErrors();

        $this->assertFalse($category->fresh()->active);
    }

    public function test_category_with_tickets_cannot_be_deleted_only_deactivated(): void
    {
        $category = Category::factory()->create();
        Ticket::factory()->create(['category_id' => $category->id]);

        $this->signIn($this->jefe)->from('/categories')->delete("/categories/{$category->id}")
            ->assertRedirect('/categories')
            ->assertSessionHas('error', __('categories.errors.has_tickets'));

        $this->assertDatabaseHas('categories', ['id' => $category->id]);

        $this->signIn($this->jefe)->put("/categories/{$category->id}", ['name' => $category->name, 'active' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse($category->fresh()->active);
    }

    public function test_category_with_only_soft_deleted_tickets_still_cannot_be_deleted(): void
    {
        $category = Category::factory()->create();
        Ticket::factory()->create(['category_id' => $category->id])->delete();

        $this->signIn($this->jefe)->delete("/categories/{$category->id}")->assertSessionHas('error');

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_deleting_an_empty_category_works(): void
    {
        $category = Category::factory()->create();

        $this->signIn($this->jefe)->delete("/categories/{$category->id}")->assertSessionHas('status', 'category-deleted');

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    // --- Validacion y asignacion masiva --------------------------------------

    public function test_name_is_required_unique_and_limited(): void
    {
        Category::factory()->create(['name' => 'Hardware']);
        $other = Category::factory()->create(['name' => 'Otra']);

        $this->signIn($this->jefe)->post('/categories', [])->assertInvalid('name');
        $this->signIn($this->jefe)->post('/categories', ['name' => 'Hardware'])->assertInvalid('name');
        $this->signIn($this->jefe)->post('/categories', ['name' => str_repeat('a', 256)])->assertInvalid('name');
        $this->signIn($this->jefe)->post('/categories', ['name' => ['array']])->assertInvalid('name');
        $this->signIn($this->jefe)->put("/categories/{$other->id}", ['name' => 'Hardware'])->assertInvalid('name');
        $this->signIn($this->jefe)->post('/categories', ['name' => 'Valida', 'active' => 'maybe'])->assertInvalid('active');

        // Conservar su propio nombre al editar es valido.
        $this->signIn($this->jefe)->put("/categories/{$other->id}", ['name' => 'Otra'])->assertValid();
        $this->signIn($this->jefe)->post('/categories', ['name' => 'Valida'])->assertValid();
    }

    public function test_extra_fields_are_not_mass_assigned(): void
    {
        $this->signIn($this->jefe)->post('/categories', ['name' => 'Segura', 'id' => 999, 'created_at' => '2000-01-01 00:00:00']);

        $category = Category::query()->where('name', 'Segura')->firstOrFail();
        $this->assertNotSame(999, $category->id);
        $this->assertNotSame(2000, $category->created_at->year);
    }

    // --- Vistas ------------------------------------------------------------------

    public function test_only_the_jefe_sees_the_categories_link_in_the_sidebar(): void
    {
        $this->signIn($this->jefe)->get('/dashboard')->assertSee(route('categories.index'), false);
        $this->signIn($this->coordA)->get('/dashboard')->assertDontSee(route('categories.index'), false);
        $this->signIn($this->empA1)->get('/dashboard')->assertDontSee(route('categories.index'), false);
    }

    public function test_index_is_paginated_and_counts_tickets_without_n_plus_one(): void
    {
        Category::factory()->count(20)->create();

        $response = $this->signIn($this->jefe)->get('/categories')->assertOk();

        $this->assertSame((int) config('tickets.categories_per_page'), $response->viewData('categories')->count());
        $this->assertSame(20, $response->viewData('categories')->total());
    }

    public function test_category_names_are_escaped_in_the_index(): void
    {
        Category::factory()->create(['name' => '<script>alert(1)</script>']);

        $this->signIn($this->jefe)->get('/categories')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }
}
