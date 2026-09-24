<?php

declare(strict_types=1);

namespace Tests\Feature\Activities;

use App\Enums\AssignmentRole;
use App\Enums\TicketStatus;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Category;
use Tests\Concerns\BuildsActivityScenario;
use Tests\DatabaseTestCase;

/**
 * Vistas de actividades: acciones según la Policy, escape de contenido de usuario, compatibilidad con
 * la CSP (Alpine build CSP, sin scripts ni manejadores en línea, sin `{!! !!}`) y navegación.
 */
class ActivityViewsTest extends DatabaseTestCase
{
    use BuildsActivityScenario;

    private Activity $activity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
        $this->activity = $this->makeActivity(
            $this->teamA,
            ['title' => '"><x-evil onload=alert(1)>', 'description' => '<script>alert(1)</script>', 'status' => TicketStatus::InProgress, 'due_date' => now()->subDays(2)->toDateString(), 'category_id' => Category::factory()->create()->id],
            $this->empA1,
        );
        $this->assignTo($this->activity, $this->empA2, AssignmentRole::Colaborador);
        $this->makeSubtask($this->activity, "'\"><script>alert(2)</script>", false, $this->empA2);
        $this->makeSubtask($this->activity, 'Hecha', true);
        $this->activity->comments()->create(['body' => "'\"><script>alert(3)</script>", 'user_id' => $this->coordA->id]);
        Attachment::factory()->for($this->activity, 'attachable')->create(['original_name' => '"onmouseover="alert(4).pdf', 'user_id' => $this->empA1->id]);
    }

    /**
     * @return array<string, string>
     */
    private function renderAllPages(): array
    {
        $template = $this->makeActivity($this->teamA, ['recurrence_rule' => ['frequency' => 'weekly', 'interval' => 2, 'days_of_week' => [1, 3], 'day_of_month' => null, 'ends_at' => '2030-12-31'], 'start_date' => '2030-01-01']);
        $instance = $this->makeActivity($this->teamA, ['parent_activity_id' => $template->id, 'occurrence_date' => '2030-01-06', 'title' => 'Instancia'], $this->empA1);

        $pages = [];
        foreach ([[$this->jefe, 'jefe'], [$this->coordA, 'coord']] as [$actor, $label]) {
            foreach (['/activities', '/activities/create', "/activities/{$this->activity->id}", "/activities/{$this->activity->id}/edit", "/activities/{$template->id}", "/activities/{$template->id}/edit", "/activities/{$instance->id}"] as $uri) {
                $pages["{$label} {$uri}"] = $this->signIn($actor)->get($uri)->assertOk()->getContent();
            }
        }
        foreach (['/dashboard', '/tickets/pending', "/activities/{$this->activity->id}", "/activities/{$instance->id}"] as $uri) {
            $pages["emp {$uri}"] = $this->signIn($this->empA1)->get($uri)->assertOk()->getContent();
        }

        return $pages;
    }

    // --- Navegación ----------------------------------------------------------------------------------------------------------------

    public function test_sidebar_offers_pending_to_everyone_and_the_activity_list_only_to_managers(): void
    {
        foreach ([$this->jefe, $this->coordA, $this->empA1] as $actor) {
            $this->signIn($actor)->get('/dashboard')->assertSee(route('tickets.pending'), false);
        }

        $this->signIn($this->jefe)->get('/dashboard')->assertSee(route('activities.index'), false);
        $this->signIn($this->coordA)->get('/dashboard')->assertSee(route('activities.index'), false);
        $this->signIn($this->empA1)->get('/dashboard')->assertDontSee(route('activities.index'), false);
    }

    // --- Acciones según Policy ---------------------------------------------------------------------------------------------------

    public function test_a_manager_sees_management_controls(): void
    {
        $html = $this->signIn($this->coordA)->get("/activities/{$this->activity->id}")->assertOk()->getContent();

        foreach ([
            route('activities.edit', $this->activity),
            route('activities.assignments.update', $this->activity),
            route('activities.subtasks.store', $this->activity),
            route('activities.transition', $this->activity),
            route('activities.destroy', $this->activity),
        ] as $url) {
            $this->assertStringContainsString($url, $html, $url);
        }

        $this->assertStringContainsString(__('activities.actions.transition.cancelled'), $html);
        $this->assertStringContainsString('name="responsible_id"', $html);
    }

    public function test_an_assigned_employee_sees_only_work_controls(): void
    {
        $html = $this->signIn($this->empA1)->get("/activities/{$this->activity->id}")->assertOk()->getContent();

        $this->assertStringContainsString(route('activities.transition', $this->activity), $html);
        $this->assertStringContainsString(__('activities.actions.transition.in_review'), $html);
        $this->assertStringContainsString(route('activities.comments.store', $this->activity), $html);
        $this->assertStringContainsString(route('activities.attachments.store', $this->activity), $html);
        // Es el responsable: puede marcar cualquier subtarea.
        $this->assertStringContainsString('/done', $html);

        // Formularios (action exacto: la URL de alta de subtareas es prefijo de la de marcar) y el enlace de edicion.
        foreach ([route('activities.assignments.update', $this->activity), route('activities.subtasks.store', $this->activity)] as $url) {
            $this->assertStringNotContainsString('action="'.$url.'"', $html, "un empleado no debe ver el formulario {$url}");
        }
        $this->assertStringNotContainsString('href="'.route('activities.edit', $this->activity).'"', $html);
        $this->assertStringNotContainsString(__('activities.actions.transition.cancelled'), $html);
        $this->assertStringNotContainsString(__('activities.actions.transition.completed'), $html);
        $this->assertStringNotContainsString(__('activities.actions.delete'), $html);
    }

    public function test_a_collaborator_only_gets_the_mark_control_for_own_subtasks(): void
    {
        $html = $this->signIn($this->empA2)->get("/activities/{$this->activity->id}")->assertOk()->getContent();

        $mine = $this->activity->subtasks()->where('assigned_to', $this->empA2->id)->firstOrFail();
        $other = $this->activity->subtasks()->where('done', true)->firstOrFail();

        $this->assertStringContainsString(route('activities.subtasks.done', [$this->activity, $mine]), $html);
        $this->assertStringNotContainsString(route('activities.subtasks.done', [$this->activity, $other]), $html);
    }

    public function test_final_activities_offer_reopen_to_managers_and_no_edits(): void
    {
        $done = $this->makeActivity($this->teamA, ['status' => TicketStatus::Completed, 'title' => 'Cerrada'], $this->empA1);
        $this->makeSubtask($done, 'x');

        $html = $this->signIn($this->coordA)->get("/activities/{$done->id}")->assertOk()->getContent();

        $this->assertStringContainsString(__('activities.actions.transition.pending'), $html);
        $this->assertStringNotContainsString(route('activities.edit', $done), $html);
        $this->assertStringNotContainsString(route('activities.subtasks.store', $done), $html);
    }

    // --- Contenido ----------------------------------------------------------------------------------------------------------------------

    public function test_detail_shows_progress_with_text_badges_and_history(): void
    {
        $response = $this->signIn($this->coordA)->get("/activities/{$this->activity->id}")->assertOk();

        $response->assertSee($this->activity->folio)
            ->assertSee('50 %', false)
            ->assertSee(__('activities.show.progress_summary', ['done' => 1, 'total' => 2]))
            ->assertSee(__('tickets.show.overdue'))
            ->assertSee(TicketStatus::InProgress->label())
            ->assertSee(__('tickets.show.history'));
        $this->assertStringContainsString('<progress', $response->getContent());
        $this->assertStringNotContainsString('style="width', $response->getContent());
    }

    public function test_user_content_is_escaped_everywhere(): void
    {
        foreach ($this->renderAllPages() as $name => $html) {
            $this->assertStringNotContainsString('<x-evil', $html, $name);
            $this->assertStringNotContainsString('<script>alert(', $html, $name);
        }

        $html = $this->signIn($this->coordA)->get("/activities/{$this->activity->id}")->getContent();
        $this->assertStringContainsString('&lt;x-evil onload=alert(1)&gt;', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(2)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&quot;onmouseover=&quot;alert(4).pdf', $html);
    }

    public function test_no_page_has_inline_scripts_or_event_handler_attributes(): void
    {
        foreach ($this->renderAllPages() as $name => $html) {
            preg_match_all('#<script\b([^>]*)>#i', $html, $scripts);
            foreach ($scripts[1] as $attributes) {
                $this->assertStringContainsString('src=', $attributes, "script en linea en {$name}");
            }

            $this->assertDoesNotMatchRegularExpression('/<[a-z][^<>]*\son[a-z]+\s*=/i', preg_replace('#"[^"]*"#', '""', $html) ?? '', "manejador on* en {$name}");
        }
    }

    public function test_alpine_attributes_use_simple_names_only_and_carry_no_user_data(): void
    {
        $seen = 0;

        foreach ($this->renderAllPages() as $name => $html) {
            preg_match_all('/\s(x-(?:data|show|on:[\w.:-]+|bind:[\w.:-]+|init|text|html|model|if|for|effect))="([^"]*)"/', $html, $matches, PREG_SET_ORDER);

            foreach ($matches as [$full, $directive, $value]) {
                $seen++;
                $this->assertMatchesRegularExpression('/^[A-Za-z_][A-Za-z0-9_]*$/', $value, "{$name}: {$directive}=\"{$value}\"");
            }

            preg_match_all('/\s(?:x-[\w:.-]+|data-[\w-]+)="([^"]*)"/', $html, $attributes);
            foreach ($attributes[1] as $value) {
                $this->assertStringNotContainsString('alert(', $value, "dato de usuario en atributo de {$name}");
                $this->assertStringNotContainsString('evil', $value, "dato de usuario en atributo de {$name}");
            }
        }

        $this->assertGreaterThan(20, $seen);
    }

    public function test_recurrence_editor_uses_registered_alpine_component_and_plain_fields(): void
    {
        $html = $this->signIn($this->coordA)->get('/activities/create')->assertOk()->getContent();

        $this->assertStringContainsString('x-data="recurrenceEditor"', $html);
        foreach (['name="is_recurring"', 'name="recurrence[frequency]"', 'name="recurrence[interval]"', 'name="recurrence[days_of_week][]"', 'name="recurrence[day_of_month]"', 'name="recurrence[ends_at]"', 'name="start_date"'] as $field) {
            $this->assertStringContainsString($field, $html, $field);
        }
    }

    public function test_only_the_jefe_gets_the_team_field_in_the_create_form(): void
    {
        $this->signIn($this->jefe)->get('/activities/create')->assertSee('name="team_id"', false);
        $this->signIn($this->coordA)->get('/activities/create')->assertDontSee('name="team_id"', false);
    }

    public function test_source_views_do_not_use_unescaped_output(): void
    {
        $offenders = [];

        foreach (['activities', 'components'] as $dir) {
            foreach (glob(resource_path("views/{$dir}/*.blade.php")) ?: [] as $file) {
                if (str_contains((string) file_get_contents($file), '{!!')) {
                    $offenders[] = basename($file);
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_csp_header_has_no_unsafe_eval_on_activity_pages(): void
    {
        foreach (['/activities', "/activities/{$this->activity->id}", '/activities/create'] as $uri) {
            $csp = (string) $this->signIn($this->coordA)->get($uri)->headers->get('Content-Security-Policy');

            $this->assertStringContainsString("script-src 'self'", $csp);
            $this->assertStringNotContainsString('unsafe-eval', $csp);
        }
    }

    public function test_the_editor_component_is_registered_in_the_bundle_source(): void
    {
        $source = (string) file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("Alpine.data('recurrenceEditor'", $source);
        // El build CSP de Alpine no usa eval ni new Function: no se reintroduce el build estandar.
        $this->assertDoesNotMatchRegularExpression('/\beval\s*\(|new\s+Function\s*\(/', $source);
        $this->assertStringNotContainsString("from 'alpinejs'", $source);
    }
}
