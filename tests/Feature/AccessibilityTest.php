<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AssignmentRole;
use App\Enums\IncomingEmailStatus;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Activity;
use App\Models\Assignment;
use App\Models\Category;
use App\Models\ChatMessage;
use App\Models\Coordination;
use App\Models\CoordinationMailbox;
use App\Models\IncomingEmail;
use App\Models\SpreadsheetSource;
use App\Models\Station;
use App\Models\Subtask;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ChatService;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\DatabaseTestCase;

/**
 * Accesibilidad (WCAG 2.2): las preferencias se aplican antes de pintar con un archivo propio (CSP); cada ruta GET se
 * recorre por rol con datos de ejemplo revisando la estructura del HTML, y los formularios invalidos muestran cada
 * error en la pantalla.
 */
class AccessibilityTest extends DatabaseTestCase
{
    /** Rutas GET que no devuelven una pantalla: descargas, imagenes, JSON, puentes y redirecciones. */
    private const NOT_PAGES = [
        'activities.export', 'tickets.export', 'attachments.download', 'attachments.preview',
        'incoming-email-attachments.download', 'avatars.show', 'chat.list', 'chat.unread', 'chat.users',
        'chat.messages.index', 'notifications.summary', 'notifications.open', 'sesion.viva', 'sheet-sources.callback',
        'ecosistema.sso', 'ecosistema.entrar', 'ecosistema.salir', 'storage.local', 'interno.', 'interno.portal.',
    ];

    /** @var array<string, mixed> */
    private array $world;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('tickets.attachments.disk'));
        $this->world = $this->scenario();
    }

    public function test_guest_and_authenticated_pages_offer_the_panel_and_apply_the_preference_early(): void
    {
        $script = '<script src="'.asset('js/accesibilidad-inicial.js').'?v=2"></script>';

        $this->get(route('login'))->assertOk()
            ->assertSee($script, false)
            ->assertSee('x-data="panelAccesibilidad"', false);

        $this->signIn(User::factory()->administrador()->create())->get(route('dashboard'))->assertOk()
            ->assertSee($script, false)
            ->assertSee('x-data="panelAccesibilidad"', false)
            ->assertSee('x-on:click="abrir"', false)
            ->assertSee('x-on:change="cambiar"', false);
    }

    public function test_the_early_script_only_reads_the_saved_preference(): void
    {
        $source = (string) file_get_contents(public_path('js/accesibilidad-inicial.js'));

        $this->assertDoesNotMatchRegularExpression('/\b(eval|Function\(|innerHTML|document\.write)/', $source);
    }

    public function test_each_theme_selector_has_its_own_label(): void
    {
        $html = $this->signIn(User::factory()->administrador()->create())->get(route('dashboard'))->getContent();

        preg_match_all('/id="(theme-toggle-[A-Za-z0-9]+)"/', (string) $html, $matches);

        $this->assertCount(2, array_unique($matches[1]));
    }

    public function test_pages_have_a_descriptive_title_and_a_skip_link(): void
    {
        $html = (string) $this->get(route('login'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('~<title>[^<]+ · [^<]+</title>~u', $html);
        $this->assertStringContainsString('href="#contenido"', $html);
        $this->assertStringContainsString('id="contenido"', $html);
    }

    public function test_server_errors_reach_the_accessibility_script(): void
    {
        $html = (string) $this->from(route('login'))->followingRedirects()
            ->post(route('login'), ['email' => '', 'password' => ''])
            ->assertOk()
            ->getContent();

        $this->assertSame(1, preg_match('~data-errores="([A-Za-z0-9+/=]+)"~', $html, $match));
        $errors = json_decode((string) base64_decode($match[1], true), true);

        $this->assertContains('email', array_column((array) $errors, 'campo'));
    }

    public function test_only_a_signed_in_person_can_keep_the_session_alive(): void
    {
        $this->get(route('sesion.viva'))->assertRedirect(route('login'));

        // Con el token de la pagina abierta la sesion continua; si se rehizo (otro token), se avisa.
        $this->signIn(User::factory()->administrador()->create())
            ->withSession(['_token' => 'vigente'])->get(route('sesion.viva'), ['X-CSRF-TOKEN' => 'vigente'])->assertNoContent();
        $this->withSession(['_token' => 'nuevo'])->get(route('sesion.viva'), ['X-CSRF-TOKEN' => 'vigente'])->assertStatus(409);
    }

    public function test_every_page_has_an_accessible_structure_for_each_role(): void
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! in_array('GET', $route->methods(), true) || ! in_array('web', $route->gatherMiddleware(), true)
                || $name === '' && in_array($route->uri(), ['/', 'up'], true) || in_array($name, self::NOT_PAGES, true)
                || str_starts_with($name, 'interno.')) {
                continue;
            }

            $this->assertNotSame('', $name, "La ruta GET {$route->uri()} necesita nombre para revisarse.");
            $routes[$name] = $route->parameterNames();
        }

        $checked = [];
        $problems = [];

        foreach (['guest', 'pending', 'unconfirmed', 'enrolled', 'admin', 'boss', 'coordinator', 'employee'] as $role) {
            foreach ($routes as $name => $parameters) {
                foreach ($this->urlsFor($name, $parameters) as $url) {
                    $this->flushSession();
                    $this->app['auth']->forgetGuards();

                    $response = $this->as($role)->get($url);

                    if ($response->getStatusCode() !== 200 || ! str_contains((string) $response->headers->get('content-type'), 'text/html')) {
                        continue;
                    }

                    $checked[$name] = true;

                    foreach ($this->problems((string) $response->getContent()) as $problem) {
                        $problems[$problem.' ['.$name.']'] = "{$role} {$url}: {$problem}";
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_diff(array_keys($routes), array_keys($checked))), 'Rutas GET sin revisar: agrega sus datos o declara que no son pantallas.');
        $this->assertSame([], array_values($problems), implode("\n", $problems));
    }

    public function test_invalid_submissions_show_every_error_on_screen(): void
    {
        $w = $this->world;
        $long = str_repeat('a', 5001);
        $subtask = $w['subtask'];

        $cases = [
            ['guest', 'get', 'login', 'post', route('login'), []],
            ['guest', 'get', 'register', 'post', route('register'), []],
            ['guest', 'get', 'password.request', 'post', route('password.email'), []],
            ['employee', 'url', route('tickets.create'), 'post', route('tickets.store'), []],
            ['admin', 'url', route('tickets.edit', $w['ticket']), 'put', route('tickets.update', $w['ticket']), []],
            ['admin', 'url', route('tickets.show', $w['ticket']), 'put', route('tickets.assignments.update', $w['ticket']), []],
            ['employee', 'url', route('tickets.show', $w['ticket']), 'post', route('tickets.comments.store', $w['ticket']), []],
            ['employee', 'url', route('tickets.show', $w['ticket']), 'post', route('tickets.attachments.store', $w['ticket']), []],
            ['admin', 'url', route('activities.create'), 'post', route('activities.store'), []],
            ['admin', 'url', route('activities.edit', $w['activity']), 'put', route('activities.update', $w['activity']), []],
            ['admin', 'url', route('activities.show', $w['activity']), 'post', route('activities.subtasks.store', $w['activity']), []],
            ['admin', 'url', route('activities.show', $w['activity']), 'put', route('activities.subtasks.update', [$w['activity'], $subtask]), ['subtask_id' => $subtask->id, 'title' => '']],
            ['admin', 'url', route('categories.create'), 'post', route('categories.store'), []],
            ['admin', 'url', route('teams.create'), 'post', route('teams.store'), []],
            ['admin', 'url', route('teams.edit', $w['team']), 'put', route('teams.update', $w['team']), []],
            ['admin', 'url', route('stations.create'), 'post', route('stations.store'), []],
            ['admin', 'url', route('coordinations.create'), 'post', route('coordinations.store'), []],
            ['admin', 'url', route('mailboxes.edit', $w['coordination']), 'put', route('mailboxes.update', $w['coordination']), []],
            ['admin', 'url', route('sheet-sources.edit', $w['coordination']), 'put', route('sheet-sources.update', $w['coordination']), []],
            ['admin', 'url', route('inbox-settings.index'), 'post', route('sheet-sources.upload', $w['coordination']), ['upload_for' => $w['coordination']->id]],
            ['admin', 'url', route('users.show', $w['pending']), 'post', route('users.approve', $w['pending']), []],
            ['admin', 'url', route('users.show', $w['pending']), 'post', route('users.reject', $w['pending']), ['reason' => $long]],
            ['admin', 'url', route('users.show', $w['employee']), 'put', route('users.update', $w['employee']), []],
            ['admin', 'url', route('users.show', $w['employee']), 'post', route('users.deactivate', $w['employee']), ['reason' => $long]],
            ['admin', 'url', route('incoming-emails.show', $w['email']), 'post', route('incoming-emails.discard', $w['email']), []],
            ['admin', 'url', route('incoming-emails.convert', $w['email']), 'post', route('incoming-emails.convert.store', $w['email']), []],
            ['employee', 'url', route('profile.edit'), 'patch', route('profile.update'), []],
            ['employee', 'url', route('profile.edit'), 'put', route('password.update'), []],
            ['employee', 'url', route('profile.edit'), 'post', route('profile.avatar.update'), []],
            ['admin', 'url', route('profile.edit'), 'post', route('two-factor.recovery-codes'), []],
        ];

        foreach ($cases as [$role, $kind, $from, $method, $action, $data]) {
            $this->flushSession();
            $this->app['auth']->forgetGuards();
            $page = $kind === 'get' ? route($from) : $from;

            $response = $this->as($role)->from($page)->followingRedirects()->{$method}($action, $data);

            $this->assertErrorsVisible($response, "{$method} {$action}");
        }
    }

    public function test_a_rejected_transition_reopens_its_block_with_the_message_and_the_text(): void
    {
        $ticket = $this->world['reviewTicket'];
        $comment = str_repeat('x', (int) config('tickets.comment_max_length') + 1);

        $response = $this->as('admin')->from(route('tickets.show', $ticket))->followingRedirects()
            ->post(route('tickets.transition', $ticket), ['status' => TicketStatus::InProgress->value, 'comment' => $comment]);

        $this->assertErrorsVisible($response, 'transicion');
        $xpath = $this->xpath((string) $response->getContent());
        $this->assertSame(1, $xpath->query('//details[@open]//textarea[@id="transition_reject_comment"]')->length);
        $this->assertSame(1, $xpath->query('//details[@open]')->length);
        $this->assertSame($comment, trim((string) $xpath->query('//textarea[@id="transition_reject_comment"]')->item(0)?->textContent));
    }

    public function test_dialogs_reopen_after_an_error(): void
    {
        $cases = [
            [route('users.show', $this->world['pending']), route('users.reject', $this->world['pending']), 'reject-user'],
            [route('users.show', $this->world['employee']), route('users.deactivate', $this->world['employee']), 'deactivate-user'],
            [route('incoming-emails.show', $this->world['email']), route('incoming-emails.discard', $this->world['email']), 'discard-email'],
        ];

        foreach ($cases as [$page, $action, $modal]) {
            $closed = $this->xpath((string) $this->as('admin')->get($page)->getContent());
            $this->assertSame('0', $closed->query("//div[@data-name='{$modal}']")->item(0)?->getAttribute('data-show'), $modal);

            $response = $this->as('admin')->from($page)->followingRedirects()->post($action, ['reason' => str_repeat('a', 1001)]);
            $open = $this->xpath((string) $response->getContent());

            $this->assertSame('1', $open->query("//div[@data-name='{$modal}']")->item(0)?->getAttribute('data-show'), $modal);
        }
    }

    public function test_a_subtask_error_stays_with_the_edited_subtask(): void
    {
        [$activity, $subtask] = [$this->world['activity'], $this->world['subtask']];

        $response = $this->as('admin')->from(route('activities.show', $activity))->followingRedirects()
            ->put(route('activities.subtasks.update', [$activity, $subtask]), ['subtask_id' => $subtask->id, 'title' => str_repeat('a', 256)]);

        $xpath = $this->xpath((string) $response->getContent());
        $this->assertSame(1, $xpath->query("//details[@open]//input[@id='subtask_title_{$subtask->id}']")->length);
        $this->assertSame(1, $xpath->query("//details[@open]//li[contains(., '255')]")->length);
        $this->assertSame('', $xpath->query('//input[@id="new_subtask_title"]')->item(0)?->getAttribute('value'));
        $this->assertSame(0, $xpath->query('//input[@id="new_subtask_title"]/following-sibling::ul')->length);
    }

    public function test_chart_colors_contrast_with_both_surfaces(): void
    {
        $source = (string) file_get_contents(resource_path('js/charts.js'));

        $this->assertStringContainsString("return isDarkTheme() ? '#12201D' : '#FFFFFF';", $source);
        $this->assertSame(1, preg_match('~const SERIES = \{(.*?)\n\};~s', $source, $series));
        $this->assertSame(1, preg_match('~const PALETTES = \{(.*?)\n\};~s', $source, $palettes));

        foreach ([$series[1], $palettes[1]] as $block) {
            [$light, $dark] = explode('dark:', $block, 2);

            foreach (['#FFFFFF' => $light, '#12201D' => $dark] as $surface => $colors) {
                preg_match_all('~#[0-9A-Fa-f]{6}~', $colors, $found);
                $this->assertNotEmpty($found[0]);

                foreach ($found[0] as $color) {
                    $this->assertGreaterThanOrEqual(3.0, $this->contrast($color, $surface), "{$color} sobre {$surface}");
                }
            }
        }
    }

    private function contrast(string $one, string $other): float
    {
        $luminance = function (string $hex): float {
            [$r, $g, $b] = array_map(function (string $part): float {
                $channel = hexdec($part) / 255;

                return $channel <= 0.03928 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
            }, str_split(ltrim($hex, '#'), 2));

            return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
        };
        $values = [$luminance($one), $luminance($other)];

        return (max($values) + 0.05) / (min($values) + 0.05);
    }

    private function assertErrorsVisible(TestResponse $response, string $context): void
    {
        $response->assertOk();
        $html = (string) $response->getContent();

        $this->assertSame(1, preg_match('~data-errores="([A-Za-z0-9+/=]+)"~', $html, $match), $context);
        $errors = (array) json_decode((string) base64_decode($match[1], true), true);
        $this->assertNotEmpty($errors, "{$context}: el envio no produjo errores");

        $xpath = $this->xpath($html);
        // Lo que queda plegado u oculto no cuenta como visible.
        $hidden = "//details[not(@open)]/*[not(self::summary)] | //div[@data-show='0'] | //template | //script";
        foreach (iterator_to_array($xpath->query($hidden)) as $node) {
            $node->parentNode?->removeChild($node);
        }
        $visible = $this->squash((string) $xpath->query('//body')->item(0)?->textContent);

        foreach ($errors as $error) {
            $this->assertStringContainsString($this->squash($error['mensaje']), $visible, "{$context}: no se ve el error de «{$error['campo']}»");
        }

        $this->assertSame([], $this->problems($html), $context);
    }

    private function as(string $role): static
    {
        if ($role === 'guest') {
            return $this;
        }

        $user = $this->world[$role];

        // Sin el desafio 2FA superado, para ver las pantallas del propio 2FA.
        return in_array($role, ['unconfirmed', 'enrolled', 'pending'], true) ? $this->actingAs($user) : $this->signIn($user);
    }

    /**
     * @param  list<string>  $parameters
     * @return list<string>
     */
    private function urlsFor(string $name, array $parameters): array
    {
        $w = $this->world;
        $values = [
            'tickets.show' => [$w['ticket'], $w['reviewTicket'], $w['poolTicket'], $w['closedTicket']],
            'tickets.edit' => [$w['ticket']],
            'activities.show' => [$w['activity'], $w['template']],
            'activities.edit' => [$w['activity'], $w['template']],
            'users.show' => [$w['employee'], $w['pending'], $w['boss']],
            'teams.show' => [$w['team']], 'teams.edit' => [$w['team']],
            'stations.show' => [$w['station']], 'stations.edit' => [$w['station']],
            'coordinations.show' => [$w['coordination']], 'coordinations.edit' => [$w['coordination']],
            'mailboxes.edit' => [$w['coordination']], 'sheet-sources.edit' => [$w['coordination']],
            'categories.edit' => [$w['category']],
            'incoming-emails.show' => [$w['email'], $w['discarded']],
            'incoming-emails.convert' => [$w['email']],
            'chat.show' => [$w['conversation']],
            'password.reset' => ['token-de-ejemplo'],
        ];

        if ($parameters !== []) {
            $this->assertArrayHasKey($name, $values, "Falta un registro de ejemplo para la ruta {$name}.");

            return array_map(fn (mixed $value): string => route($name, $value), $values[$name]);
        }

        return match ($name) {
            'users.index' => [route($name), route($name, ['status' => 'pending'])],
            'tickets.index' => [route($name), route($name, ['sent' => 1])],
            'tickets.pending' => [route($name), route($name, ['scope' => 'team', 'due_today' => 1])],
            'activities.index' => [route($name), route($name, ['due_today' => 1])],
            'activities.templates.index' => [route($name), route($name, ['trashed' => 1])],
            'incoming-emails.index' => [route($name), route($name, ['status' => 'all'])],
            default => [route($name)],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function scenario(): array
    {
        $station = Station::factory()->create();
        $coordination = Coordination::factory()->for($station)->create();
        $team = Team::factory()->for($coordination)->create();
        $admin = User::factory()->administrador()->create();
        $boss = User::factory()->jefeOf($coordination)->create();
        $coordinator = User::factory()->coordinador()->create(['team_id' => $team->id]);
        $team->forceFill(['coordinator_id' => $coordinator->id])->save();
        $employee = User::factory()->empleado()->create(['team_id' => $team->id]);
        $pending = User::factory()->pending()->create();
        // Administradores sin 2FA configurado (pantalla de alta) y con 2FA sin superar (desafio).
        $unconfirmed = User::factory()->active()->withRole(UserRole::Administrador)->create();
        $enrolled = User::factory()->administrador()->create();
        $category = Category::factory()->create(['coordination_id' => $coordination->id]);

        $ticket = Ticket::factory()->forTeam($team)->inProgress()->overdue()->assignedTo($employee)->create(['created_by' => $employee->id]);
        $reviewTicket = Ticket::factory()->forTeam($team)->inReview()->assignedTo($employee)->create(['created_by' => $employee->id]);
        $poolTicket = Ticket::factory()->forTeam($team)->create(['created_by' => $employee->id]);
        $closedTicket = Ticket::factory()->forTeam($team)->completed()->assignedTo($employee)->create(['created_by' => $employee->id]);
        $ticket->comments()->create(['body' => 'Comentario de ejemplo', 'user_id' => $employee->id]);
        $ticket->statusHistories()->create(['from_status' => null, 'to_status' => TicketStatus::Pending, 'user_id' => $employee->id]);
        foreach (['foto.png' => 'image/png', 'nota.pdf' => 'application/pdf'] as $file => $mime) {
            $ticket->attachments()->forceCreate(['attachable_type' => $ticket->getMorphClass(), 'attachable_id' => $ticket->id, 'user_id' => $employee->id, 'original_name' => $file, 'path' => "tickets/{$ticket->id}/{$file}", 'mime' => $mime, 'size' => 2048]);
        }

        $activity = Activity::factory()->forTeam($team)->inProgress()->overdue()->createdBy($coordinator)->assignedTo($employee)->create();
        $activity->assignments()->save(new Assignment(['user_id' => $coordinator->id, 'role' => AssignmentRole::Colaborador]));
        $subtask = Subtask::factory()->create(['activity_id' => $activity->id, 'assigned_to' => $employee->id]);
        Subtask::factory()->create(['activity_id' => $activity->id]);
        $template = Activity::factory()->forTeam($team)->createdBy($coordinator)->recurring()->create();
        Activity::factory()->forTeam($team)->createdBy($coordinator)->recurring()->create()->delete();

        $email = $this->email($coordination, 'uno', IncomingEmailStatus::PendingReview);
        $discarded = $this->email($coordination, 'dos', IncomingEmailStatus::Discarded);

        CoordinationMailbox::query()->forceCreate(['coordination_id' => $coordination->id, 'host' => 'imap.ejemplo.mx', 'port' => 993, 'encryption' => 'ssl', 'username' => 'buzon@ejemplo.mx', 'password' => 'secreta', 'folder' => 'INBOX', 'active' => true]);
        SpreadsheetSource::query()->forceCreate(['coordination_id' => $coordination->id, 'name' => 'Solicitudes', 'share_url' => '', 'header_row' => 1, 'subject_column' => 'Asunto', 'body_columns' => ['Detalle'], 'active' => true]);

        $chat = app(ChatService::class);
        $conversation = $chat->startDirect($coordinator, $employee);
        $chat->send($coordinator, $conversation, 'Buenos dias', null);
        $message = ChatMessage::query()->firstOrFail();
        $message->attachments()->forceCreate(['attachable_type' => $message->getMorphClass(), 'attachable_id' => $message->id, 'user_id' => $coordinator->id, 'original_name' => 'plano.png', 'path' => 'chat/plano.png', 'mime' => 'image/png', 'size' => 2048]);

        return compact('station', 'coordination', 'team', 'admin', 'boss', 'coordinator', 'employee', 'pending', 'unconfirmed', 'enrolled', 'category',
            'ticket', 'reviewTicket', 'poolTicket', 'closedTicket', 'activity', 'subtask', 'template', 'email', 'discarded', 'conversation');
    }

    private function email(Coordination $coordination, string $key, IncomingEmailStatus $status): IncomingEmail
    {
        $email = new IncomingEmail([
            'coordination_id' => $coordination->id, 'message_id' => "{$key}@ejemplo.mx", 'from_email' => 'proveedor@ejemplo.mx',
            'from_name' => 'Proveedor', 'subject' => "Asunto {$key}", 'body' => 'Cuerpo del correo', 'received_at' => now(),
        ]);
        $email->forceFill(['status' => $status, 'discard_reason' => $status === IncomingEmailStatus::Discarded ? 'Duplicado' : null])->save();

        return $email;
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Nombre accesible aproximado de un enlace, boton o elemento: texto no oculto, aria-label, alt o title. */
    private function accessibleName(DOMElement $element, DOMXPath $xpath): string
    {
        foreach (['aria-label'] as $attribute) {
            if (trim($element->getAttribute($attribute)) !== '') {
                return $this->squash($element->getAttribute($attribute));
            }
        }

        if ($element->hasAttribute('aria-labelledby')) {
            $parts = [];
            foreach (preg_split('/\s+/', trim($element->getAttribute('aria-labelledby'))) ?: [] as $id) {
                $parts[] = (string) $xpath->query("//*[@id='{$id}']")->item(0)?->textContent;
            }

            return $this->squash(implode(' ', $parts));
        }

        $text = $this->squash($this->text($element));

        return $text !== '' ? $text : $this->squash($element->getAttribute('title'));
    }

    private function text(DOMNode $node): string
    {
        if ($node instanceof DOMElement) {
            if ($node->getAttribute('aria-hidden') === 'true') {
                return '';
            }
            if ($node->nodeName === 'img') {
                return ' '.$node->getAttribute('alt').' ';
            }
            // Texto que pone Alpine.
            if ($node->hasAttribute('x-text')) {
                return ' {x-text} ';
            }
        }

        if (! $node->hasChildNodes()) {
            return $node->nodeType === XML_TEXT_NODE ? (string) $node->textContent : '';
        }

        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= $this->text($child);
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    private function problems(string $html): array
    {
        $x = $this->xpath($html);
        $problems = [];
        $outside = '[not(ancestor::template)]';
        $label = fn (DOMElement $e): string => $e->nodeName
            .($e->getAttribute('id') !== '' ? '#'.$e->getAttribute('id') : '')
            .($e->getAttribute('name') !== '' ? '[name='.$e->getAttribute('name').']' : '')
            .($e->getAttribute('href') !== '' ? '[href='.$e->getAttribute('href').']' : '');

        if ($x->query('/html')->item(0)?->getAttribute('lang') !== 'es') {
            $problems[] = 'el documento no declara lang="es"';
        }
        if (! str_contains((string) $x->query('//head/title')->item(0)?->textContent, ' · ')) {
            $problems[] = 'la pantalla no tiene un titulo propio';
        }
        if (($count = $x->query("//h1{$outside}")->length) !== 1) {
            $problems[] = "hay {$count} encabezados h1";
        }

        // Sin saltos de nivel dentro del contenido principal.
        $level = 1;
        foreach ($x->query("//main//*[self::h2 or self::h3 or self::h4][not(ancestor::*[@role='dialog'])]{$outside}") as $heading) {
            $current = (int) substr($heading->nodeName, 1);
            if ($current > $level + 1) {
                $problems[] = "salto de encabezado a {$heading->nodeName}: ".$this->squash($heading->textContent);
            }
            $level = $current;
        }

        $ids = [];
        foreach ($x->query("//*[@id]{$outside}") as $element) {
            $ids[$element->getAttribute('id')] = ($ids[$element->getAttribute('id')] ?? 0) + 1;
        }
        foreach (array_filter($ids, fn (int $times): bool => $times > 1) as $id => $times) {
            $problems[] = "id repetido: {$id}";
        }

        foreach (['aria-describedby', 'aria-labelledby', 'aria-controls', 'aria-errormessage', 'for', 'list'] as $attribute) {
            foreach ($x->query("//*[@{$attribute}]{$outside}") as $element) {
                foreach (preg_split('/\s+/', trim($element->getAttribute($attribute)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $id) {
                    if (! isset($ids[$id])) {
                        $problems[] = "{$attribute} apunta a un id que no existe: {$id}";
                    }
                }
            }
        }

        $controls = "//*[self::select or self::textarea or (self::input and not(@type='hidden' or @type='submit' or @type='button'))]{$outside}";
        foreach ($x->query($controls) as $control) {
            $id = $control->getAttribute('id');
            $labels = $x->query($id !== '' ? "//label[@for='{$id}'] | ancestor::label" : 'ancestor::label', $control);
            $text = '';
            $marked = false;
            foreach ($labels as $found) {
                $text .= $this->text($found);
                $marked = $marked || str_contains($found->textContent, '*') || str_contains($found->textContent, 'obligatorio');
            }

            if (trim($text) === '' && trim($control->getAttribute('aria-label')) === '' && ! $control->hasAttribute('aria-labelledby')) {
                $problems[] = 'control sin nombre: '.$label($control);
            }
            if ($control->hasAttribute('required') && ! $marked) {
                $problems[] = 'campo obligatorio sin indicarlo: '.$label($control);
            }
        }

        foreach ($x->query("//*[self::a[@href] or self::button or self::summary]{$outside}") as $element) {
            if ($this->accessibleName($element, $x) === '') {
                $problems[] = 'enlace o boton sin nombre: '.$label($element);
            }
        }

        // Dentro de tablas y listas de registros, un mismo nombre no puede llevar a sitios distintos.
        $seen = [];
        $rows = "//tbody//*[self::a[@href] or self::button] | //*[@id='subtareas' or @id='adjuntos']//*[self::button or self::summary]";
        foreach ($x->query($rows) as $element) {
            $name = $this->accessibleName($element, $x);
            $form = $x->query('ancestor::form', $element)->item(0);
            $target = $element->getAttribute('href') ?: ($form instanceof DOMElement ? $form->getAttribute('action') : $element->getNodePath());
            if (isset($seen[$name]) && $seen[$name] !== $target) {
                $problems[] = "nombre repetido para destinos distintos: {$name}";
            }
            $seen[$name] = $target;
        }

        foreach ($x->query("//img{$outside}") as $image) {
            if (! $image->hasAttribute('alt')) {
                $problems[] = 'imagen sin alt: '.$image->getAttribute('src');
            }
        }
        foreach ($x->query("//th[not(@scope)]{$outside}") as $header) {
            $problems[] = 'th sin scope: '.$this->squash($header->textContent);
        }
        foreach ($x->query("//table[not(caption) and not(@aria-label) and not(@aria-labelledby)]{$outside}") as $table) {
            $problems[] = 'tabla sin nombre: '.$this->squash((string) $x->query('.//th', $table)->item(0)?->textContent);
        }
        foreach ($x->query("//*[@role='dialog' or @role='alertdialog' or @role='log' or @role='img' or @role='group' or @role='region']{$outside}") as $element) {
            if ($element->nodeName !== 'svg' && $element->getAttribute('aria-label') === '' && ! $element->hasAttribute('aria-labelledby')) {
                $problems[] = 'region sin nombre: '.$element->getAttribute('role');
            }
        }

        // Una columna oculta en pantallas angostas (o con zoom) repite su dato en la misma fila.
        foreach ($x->query("//tbody/tr{$outside}") as $row) {
            foreach ($x->query('td', $row) as $cell) {
                if (preg_match('/(?:^|\s)hidden\s(?:.*\s)?(sm|md|lg|xl):table-cell/', $cell->getAttribute('class'), $match) !== 1) {
                    continue;
                }
                $alternative = "*//*[contains(concat(' ', normalize-space(@class), ' '), ' {$match[1]}:hidden ')]";
                $text = $this->squash($cell->textContent);
                // O el mismo texto ya se ve en otra celda que no se oculta.
                $elsewhere = false;
                foreach ($x->query("*[not(contains(concat(' ', normalize-space(@class), ' '), ' hidden '))]", $row) as $other) {
                    $elsewhere = $elsewhere || str_contains($this->squash($other->textContent), $text);
                }
                if (! $elsewhere && $x->query($alternative, $row)->length === 0) {
                    $problems[] = "columna oculta hasta {$match[1]} sin alternativa: {$text}";
                }
            }
        }

        return array_values(array_unique($problems));
    }
}
