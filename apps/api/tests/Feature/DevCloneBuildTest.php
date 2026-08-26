<?php

namespace Tests\Feature;

use App\Jobs\BuildDevCloneJob;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Site;
use App\Services\DevCloneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DevCloneBuildTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_requires_backup_or_pairing(): void
    {
        [$user, $site] = $this->site();

        $this->withToken($user->createToken('t')->plainTextToken)
            ->postJson('/api/sites/'.$site->id.'/dev-clone')
            ->assertStatus(422);
    }

    public function test_create_dispatches_when_paired_without_server_backup(): void
    {
        Queue::fake();
        [$user, $site] = $this->site();
        $site->setHmacSecret('secret');
        $site->paired_at = now();
        $site->save();

        $this->withToken($user->createToken('t')->plainTextToken)
            ->postJson('/api/sites/'.$site->id.'/dev-clone')
            ->assertStatus(202);

        Queue::assertPushed(BuildDevCloneJob::class, fn (BuildDevCloneJob $job) => $job->siteId === $site->id);
        $this->assertSame('building', $site->fresh()->dev_clone['status']);
    }

    public function test_stale_building_can_be_restarted(): void
    {
        Queue::fake();
        [$user, $site] = $this->site();
        $site->setHmacSecret('secret');
        $site->paired_at = now();
        $site->dev_clone = ['status' => 'building', 'message' => 'Backup vom Kundenserver holen 20/92: files-17.zip'];
        $site->save();

        $this->withToken($user->createToken('t')->plainTextToken)
            ->postJson('/api/sites/'.$site->id.'/dev-clone')
            ->assertStatus(202);

        Queue::assertPushed(BuildDevCloneJob::class);
    }

    public function test_clone_url_uses_public_host_outside_local(): void
    {
        $this->app['env'] = 'production';
        config([
            'wwc.clone_base_url' => 'http://localhost',
            'wwc.public_api_url' => 'http://localhost:8081',
            'wwc.portal_url' => 'https://wwc.kiservicehub.de',
            'app.url' => 'http://localhost:8081',
        ]);

        $this->assertSame(
            'https://wwc.kiservicehub.de/clone/9123',
            app(DevCloneService::class)->clonePublicUrl(9123)
        );
    }

    public function test_clone_url_keeps_explicit_lan_host(): void
    {
        config(['wwc.clone_base_url' => 'http://192.168.1.248']);

        $this->assertSame(
            'http://192.168.1.248:9100',
            app(DevCloneService::class)->clonePublicUrl(9100)
        );
    }

    public function test_old_cloudflare_port_url_needs_repair(): void
    {
        $svc = app(DevCloneService::class);
        $this->assertTrue($svc->urlNeedsCloudflareRepair('http://wwc.kiservicehub.de:9123'));
        $this->assertFalse($svc->urlNeedsCloudflareRepair('https://wwc.kiservicehub.de/clone/9123'));
    }

    public function test_clone_url_keeps_localhost_in_local(): void
    {
        $this->app['env'] = 'local';
        config([
            'wwc.clone_base_url' => 'http://localhost',
            'wwc.portal_url' => 'http://localhost:3000',
        ]);

        $this->assertSame(
            'http://localhost:3000/clone/9100',
            app(DevCloneService::class)->clonePublicUrl(9100)
        );
    }

    /** @return array{0:\App\Models\User,1:Site} */
    private function site(): array
    {
        $org = Organization::create(['name' => 'Haus', 'slug' => 'haus']);
        $user = \App\Models\User::factory()->create(['current_organization_id' => $org->id]);
        Membership::create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => 'owner']);
        $site = Site::create([
            'organization_id' => $org->id,
            'name' => 'Cuno',
            'url' => 'https://cuno.example',
            'status' => 'pending',
        ]);

        return [$user, $site];
    }
}
