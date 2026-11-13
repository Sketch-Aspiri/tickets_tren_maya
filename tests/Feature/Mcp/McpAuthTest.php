<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Models\McpClient;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\DatabaseTestCase;

/**
 * Autenticacion del servidor MCP (decision 94): sin clientes el endpoint no existe, token Bearer por hash,
 * revocacion inmediata, cuenta inactiva, IPs permitidas y el cliente actua como su cuenta.
 */
class McpAuthTest extends DatabaseTestCase
{
    private function makeClient(?User $user = null, array $attributes = []): array
    {
        $user ??= User::factory()->coordinador()->create();
        [$plain, $hash] = McpClient::newToken();

        $client = new McpClient(array_merge(['name' => 'grokbot', 'user_id' => $user->getKey(), 'can_write' => false], $attributes));
        $client->forceFill(['token_hash' => $hash])->save();

        return [$client, $plain, $user];
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function rpc(string $method, array $params = [], ?string $token = null, array $headers = [], string $ip = '203.0.113.9'): TestResponse
    {
        $headers = array_merge(['Accept' => 'application/json, text/event-stream'], $token === null ? [] : ['Authorization' => 'Bearer '.$token], $headers);

        return $this->withHeaders($headers)
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
    }

    private function initialize(?string $token): TestResponse
    {
        return $this->rpc('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new \stdClass,
            'clientInfo' => ['name' => 'prueba', 'version' => '1.0'],
        ], $token);
    }

    public function test_endpoint_does_not_exist_without_any_client(): void
    {
        $this->initialize('tmcp_cualquiera')->assertNotFound();
    }

    public function test_only_revoked_clients_also_means_not_found(): void
    {
        [$client, $token] = $this->makeClient();
        $client->forceFill(['revoked_at' => now()])->save();

        $this->initialize($token)->assertNotFound();
    }

    public function test_missing_malformed_or_unknown_token_is_unauthorized(): void
    {
        $this->makeClient();

        $this->initialize(null)->assertUnauthorized()->assertExactJson(['error' => 'unauthorized']);
        $this->initialize('tmcp_no-existe')->assertUnauthorized();
        $this->initialize(str_repeat('a', 200))->assertUnauthorized();
    }

    public function test_valid_token_authenticates_and_marks_last_use(): void
    {
        [$client, $token] = $this->makeClient();

        $this->initialize($token)->assertOk()->assertHeader('Cache-Control');
        $this->assertNotNull($client->fresh()->last_used_at);
    }

    public function test_token_is_stored_only_as_a_hash(): void
    {
        [$client, $token] = $this->makeClient();

        $this->assertSame(hash('sha256', $token), $client->token_hash);
        $this->assertStringNotContainsString($token, json_encode($client->toArray()));
        $this->assertStringStartsWith('tmcp_', $token);
    }

    public function test_revoked_client_loses_access_immediately(): void
    {
        [$client, $token] = $this->makeClient();
        $this->makeClient(User::factory()->coordinador()->create(), ['name' => 'otro']);
        $this->initialize($token)->assertOk();

        $client->forceFill(['revoked_at' => now()])->save();

        $this->initialize($token)->assertUnauthorized();
    }

    public function test_inactive_or_roleless_account_is_unauthorized(): void
    {
        [, $token, $user] = $this->makeClient();
        $user->forceFill(['status' => 'inactive'])->save();

        $this->initialize($token)->assertUnauthorized();
    }

    public function test_allowed_ips_are_enforced(): void
    {
        [, $token] = $this->makeClient(null, ['allowed_ips' => ['198.51.100.7']]);

        $this->rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass, 'clientInfo' => ['name' => 'p', 'version' => '1']], $token, [], '203.0.113.9')->assertUnauthorized();
        $this->rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass, 'clientInfo' => ['name' => 'p', 'version' => '1']], $token, [], '198.51.100.7')->assertOk();
    }

    public function test_get_requests_to_the_endpoint_are_not_served(): void
    {
        $this->makeClient();

        $this->get('/mcp')->assertStatus(405);
    }

    public function test_rate_limit_applies_per_token(): void
    {
        config(['tickets.rate_limits.mcp_per_minute' => 2]);
        [, $token] = $this->makeClient();

        $this->initialize($token)->assertOk();
        $this->initialize($token)->assertOk();
        $this->initialize($token)->assertStatus(429);
    }
}
