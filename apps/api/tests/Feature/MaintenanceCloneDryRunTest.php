<?php

namespace Tests\Feature;

use App\Jobs\CloneDryRunJob;
use App\Models\MaintenanceRun;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MaintenanceCloneDryRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_execute_requires_ready_clone(): void
    {
        [$user, $site] = $this->site();
        $run = MaintenanceRun::create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'trigger' => 'manual',
            'status' => 'planned',
            'plan' => [
                'updates' => [['type' => 'plugin', 'slug' => 'akismet', 'name' => 'Akismet']],
            ],
            'started_at' => now(),
        ]);

        $this->withToken($user->createToken('t')->plainTextToken)
            ->postJson('/api/sites/'.$site->id.'/maintenance/runs/'.$run->id.'/execute')
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'needs_review');

        $this->assertStringContainsString('isolierte Umgebung', (string) $run->fresh()->error);
    }

    public function test_execute_dispatches_clone_dry_run(): void
    {
        Queue::fake();
        [$user, $site] = $this->site();
        $site->update(['dev_clone' => ['status' => 'ready', 'port' => 9123]]);
        $run = MaintenanceRun::create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'trigger' => 'manual',
            'status' => 'planned',
            'plan' => [
                'updates' => [
                    ['type' => 'plugin', 'slug' => 'akismet'],
                    ['type' => 'core', 'slug' => 'wordpress'],
                ],
            ],
            'started_at' => now(),
        ]);

        $this->withToken($user->createToken('t')->plainTextToken)
            ->postJson('/api/sites/'.$site->id.'/maintenance/runs/'.$run->id.'/execute')
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'dry_running')
            ->assertJsonPath('data.plan.dry_run_target', 'clone');

        Queue::assertPushed(CloneDryRunJob::class, function (CloneDryRunJob $job) use ($site, $run) {
            return $job->siteId === $site->id
                && $job->maintenanceRunId === $run->id
                && count($job->items) === 2;
        });
    }

    /** @return array{0:User,1:Site} */
    private function site(): array
    {
        $org = Organization::create(['name' => 'Haus', 'slug' => 'haus']);
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        Membership::create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => 'owner']);
        $site = Site::create([
            'organization_id' => $org->id,
            'name' => 'Cuno',
            'url' => 'https://cuno.example',
            'status' => 'active',
        ]);

        return [$user, $site];
    }
}
