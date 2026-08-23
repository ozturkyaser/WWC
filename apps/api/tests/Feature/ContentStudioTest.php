<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\ContentStudioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentStudioTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Site $site;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $org = Organization::create(['name' => 'Haus', 'slug' => 'haus']);
        $this->user = User::factory()->create(['current_organization_id' => $org->id]);
        Membership::create(['organization_id' => $org->id, 'user_id' => $this->user->id, 'role' => 'owner']);
        $this->token = $this->user->createToken('t')->plainTextToken;
        $this->site = Site::create([
            'organization_id' => $org->id,
            'name' => 'Cuno',
            'url' => 'https://cuno.example',
            'status' => 'active',
        ]);
    }

    public function test_plan_requires_scan(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/sites/'.$this->site->id.'/content-studio/plan', ['prompt' => 'Neue Landingpage'])
            ->assertStatus(422);
    }

    public function test_plan_creates_draft_from_prompt(): void
    {
        $this->site->update([
            'content_studio' => [
                'intel' => [
                    'ok' => true,
                    'site' => ['name' => 'Cuno'],
                    'pages' => [],
                    'plugins' => [],
                    'editors' => ['default' => 'gutenberg', 'builders' => []],
                ],
            ],
        ]);

        $res = $this->withToken($this->token)
            ->postJson('/api/sites/'.$this->site->id.'/content-studio/plan', [
                'prompt' => 'Erstelle eine Landingpage für Winterreifen',
            ])
            ->assertOk();

        $this->assertSame('planned', $res->json('data.draft.status'));
        $this->assertSame('create_post', $res->json('data.draft.ops.0.op'));
        $this->assertSame('page', $res->json('data.draft.ops.0.type'));
        $this->assertSame('publish', $res->json('data.draft.ops.0.status'));
    }

    public function test_run_requires_clone(): void
    {
        $this->site->update([
            'content_studio' => [
                'intel' => [
                    'ok' => true,
                    'site' => ['name' => 'Cuno'],
                    'pages' => [],
                    'plugins' => [],
                    'editors' => ['default' => 'gutenberg', 'builders' => []],
                ],
            ],
        ]);

        $this->withToken($this->token)
            ->postJson('/api/sites/'.$this->site->id.'/content-studio/run', [
                'prompt' => 'Neue Landingpage für Winterreifen',
            ])
            ->assertStatus(422);
    }

    public function test_publicize_url_rewrites_live_permalink_to_clone(): void
    {
        $this->site->update([
            'url' => 'https://cuno.example',
            'dev_clone' => ['status' => 'ready', 'url' => 'https://wwc.kiservicehub.de/clone/9123'],
        ]);

        $svc = app(ContentStudioService::class);
        $this->assertSame(
            'https://wwc.kiservicehub.de/clone/9123/winterreifen/',
            $svc->publicizeUrl($this->site, 'https://cuno.example/winterreifen/')
        );
        $this->assertSame(
            'https://wwc.kiservicehub.de/clone/9123/winterreifen/',
            $svc->publicizeUrl($this->site, 'https://wwc.kiservicehub.de/clone/9123/winterreifen/')
        );
    }

    public function test_apply_dev_requires_clone(): void
    {
        $this->site->update([
            'content_studio' => [
                'draft' => [
                    'status' => 'planned',
                    'ops' => [['op' => 'create_post', 'type' => 'page', 'title' => 'Test', 'content' => 'x', 'status' => 'draft']],
                ],
            ],
        ]);

        $this->withToken($this->token)
            ->postJson('/api/sites/'.$this->site->id.'/content-studio/apply-dev')
            ->assertStatus(422);
    }

    public function test_promote_requires_applied_dev(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/sites/'.$this->site->id.'/content-studio/promote')
            ->assertStatus(422);
    }

    public function test_sanitize_drops_unknown_ops(): void
    {
        $ops = app(ContentStudioService::class)->sanitizeOps([
            ['op' => 'rm -rf', 'path' => '/'],
            ['op' => 'set_option', 'key' => 'admin_email', 'value' => 'x'],
            ['op' => 'set_option', 'key' => 'blogname', 'value' => 'Neu'],
            ['op' => 'create_post', 'type' => 'page', 'title' => 'Hallo', 'content' => '<p>Hi</p>'],
        ]);

        $this->assertCount(2, $ops);
        $this->assertSame('set_option', $ops[0]['op']);
        $this->assertSame('blogname', $ops[0]['key']);
        $this->assertSame('create_post', $ops[1]['op']);
    }

    public function test_scan_and_mcp_require_clone(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/mcp/tools')
            ->assertOk()
            ->assertJsonPath('tools.0.name', 'wwc_site_scan');

        $this->withToken($this->token)
            ->postJson('/api/sites/'.$this->site->id.'/content-studio/scan')
            ->assertStatus(422);

        $this->withToken($this->token)
            ->postJson('/api/mcp/call', [
                'tool' => 'wwc_site_scan',
                'site_id' => $this->site->id,
            ])
            ->assertStatus(422);
    }
}
