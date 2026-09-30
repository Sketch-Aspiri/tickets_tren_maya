<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Concerns\BuildsActivityScenario;
use Tests\DatabaseTestCase;

/** Asistente flotante Temayin: preguntas frecuentes precargadas, filtradas por rol. */
class AssistantTest extends DatabaseTestCase
{
    use BuildsActivityScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildScenario();
    }

    private function dashboardAs($user): string
    {
        return $this->signIn($user)->get('/dashboard')->assertOk()->getContent();
    }

    public function test_widget_shows_avatar_and_name_to_signed_in_users(): void
    {
        $html = $this->dashboardAs($this->empA1);

        $this->assertStringContainsString('temayin.webp', $html);
        $this->assertStringContainsString(e(__('assistant.avatar_alt')), $html);
        $this->assertStringContainsString(e(__('assistant.faqs.create_ticket.q')), $html);
    }

    public function test_widget_is_not_rendered_on_guest_pages(): void
    {
        $this->get('/login')->assertOk()->assertDontSee('temayin.webp');
    }

    public function test_employee_does_not_see_manager_or_admin_questions(): void
    {
        $html = $this->dashboardAs($this->empA1);

        $this->assertStringNotContainsString(e(__('assistant.faqs.assign.q')), $html);
        $this->assertStringNotContainsString(e(__('assistant.faqs.approve_users.q')), $html);
        $this->assertStringNotContainsString(e(__('assistant.faqs.audit.q')), $html);
    }

    public function test_coordinator_sees_manager_questions_but_not_admin_ones(): void
    {
        $html = $this->dashboardAs($this->coordA);

        $this->assertStringContainsString(e(__('assistant.faqs.assign.q')), $html);
        $this->assertStringNotContainsString(e(__('assistant.faqs.approve_users.q')), $html);
    }

    public function test_chief_sees_every_group(): void
    {
        $html = $this->dashboardAs($this->jefe);

        $this->assertStringContainsString(e(__('assistant.faqs.assign.q')), $html);
        $this->assertStringContainsString(e(__('assistant.faqs.approve_users.q')), $html);
    }

    public function test_widget_adds_no_inline_scripts_or_handlers(): void
    {
        $html = $this->dashboardAs($this->jefe);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html);
    }

    public function test_every_faq_declares_a_known_audience_and_texts(): void
    {
        foreach (__('assistant.faqs') as $id => $faq) {
            $this->assertContains($faq['audience'], ['all', 'manage', 'admin'], $id);
            $this->assertNotSame('', trim($faq['q']), $id);
            $this->assertNotSame('', trim($faq['a']), $id);
        }
    }
}
