<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpTokenTest extends TestCase
{
    use RefreshDatabase;

    private function registerUser(): array
    {
        $res = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'mcp@example.com',
            'password' => 'secret-password',
            'organization_name' => 'Test Org',
        ])->assertCreated();

        return [$res->json('token'), User::where('email', 'mcp@example.com')->firstOrFail()];
    }

    public function test_status_is_empty_until_issued(): void
    {
        [$token] = $this->registerUser();

        $this->withToken($token)
            ->getJson('/api/auth/mcp-token')
            ->assertOk()
            ->assertJson(['exists' => false]);
    }

    public function test_issue_returns_token_once_and_replaces_previous(): void
    {
        [$session, $user] = $this->registerUser();

        $first = $this->withToken($session)
            ->postJson('/api/auth/mcp-token')
            ->assertOk()
            ->assertJsonPath('shown_once', true);

        $plain = $first->json('token');
        $this->assertNotEmpty($plain);
        $this->assertSame($plain, $first->json('cursor_config.mcpServers.wwc.env.WWC_TOKEN'));
        $this->assertSame(1, $user->tokens()->where('name', 'mcp')->count());

        $this->withToken($session)
            ->getJson('/api/auth/mcp-token')
            ->assertOk()
            ->assertJson(['exists' => true]);

        $second = $this->withToken($session)
            ->postJson('/api/auth/mcp-token')
            ->assertOk();

        $this->assertNotSame($plain, $second->json('token'));
        $this->assertSame(1, $user->fresh()->tokens()->where('name', 'mcp')->count());
    }
}
