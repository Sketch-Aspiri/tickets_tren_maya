<?php

declare(strict_types=1);

namespace Tests\Feature\Tickets;

use App\Enums\Priority;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use Tests\Concerns\BuildsTicketScenario;
use Tests\DatabaseTestCase;

/**
 * Vistas compatibles con CSP (sin 'unsafe-eval'): Alpine build CSP, sin scripts en linea, sin
 * expresiones Alpine complejas y sin datos de usuario dentro de atributos x-*.
 */
class TicketViewsTest extends DatabaseTestCase
{
    use BuildsTicketScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    /**
     * @return array<string, string>
     */
    private function renderAllPages(): array
    {
        $category = Category::factory()->create();
        $ticket = Ticket::factory()->forTeam($this->teamA)->createdBy($this->empA1)->overdue()->assignedTo($this->empA1)
            ->create(['title' => '"><x-evil onload=alert(1)>', 'category_id' => $category->id]);
        $ticket->comments()->create(['body' => "'\"><script>alert(1)</script>", 'user_id' => $this->coordA->id]);
        Attachment::factory()->for($ticket, 'attachable')->create(['original_name' => '"onmouseover="alert(1).pdf', 'user_id' => $this->empA1->id]);
        $pendingUser = User::factory()->pending()->create();

        $pages = [];
        foreach ([[$this->jefe, 'jefe'], [$this->coordA, 'coord'], [$this->empA1, 'emp']] as [$actor, $label]) {
            foreach (['/dashboard', '/tickets', '/tickets/pending', '/tickets/create', "/tickets/{$ticket->id}", "/tickets/{$ticket->id}/edit"] as $uri) {
                $pages["{$label} {$uri}"] = $this->signIn($actor)->get($uri)->assertOk()->getContent();
            }
        }
        foreach (['/categories', '/categories/create', "/categories/{$category->id}/edit", '/teams', '/users', "/users/{$pendingUser->id}"] as $uri) {
            $pages["jefe {$uri}"] = $this->signIn($this->jefe)->get($uri)->assertOk()->getContent();
        }

        return $pages;
    }

    public function test_no_page_has_inline_scripts_or_event_handler_attributes(): void
    {
        foreach ($this->renderAllPages() as $name => $html) {
            preg_match_all('#<script\b([^>]*)>#i', $html, $scripts);
            foreach ($scripts[1] as $attributes) {
                $this->assertStringContainsString('src=', $attributes, "script en linea en {$name}");
            }

            // Solo dentro de etiquetas reales: el texto escapado ("&lt;x onload=...") no contiene "<".
            $this->assertDoesNotMatchRegularExpression('/<[a-z][^<>]*\son[a-z]+\s*=/i', preg_replace('#"[^"]*"#', '""', $html) ?? '', "manejador on* en {$name}");
        }
    }

    public function test_alpine_attributes_only_reference_registered_components_and_methods_by_name(): void
    {
        $seen = 0;

        foreach ($this->renderAllPages() as $name => $html) {
            preg_match_all('/\s(x-(?:data|show|on:[\w.:-]+|bind:[\w.:-]+|init|text|html|model|if|for|effect))="([^"]*)"/', $html, $matches, PREG_SET_ORDER);

            foreach ($matches as [$full, $directive, $value]) {
                $seen++;
                // Con el build CSP solo se admiten nombres simples (propiedades / metodos de Alpine.data).
                $this->assertMatchesRegularExpression('/^[A-Za-z_][A-Za-z0-9_]*$/', $value, "{$name}: {$directive}=\"{$value}\" no es un nombre simple");
                $this->assertNotSame('x-html', $directive, 'x-html inyectaria HTML sin escapar');
            }
        }

        $this->assertGreaterThan(50, $seen, 'se inspeccionaron atributos Alpine reales');
    }

    public function test_user_content_never_appears_inside_alpine_or_data_attributes(): void
    {
        foreach ($this->renderAllPages() as $name => $html) {
            preg_match_all('/\s(?:x-[\w:.-]+|data-[\w-]+)="([^"]*)"/', $html, $matches);

            foreach ($matches[1] as $value) {
                $this->assertStringNotContainsString('evil', $value, "dato de usuario en atributo de {$name}");
                $this->assertStringNotContainsString('alert(1)', $value, "dato de usuario en atributo de {$name}");
            }
        }
    }

    public function test_user_input_is_always_escaped_and_no_raw_blade_output_of_user_data(): void
    {
        $html = $this->renderAllPages()['jefe /tickets/'.Ticket::query()->firstOrFail()->id];

        $this->assertStringNotContainsString('<x-evil', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;x-evil onload=alert(1)&gt;', $html);
        $this->assertStringContainsString('&quot;onmouseover=&quot;alert(1).pdf', $html);
    }

    public function test_source_views_do_not_use_unescaped_output_for_ticket_content(): void
    {
        $offenders = [];

        foreach (['tickets', 'categories', 'components'] as $dir) {
            foreach (glob(resource_path("views/{$dir}/*.blade.php")) ?: [] as $file) {
                if (str_contains((string) file_get_contents($file), '{!!')) {
                    $offenders[] = basename($file);
                }
            }
        }

        $this->assertSame([], $offenders, 'las vistas de tickets/categorias/componentes no usan {!! !!}');
    }

    public function test_csp_header_has_no_unsafe_eval_on_ticket_pages(): void
    {
        $ticket = $this->makeTicket($this->teamA);

        foreach (['/tickets', "/tickets/{$ticket->id}"] as $uri) {
            $csp = (string) $this->signIn($this->jefe)->get($uri)->headers->get('Content-Security-Policy');

            $this->assertStringNotContainsString('unsafe-eval', $csp);
            $this->assertStringContainsString("script-src 'self'", $csp);
        }
    }

    public function test_layout_registers_the_csp_alpine_components_it_uses(): void
    {
        $js = (string) file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("from '@alpinejs/csp'", $js);
        $this->assertStringNotContainsString("from 'alpinejs'", $js);

        foreach (['sidebar', 'modal', 'modalTrigger', 'confirmSubmit', 'twoFactorChallenge'] as $component) {
            $this->assertStringContainsString("Alpine.data('{$component}'", $js);
        }
    }

    public function test_two_factor_challenge_and_layout_use_named_components(): void
    {
        $challenge = $this->get('/login')->getContent();
        $this->assertStringNotContainsString('x-data="{', $challenge);

        $dashboard = $this->signIn($this->empA1)->get('/dashboard')->getContent();
        $this->assertStringContainsString('x-data="sidebar"', $dashboard);
        $this->assertStringContainsString('x-bind:class="panelClass"', $dashboard);
    }

    public function test_ticket_status_and_priority_badges_show_text_not_only_color(): void
    {
        $ticket = Ticket::factory()->forTeam($this->teamA)->inReview()->priority(Priority::Urgent)->create();

        $this->signIn($this->jefe)->get("/tickets/{$ticket->id}")
            ->assertSee('En revisión')
            ->assertSee('Urgente');
    }

    public function test_team_with_tickets_cannot_be_deleted(): void
    {
        $team = Team::factory()->create();
        Ticket::factory()->forTeam($team)->create();
        $team->members()->detach();

        $this->signIn($this->jefe)->delete("/teams/{$team->id}")->assertSessionHas('error', __('teams.errors.has_tickets'));

        $this->assertDatabaseHas('teams', ['id' => $team->id]);
    }

    public function test_team_with_only_soft_deleted_tickets_still_cannot_be_deleted(): void
    {
        $team = Team::factory()->create();
        Ticket::factory()->forTeam($team)->create()->delete();
        $team->members()->detach();

        $this->signIn($this->jefe)->delete("/teams/{$team->id}")->assertSessionHas('error');

        $this->assertDatabaseHas('teams', ['id' => $team->id]);
    }
}
