<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Models\McpClient;
use App\Models\User;
use Spatie\Activitylog\Models\Activity as ActivityLogEntry;
use Tests\DatabaseTestCase;

/**
 * Comandos `mcp:cliente-crear` y `mcp:cliente-revocar` (decision 94) y la herramienta `whoami` por HTTP.
 */
class McpClientCommandsTest extends DatabaseTestCase
{
    public function test_create_shows_the_token_once_and_stores_only_its_hash(): void
    {
        $user = User::factory()->coordinador()->create();

        $this->artisan('mcp:cliente-crear', ['nombre' => 'grokbot', 'correo' => $user->email])
            ->expectsOutputToContain('tmcp_')
            ->assertSuccessful();

        $client = McpClient::query()->firstOrFail();
        $this->assertSame($user->getKey(), $client->user_id);
        $this->assertFalse($client->can_write);
        $this->assertSame(64, strlen($client->token_hash));
        $this->assertStringStartsNotWith('tmcp_', $client->token_hash);

        $entry = ActivityLogEntry::query()->where('log_name', 'mcp')->where('event', 'client_created')->firstOrFail();
        $this->assertStringNotContainsString('tmcp_', json_encode($entry->properties).json_encode($entry->attribute_changes));
    }

    public function test_create_with_write_flag_and_ips(): void
    {
        $user = User::factory()->coordinador()->create();

        $this->artisan('mcp:cliente-crear', ['nombre' => 'jefe', 'correo' => $user->email, '--escritura' => true, '--ip' => ['198.51.100.7']])
            ->assertSuccessful();

        $client = McpClient::query()->firstOrFail();
        $this->assertTrue($client->can_write);
        $this->assertSame(['198.51.100.7'], $client->allowed_ips);
    }

    public function test_create_rejects_bad_input(): void
    {
        $user = User::factory()->coordinador()->create();
        $inactive = User::factory()->coordinador()->inactive()->create();

        $this->artisan('mcp:cliente-crear', ['nombre' => 'a', 'correo' => 'nadie@ejemplo.test'])->assertFailed();
        $this->artisan('mcp:cliente-crear', ['nombre' => 'a', 'correo' => $inactive->email])->assertFailed();
        $this->artisan('mcp:cliente-crear', ['nombre' => 'a', 'correo' => $user->email, '--ip' => ['no-es-ip']])->assertFailed();
        $this->assertSame(0, McpClient::query()->count());

        $this->artisan('mcp:cliente-crear', ['nombre' => 'a', 'correo' => $user->email])->assertSuccessful();
        $this->artisan('mcp:cliente-crear', ['nombre' => 'a', 'correo' => $user->email])->assertFailed();
    }

    public function test_global_scope_accounts_need_an_explicit_force_flag(): void
    {
        $admin = User::factory()->administrador()->create();

        $this->artisan('mcp:cliente-crear', ['nombre' => 'a', 'correo' => $admin->email])->assertFailed();
        $this->assertSame(0, McpClient::query()->count());

        $this->artisan('mcp:cliente-crear', ['nombre' => 'a', 'correo' => $admin->email, '--forzar' => true])->assertSuccessful();
        $this->assertSame(1, McpClient::query()->count());
    }

    public function test_revoke_marks_the_client_and_audits(): void
    {
        $user = User::factory()->coordinador()->create();
        $this->artisan('mcp:cliente-crear', ['nombre' => 'grokbot', 'correo' => $user->email])->assertSuccessful();

        $this->artisan('mcp:cliente-revocar', ['nombre' => 'grokbot'])->assertSuccessful();

        $this->assertTrue(McpClient::query()->firstOrFail()->isRevoked());
        $this->assertTrue(ActivityLogEntry::query()->where('log_name', 'mcp')->where('event', 'client_revoked')->exists());
        $this->artisan('mcp:cliente-revocar', ['nombre' => 'no-existe'])->assertFailed();
    }

    public function test_whoami_tool_reports_the_acting_account(): void
    {
        $user = User::factory()->coordinador()->create();
        [$plain, $hash] = McpClient::newToken();
        (new McpClient(['name' => 'grokbot', 'user_id' => $user->getKey(), 'can_write' => false]))->forceFill(['token_hash' => $hash])->save();

        $response = $this->withHeaders([
            'Accept' => 'application/json, text/event-stream',
            'Authorization' => 'Bearer '.$plain,
            'MCP-Protocol-Version' => '2025-06-18',
        ])->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'quien_soy', 'arguments' => new \stdClass],
        ]);

        $response->assertOk();
        $this->assertStringContainsString('grokbot', $response->getContent());
        $this->assertStringContainsString($user->name, $response->getContent());
    }
}
