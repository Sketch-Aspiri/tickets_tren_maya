<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Priority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Category;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AssignmentService;
use App\Services\TicketService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

/**
 * Solo local (y pruebas), aunque se invoque directamente con `--class`. Idempotente por correo.
 * Los usuarios con rol jefe/coordinador deberan configurar 2FA en su primer inicio de sesion.
 */
class DemoDataSeeder extends Seeder
{
    /** Entornos donde se permiten cuentas demo con contrasena conocida. */
    public const ALLOWED_ENVIRONMENTS = ['local', 'testing'];

    private const TEAM_NAMES = ['Operaciones', 'Mantenimiento', 'Administracion'];

    private const EMPLOYEES_PER_TEAM = 3;

    private const CATEGORY_NAMES = ['Soporte técnico', 'Mantenimiento', 'Administrativo', 'Otros'];

    /** Prefijo que identifica los tickets demo (el bloque de tickets solo se crea si no existe ninguno). */
    private const DEMO_TICKET_PREFIX = '[Demo] ';

    private int $createdUsers = 0;

    public function run(): void
    {
        if (! app()->environment(self::ALLOWED_ENVIRONMENTS)) {
            $this->command?->warn('DemoDataSeeder solo corre en local. Crea el primer jefe de zona con: php artisan users:create-jefe correo@dominio');

            return;
        }

        [$password, $isGenerated] = $this->resolvePassword();
        $hash = Hash::make($password);
        $this->createdUsers = 0;

        $this->createUser('Jefe de Zona Demo', 'jefe@demo.test', $hash, UserRole::JefeZona, null);

        foreach (self::TEAM_NAMES as $index => $teamName) {
            $team = Team::query()->firstOrCreate(['name' => $teamName]);
            $slug = Str::slug($teamName);

            $coordinator = $this->createUser("Coordinador {$teamName}", "coordinador.{$slug}@demo.test", $hash, UserRole::Coordinador, $team);
            $team->update(['coordinator_id' => $coordinator->id]);

            foreach (range(1, self::EMPLOYEES_PER_TEAM) as $number) {
                $this->createUser("Empleado {$teamName} {$number}", "empleado{$number}.{$slug}@demo.test", $hash, UserRole::Empleado, $team);
            }

            if ($index === 0) {
                $this->createPending('Registro Pendiente Demo', 'pendiente@demo.test', $hash);
            }
        }

        $this->seedCategories();
        $this->seedTickets();

        $this->report($password, $isGenerated);
    }

    private function seedCategories(): void
    {
        foreach (self::CATEGORY_NAMES as $name) {
            Category::query()->firstOrCreate(['name' => $name], ['active' => true]);
        }
    }

    /**
     * Tickets de ejemplo en distintos estados y equipos, creados con los mismos servicios que usa la
     * aplicación (folio, historial y bitácora reales). Idempotente: si ya hay tickets demo no crea más.
     */
    private function seedTickets(): void
    {
        if (Ticket::query()->where('title', 'like', self::DEMO_TICKET_PREFIX.'%')->exists()) {
            return;
        }

        $categories = Category::query()->orderBy('id')->pluck('id')->all();

        foreach (self::TEAM_NAMES as $index => $teamName) {
            $slug = Str::slug($teamName);
            $coordinator = User::query()->where('email', "coordinador.{$slug}@demo.test")->first();
            $employees = User::query()
                ->whereIn('email', array_map(fn (int $n): string => "empleado{$n}.{$slug}@demo.test", range(1, self::EMPLOYEES_PER_TEAM)))
                ->orderBy('email')
                ->get()
                ->values();

            if ($coordinator === null || $employees->count() < self::EMPLOYEES_PER_TEAM) {
                continue;
            }

            $this->seedTeamTickets($teamName, $coordinator, $employees->all(), $categories[$index % max(count($categories), 1)] ?? null, $index === 0);
        }
    }

    /**
     * @param  list<User>  $employees
     */
    private function seedTeamTickets(string $teamName, User $coordinator, array $employees, ?int $categoryId, bool $withCancelled): void
    {
        $tickets = app(TicketService::class);
        $assignments = app(AssignmentService::class);
        [$first, $second, $third] = $employees;

        $make = fn (User $creator, string $title, Priority $priority, int $dueInDays): Ticket => $tickets->create($creator, [
            'title' => self::DEMO_TICKET_PREFIX."{$title} ({$teamName})",
            'description' => "Ticket de ejemplo para {$teamName}. Sirve para probar listados, filtros y flujos.",
            'priority' => $priority->value,
            'category_id' => $categoryId,
            'due_date' => now()->addDays($dueInDays)->toDateString(),
        ]);

        // 1. En la bolsa del equipo (sin asignar).
        $make($first, 'Revisar equipo de la oficina', Priority::Medium, 7);

        // 2. En proceso, asignado.
        $inProgress = $make($first, 'Instalar software solicitado', Priority::High, 3);
        $assignments->assign($coordinator, $inProgress, $second->getKey(), [$third->getKey()]);
        $tickets->transition($second, $inProgress, TicketStatus::InProgress);

        // 3. En revisión y vencido (fecha límite ya pasada).
        $overdue = $make($second, 'Entregar reporte semanal', Priority::Urgent, 1);
        $overdue->forceFill(['due_date' => now()->subDays(2)->toDateString()])->save();
        $assignments->assign($coordinator, $overdue, $third->getKey());
        $tickets->transition($third, $overdue, TicketStatus::InProgress);
        $tickets->transition($third, $overdue, TicketStatus::InReview, 'Listo para revisión');

        // 4. Completado.
        $done = $make($third, 'Actualizar inventario', Priority::Low, 10);
        $assignments->assign($coordinator, $done, $first->getKey());
        $tickets->transition($first, $done, TicketStatus::InProgress);
        $tickets->transition($first, $done, TicketStatus::InReview);
        $tickets->transition($coordinator, $done, TicketStatus::Completed, 'Aprobado');

        // 5. Cancelado (solo en el primer equipo).
        if ($withCancelled) {
            $cancelled = $make($second, 'Solicitud duplicada', Priority::Low, 5);
            $tickets->transition($coordinator, $cancelled, TicketStatus::Cancelled, 'Duplicado de otro ticket');
        }
    }

    /**
     * La contrasena configurada debe cumplir la misma politica que cualquier cuenta real.
     *
     * @return array{0: string, 1: bool} contrasena y si fue generada al azar
     */
    private function resolvePassword(): array
    {
        $configured = config('tickets.demo_password');

        if ($configured === null) {
            return [Str::password(16), true];
        }

        $validator = Validator::make(['password' => (string) $configured], ['password' => ['required', 'string', 'max:255', Password::defaults()]]);

        if ($validator->fails()) {
            throw new InvalidArgumentException('DEMO_USER_PASSWORD no cumple la politica de contrasenas: '.implode(' ', $validator->errors()->all()));
        }

        return [(string) $configured, false];
    }

    /**
     * Solo se imprime una contrasena que realmente se aplico a cuentas creadas en esta ejecucion.
     */
    private function report(string $password, bool $isGenerated): void
    {
        if ($this->createdUsers === 0) {
            $this->command?->info('Las cuentas demo ya existian; no se cambio ninguna contrasena.');

            return;
        }

        $this->command?->info($isGenerated
            ? "Contrasena generada para las {$this->createdUsers} cuentas demo nuevas (@demo.test): {$password}"
            : "Cuentas demo nuevas: {$this->createdUsers} (@demo.test). Contrasena tomada de DEMO_USER_PASSWORD.");
    }

    private function createUser(string $name, string $email, string $hash, UserRole $role, ?Team $team): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            return $user;
        }

        $user = new User(['name' => $name, 'email' => $email]);
        $user->forceFill([
            'password' => $hash,
            'status' => UserStatus::Active,
            'team_id' => $role->requiresTeam() ? $team?->id : null,
        ])->save();
        $user->assignRole($role->value);
        $this->createdUsers++;

        return $user;
    }

    private function createPending(string $name, string $email, string $hash): void
    {
        if (User::query()->where('email', $email)->exists()) {
            return;
        }

        $user = new User(['name' => $name, 'email' => $email]);
        $user->forceFill(['password' => $hash, 'status' => UserStatus::Pending])->save();
        $this->createdUsers++;
    }
}
