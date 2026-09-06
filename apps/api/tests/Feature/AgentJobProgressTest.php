<?php

namespace Tests\Feature;

use App\Jobs\PushAgentCommandJob;
use App\Models\AgentJob;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\HmacSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AgentJobProgressTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $org = Organization::create(['name' => 'Org', 'slug' => 'org']);
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        Membership::create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->site = Site::create([
            'organization_id' => $org->id,
            'name' => 'Cuno',
            'url' => 'https://cuno.gmbh',
            'status' => 'offline',
            'paired_at' => now()->subDay(),
            'last_seen_at' => now()->subHours(6),
            'agent_version' => '0.6.24',
        ]);
        $this->site->setHmacSecret('test-secret');
        $this->site->save();
    }

    public function test_progress_marks_site_online_and_nudges_hosting_limit(): void
    {
        Queue::fake();

        $job = AgentJob::create([
            'organization_id' => $this->site->organization_id,
            'site_id' => $this->site->id,
            'command' => 'backup_incremental',
            'status' => 'running',
            'progress' => 47,
        ]);

        $res = $this->agentPost('/api/agent/jobs/'.$job->id.'/progress', [
            'progress' => 47,
            'label' => 'Hosting-Limit: Backup wird gleich fortgesetzt…',
            'log' => [['message' => 'Hosting-Limit: Backup wird gleich fortgesetzt…', 'percent' => 47]],
        ]);

        $res->assertOk();
        $this->assertSame('online', $this->site->fresh()->status);
        $this->assertNotNull($this->site->fresh()->last_seen_at);
        $this->assertTrue($this->site->fresh()->last_seen_at->greaterThan(now()->subMinute()));

        Queue::assertPushed(PushAgentCommandJob::class, function (PushAgentCommandJob $pushed) use ($job) {
            return $pushed->jobId === $job->id && $pushed->nudge === true;
        });
    }

    public function test_progress_does_not_nudge_normal_scan_updates(): void
    {
        Queue::fake();

        $job = AgentJob::create([
            'organization_id' => $this->site->organization_id,
            'site_id' => $this->site->id,
            'command' => 'backup_incremental',
            'status' => 'running',
            'progress' => 36,
        ]);

        $this->agentPost('/api/agent/jobs/'.$job->id.'/progress', [
            'progress' => 36,
            'label' => 'Dateien scannen… 1200/47000',
        ])->assertOk();

        Queue::assertNothingPushed();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function agentPost(string $path, array $body)
    {
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $ts = (string) time();
        $nonce = bin2hex(random_bytes(8));
        $sig = HmacSigner::sign('POST', $path, $ts, $nonce, $json, $this->site->getHmacSecret());

        return $this->call(
            'POST',
            $path,
            [],
            [],
            [],
            [
                'HTTP_X_WWC_SITE_ID' => $this->site->id,
                'HTTP_X_WWC_TIMESTAMP' => $ts,
                'HTTP_X_WWC_NONCE' => $nonce,
                'HTTP_X_WWC_SIGNATURE' => $sig,
                'CONTENT_TYPE' => 'application/json',
            ],
            $json
        );
    }
}
