<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WpMcpTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $org = Organization::create(['name' => 'Haus', 'slug' => 'haus']);
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        Membership::create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->token = $user->createToken('t')->plainTextToken;
        $this->site = Site::create([
            'organization_id' => $org->id,
            'name' => 'Cuno',
            'url' => 'https://cuno.example',
            'status' => 'online',
        ]);
    }

    public function test_catalog_includes_wordpress_tools(): void
    {
        $names = $this->withToken($this->token)
            ->getJson('/api/mcp/tools')
            ->assertOk()
            ->json('tools.*.name');

        $this->assertContains('wwc_site_scan', $names);
        $this->assertContains('wwc_sites', $names);
        $this->assertContains('wwc_wp_call', $names);
    }

    public function test_lists_organization_sites(): void
    {
        $res = $this->withToken($this->token)
            ->postJson('/api/mcp/call', ['tool' => 'wwc_sites'])
            ->assertOk();

        $this->assertSame('Cuno', $res->json('data.0.name'));
        $this->assertFalse($res->json('data.0.live_paired'));
    }

    public function test_live_write_requires_confirm(): void
    {
        $this->site->setHmacSecret('secret');
        $this->site->paired_at = now();
        $this->site->save();

        $this->withToken($this->token)
            ->postJson('/api/mcp/call', [
                'tool' => 'wwc_wp_call',
                'site_id' => $this->site->id,
                'arguments' => [
                    'tool' => 'wp_seo_set',
                    'target' => 'live',
                    'arguments' => ['id' => 12, 'title' => 'Neu'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Live-Änderungen brauchen confirm_live=true.']);
    }

    public function test_live_read_calls_agent(): void
    {
        $this->site->setHmacSecret('secret');
        $this->site->paired_at = now();
        $this->site->save();

        Http::fake([
            'https://cuno.example/wp-json/wwc/v1/mcp' => Http::response([
                'ok' => true,
                'site' => ['name' => 'Cuno GmbH', 'agent_version' => '0.6.27'],
            ], 200),
        ]);

        $res = $this->withToken($this->token)
            ->postJson('/api/mcp/call', [
                'tool' => 'wwc_wp_call',
                'site_id' => $this->site->id,
                'arguments' => [
                    'tool' => 'wp_info',
                    'target' => 'live',
                ],
            ])
            ->assertOk();

        $this->assertSame('Cuno GmbH', $res->json('data.site.name'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/wp-json/wwc/v1/mcp')
            && $request['tool'] === 'wp_info');
    }
}
