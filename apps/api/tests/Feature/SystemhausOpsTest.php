<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemhausOpsTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private Organization $org;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Haus', 'slug' => 'haus']);
        $user = User::factory()->create(['current_organization_id' => $this->org->id]);
        Membership::create(['organization_id' => $this->org->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->token = $user->createToken('t')->plainTextToken;
        $client = Client::create(['organization_id' => $this->org->id, 'name' => 'Kunde']);
        $project = Project::create([
            'organization_id' => $this->org->id,
            'client_id' => $client->id,
            'name' => 'P1',
            'monthly_budget_cents' => 14900,
            'currency' => 'EUR',
            'active' => true,
            'scope' => ['hours_included' => 2, 'uptime' => true],
        ]);
        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'project_id' => $project->id,
            'name' => 'Shop',
            'url' => 'https://example.com',
            'status' => 'offline',
        ]);
    }

    public function test_ops_board_lists_offline_site(): void
    {
        $res = $this->withToken($this->token)->getJson('/api/dashboard')->assertOk();
        $this->assertGreaterThan(0, count($res->json('queue')));
        $this->assertSame(1, $res->json('sites_offline'));
    }

    public function test_time_entry_and_usage(): void
    {
        $this->withToken($this->token)->postJson('/api/time-entries', [
            'project_id' => $this->site->project_id,
            'minutes' => 90,
            'description' => 'Update',
        ])->assertCreated();

        $res = $this->withToken($this->token)->getJson('/api/time-entries')->assertOk();
        $this->assertSame(1.5, $res->json('usage.0.used_hours'));
    }

    public function test_team_invite_roundtrip(): void
    {
        $invite = $this->withToken($this->token)->postJson('/api/team/invites', [
            'email' => 'neu@example.com',
            'role' => 'technician',
        ])->assertCreated();

        $this->postJson('/api/auth/invite/accept', [
            'token' => $invite->json('data.token'),
            'name' => 'Neu',
            'password' => 'secret-password',
        ])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_freeze_blocks_updates(): void
    {
        $this->withToken($this->token)->postJson('/api/sites/'.$this->site->id.'/freeze', [
            'reason' => 'Black Friday',
        ])->assertOk();

        $this->site->refresh();
        $this->site->setHmacSecret('secret');
        $this->site->save();

        $this->withToken($this->token)->postJson('/api/sites/'.$this->site->id.'/commands', [
            'command' => 'update_core',
        ])->assertStatus(422);
    }
}
