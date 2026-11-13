<?php

declare(strict_types=1);

namespace Tests\Feature\Teams;

use App\Enums\UserStatus;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketArrivedInPool;
use App\Services\TeamService;
use App\Services\WorkItemNotifier;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity as ActivityLogEntry;
use Tests\DatabaseTestCase;

/**
 * Varios coordinadores por equipo (decision 96): uno PRINCIPAL (`teams.coordinator_id`) y hasta N adicionales
 * (`team_coordinator`). Los adicionales cumplen el mismo requisito que el principal, no se repiten, comparten avisos y
 * pasan a principal si este deja de serlo.
 */
class MultipleCoordinatorsTest extends DatabaseTestCase
{
    private User $jefe;

    private Team $team;

    private User $principal;

    private User $second;

    private User $third;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::factory()->create(['name' => 'Equipo Multi']);
        $this->jefe = User::factory()->jefe()->create();
        $this->principal = User::factory()->coordinador()->create(['name' => 'Principal', 'team_id' => $this->team->id]);
        $this->second = User::factory()->coordinador()->create(['name' => 'Segundo', 'team_id' => $this->team->id]);
        $this->third = User::factory()->coordinador()->create(['name' => 'Tercero', 'team_id' => $this->team->id]);
        $this->team->update(['coordinator_id' => $this->principal->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'coordination_id' => $this->team->coordination_id,
            'name' => $this->team->name,
            'coordinator_id' => $this->principal->id,
        ];
    }

    private function update(array $payload): TestResponse
    {
        return $this->signIn($this->jefe)->put("/teams/{$this->team->id}", $payload);
    }

    public function test_a_boss_can_add_additional_coordinators_and_it_is_audited(): void
    {
        $this->update($this->payload(['additional_coordinator_ids' => [$this->second->id, $this->third->id]]))
            ->assertSessionHasNoErrors()->assertRedirect(route('teams.index'));

        $this->assertEqualsCanonicalizing([$this->second->id, $this->third->id], $this->team->additionalCoordinators()->pluck('users.id')->all());
        $this->assertSame($this->principal->id, $this->team->fresh()->coordinator_id);
        $this->assertSame(
            ['Principal', 'Segundo', 'Tercero'],
            $this->team->fresh()->load(['coordinator', 'additionalCoordinators'])->coordinators()->pluck('name')->all(),
        );
        $this->assertTrue(ActivityLogEntry::query()->where('log_name', 'teams')->where('event', 'coordinators_changed')->exists());
    }

    public function test_the_additional_coordinators_are_listed_in_the_team_views(): void
    {
        $this->team->additionalCoordinators()->attach($this->second->id);

        $this->signIn($this->jefe)->get('/teams')->assertOk()->assertSee('Principal, Segundo');
        $this->signIn($this->jefe)->get("/teams/{$this->team->id}")->assertOk()->assertSee('Principal, Segundo');
        $this->signIn($this->jefe)->get("/teams/{$this->team->id}/edit")->assertOk()
            ->assertSee('name="additional_coordinator_ids[]"', false)
            ->assertSee(__('teams.form.additional'));
    }

    public function test_additional_coordinators_can_be_removed_and_leaving_the_field_out_keeps_them(): void
    {
        $this->team->additionalCoordinators()->attach($this->second->id);

        // Sin el campo en la peticion (otra llamada al servicio): no se tocan.
        app(TeamService::class)->update($this->team, ['coordination_id' => $this->team->coordination_id, 'name' => $this->team->name, 'coordinator_id' => $this->principal->id]);
        $this->assertSame(1, $this->team->additionalCoordinators()->count());

        // Con la lista vacia desde el formulario: se quitan todos.
        $this->update($this->payload(['additional_coordinator_ids' => []]))->assertSessionHasNoErrors();
        $this->assertSame(0, $this->team->additionalCoordinators()->count());
    }

    public function test_invalid_additional_coordinators_are_rejected(): void
    {
        $outsider = User::factory()->coordinador()->create();                       // coordinador de OTRO equipo
        $employee = User::factory()->empleado()->create(['team_id' => $this->team->id]);
        $inactive = User::factory()->coordinador()->inactive()->create(['team_id' => $this->team->id]);

        foreach ([$outsider->id, $employee->id, $inactive->id, 999999] as $bad) {
            $this->update($this->payload(['additional_coordinator_ids' => [$bad]]))->assertSessionHasErrors('additional_coordinator_ids.0');
        }

        // El principal no se repite como adicional.
        $this->update($this->payload(['additional_coordinator_ids' => [$this->principal->id]]))
            ->assertSessionHasErrors('additional_coordinator_ids.0');

        // Sin principal no hay adicionales.
        $this->update($this->payload(['coordinator_id' => null, 'additional_coordinator_ids' => [$this->second->id]]))
            ->assertSessionHasErrors('coordinator_id');

        $this->assertSame(0, $this->team->additionalCoordinators()->count());
    }

    public function test_the_number_of_additional_coordinators_is_capped(): void
    {
        config(['tickets.max_additional_coordinators' => 1]);

        $this->update($this->payload(['additional_coordinator_ids' => [$this->second->id, $this->third->id]]))
            ->assertSessionHasErrors('additional_coordinator_ids');
    }

    public function test_only_people_who_manage_the_team_can_change_its_coordinators(): void
    {
        $this->signIn($this->principal)->put("/teams/{$this->team->id}", $this->payload(['additional_coordinator_ids' => [$this->second->id]]))->assertForbidden();
        $this->assertSame(0, $this->team->additionalCoordinators()->count());
    }

    public function test_every_coordinator_is_notified_when_a_ticket_arrives_in_the_pool(): void
    {
        $this->team->additionalCoordinators()->attach($this->second->id);
        $sender = User::factory()->empleado()->create();
        Notification::fake();

        $ticket = Ticket::factory()->forTeam($this->team)->createdBy($sender)->create();
        app(WorkItemNotifier::class)->arrivedInPool($sender, $ticket);

        Notification::assertSentTo([$this->principal, $this->second], TicketArrivedInPool::class);
        Notification::assertNotSentTo([$this->third, $sender], TicketArrivedInPool::class);
    }

    public function test_when_the_principal_stops_being_valid_the_first_additional_is_promoted(): void
    {
        $this->team->additionalCoordinators()->attach([$this->second->id, $this->third->id]);

        $this->principal->forceFill(['status' => UserStatus::Inactive])->save();
        app(TeamService::class)->releaseInvalidCoordination($this->principal);

        $team = $this->team->fresh();
        $this->assertSame($this->second->id, $team->coordinator_id);
        $this->assertSame([$this->third->id], $team->additionalCoordinators()->pluck('users.id')->all());
    }

    public function test_when_there_is_nobody_to_promote_the_team_is_left_without_principal(): void
    {
        $this->principal->forceFill(['status' => UserStatus::Inactive])->save();
        app(TeamService::class)->releaseInvalidCoordination($this->principal);

        $this->assertNull($this->team->fresh()->coordinator_id);
    }

    public function test_an_additional_coordinator_who_leaves_the_team_is_removed(): void
    {
        $this->team->additionalCoordinators()->attach($this->second->id);
        $other = Team::factory()->create();

        $this->signIn($this->jefe)->put("/users/{$this->second->id}", ['role' => 'coordinador', 'team_ids' => [$other->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->team->additionalCoordinators()->count());
        $this->assertSame($this->principal->id, $this->team->fresh()->coordinator_id);
    }

    public function test_an_inactive_additional_coordinator_does_not_count_as_a_coordinator(): void
    {
        $this->team->additionalCoordinators()->attach($this->second->id);
        $this->second->forceFill(['status' => UserStatus::Inactive])->save();

        $names = $this->team->fresh()->coordinators()->pluck('name')->all();

        $this->assertSame(['Principal'], $names);
    }
}
