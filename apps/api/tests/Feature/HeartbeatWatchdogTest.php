<?php

namespace Tests\Feature;

use App\Models\AgentJob;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class HeartbeatWatchdogTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->org = Organization::create(['name' => 'Org', 'slug' => 'org']);
        $user = User::factory()->create(['current_organization_id' => $this->org->id]);
        Membership::create([
            'organization_id' => $this->org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
    }

    private function makeSite(array $attributes = []): Site
    {
        return Site::create(array_merge([
            'organization_id' => $this->org->id,
            'name' => 'Testsite',
            'url' => 'https://example.com',
            'status' => 'online',
            'paired_at' => now()->subDay(),
            'last_seen_at' => now(),
        ], $attributes));
    }

    public function test_stale_site_is_marked_offline(): void
    {
        $stale = $this->makeSite(['last_seen_at' => now()->subMinutes(30)]);
        $fresh = $this->makeSite(['url' => 'https://fresh.example.com', 'last_seen_at' => now()->subMinutes(2)]);

        $this->artisan('wwc:check-heartbeats')->assertSuccessful();

        $this->assertSame('offline', $stale->fresh()->status);
        $this->assertSame('online', $fresh->fresh()->status);
    }

    public function test_unpaired_site_is_ignored(): void
    {
        $site = $this->makeSite(['paired_at' => null, 'last_seen_at' => now()->subHours(5)]);

        $this->artisan('wwc:check-heartbeats')->assertSuccessful();

        $this->assertSame('online', $site->fresh()->status);
    }

    public function test_stuck_jobs_are_failed_by_watchdog(): void
    {
        $site = $this->makeSite();

        $stuck = AgentJob::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'command' => 'update_batch',
            'status' => 'running',
        ]);
        AgentJob::query()->whereKey($stuck->id)->update(['updated_at' => now()->subHours(3)]);

        $recent = AgentJob::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'command' => 'update_batch',
            'status' => 'running',
        ]);

        $this->artisan('wwc:check-heartbeats')->assertSuccessful();

        $this->assertSame('failed', $stuck->fresh()->status);
        $this->assertNotNull($stuck->fresh()->error);
        $this->assertSame('running', $recent->fresh()->status);
    }
}
