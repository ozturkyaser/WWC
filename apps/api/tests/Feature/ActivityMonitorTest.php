<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Models\User;
use App\Services\ActivityMonitorService;
use App\Services\HmacSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ActivityMonitorTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private string $token;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->org = Organization::create(['name' => 'Haus', 'slug' => 'haus']);
        $user = User::factory()->create(['current_organization_id' => $this->org->id]);
        Membership::create(['organization_id' => $this->org->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->token = $user->createToken('t')->plainTextToken;
        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'name' => 'Shop',
            'url' => 'https://example.com',
            'status' => 'online',
            'paired_at' => now(),
        ]);
        $this->site->setHmacSecret('test-secret');
        $this->site->save();
    }

    public function test_new_admin_is_flagged_and_auto_blocked(): void
    {
        $this->site->update([
            'activity_guard' => ['enabled' => true, 'auto_block' => true, 'block' => []],
        ]);

        $event = SiteEvent::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'type' => 'user_created',
            'severity' => 'warning',
            'title' => 'Benutzer angelegt: hacker',
            'payload' => [
                'user_login' => 'alice',
                'ip' => '1.2.3.4',
                'target_login' => 'hacker',
                'target_roles' => ['administrator'],
            ],
            'occurred_at' => now(),
        ]);

        app(ActivityMonitorService::class)->ingest($this->site, $event);

        $event->refresh();
        $this->assertContains('new_admin', $event->payload['monitor']['flags']);
        $this->assertSame('critical', $event->severity);
        $this->assertContains('new_admin', $this->site->fresh()->activity_guard['block']);
    }

    public function test_passwords_are_stripped_from_heartbeat_events(): void
    {
        $res = $this->agentPost('/api/agent/heartbeat', [
            'events' => [[
                'type' => 'login_failed',
                'title' => 'Fehlgeschlagene Anmeldung: admin',
                'severity' => 'warning',
                'payload' => [
                    'username' => 'admin',
                    'password' => 'super-secret',
                    'ip' => '8.8.8.8',
                ],
            ]],
        ]);

        $res->assertOk()->assertJsonPath('ok', true);
        $this->assertArrayHasKey('guard', $res->json());

        $event = SiteEvent::where('site_id', $this->site->id)->where('type', 'login_failed')->first();
        $this->assertNotNull($event);
        $this->assertArrayNotHasKey('password', $event->payload ?? []);
        $this->assertArrayNotHasKey('password_encrypted', $event->payload ?? []);
        $this->assertSame('admin', $event->payload['username']);
    }

    public function test_activity_feed_and_guard_update(): void
    {
        SiteEvent::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'type' => 'user_login',
            'severity' => 'info',
            'title' => 'Anmeldung: alice',
            'payload' => ['user_login' => 'alice', 'ip' => '10.0.0.1'],
            'occurred_at' => now(),
        ]);
        SiteEvent::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'type' => 'user_role',
            'severity' => 'critical',
            'title' => 'Rolle geändert: bob → administrator',
            'payload' => ['user_login' => 'alice', 'new_role' => 'administrator'],
            'occurred_at' => now(),
        ]);

        $all = $this->withToken($this->token)->getJson('/api/activity')->assertOk();
        $this->assertCount(2, $all->json('data'));

        $sus = $this->withToken($this->token)->getJson('/api/activity?suspicious=1')->assertOk();
        $this->assertCount(1, $sus->json('data'));
        $this->assertSame('user_role', $sus->json('data.0.type'));

        $this->withToken($this->token)->putJson('/api/sites/'.$this->site->id.'/activity-guard', [
            'enabled' => true,
            'auto_block' => true,
            'block' => ['plugin_install', 'theme_switch'],
        ])->assertOk()->assertJsonPath('data.activity_guard.enabled', true);

        $hb = $this->agentPost('/api/agent/heartbeat', ['wp_version' => '6.6']);
        $hb->assertOk()
            ->assertJsonPath('guard.enabled', true)
            ->assertJsonPath('guard.auto_block', true);
        $this->assertEqualsCanonicalizing(
            ['plugin_install', 'theme_switch'],
            $hb->json('guard.block')
        );
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
