<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\HmacSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportGrantTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Haus', 'slug' => 'haus']);
        $user = User::factory()->create(['current_organization_id' => $this->org->id]);
        Membership::create(['organization_id' => $this->org->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->token = $user->createToken('t')->plainTextToken;
    }

    public function test_customer_grant_creates_site_without_pairing_code(): void
    {
        $res = $this->postJson('/api/agent/support-grant', [
            'site_url' => 'https://kunde.example/',
            'wp_version' => '7.1',
            'php_version' => '8.3.33',
            'agent_version' => '0.6.28',
            'contact' => 'Anna',
            'note' => 'Backup hängt',
        ])->assertCreated();

        $res->assertJsonPath('created', true);
        $this->assertNotEmpty($res->json('hmac_secret'));
        $this->assertNotEmpty($res->json('site_id'));

        $site = Site::findOrFail($res->json('site_id'));
        $this->assertSame($this->org->id, $site->organization_id);
        $this->assertSame('https://kunde.example', $site->url);
        $this->assertSame('support', $site->access_mode);
        $this->assertSame('Anna', $site->support_contact);
        $this->assertNotNull($site->support_granted_at);
        $this->assertNull($site->support_revoked_at);
        $this->assertNotNull($site->paired_at);
    }

    public function test_second_grant_same_host_does_not_duplicate(): void
    {
        $first = $this->postJson('/api/agent/support-grant', [
            'site_url' => 'https://www.kunde.example',
        ])->assertCreated();

        $second = $this->postJson('/api/agent/support-grant', [
            'site_url' => 'https://kunde.example/wp/',
            'note' => 'Nochmal',
        ])->assertOk();

        $this->assertSame($first->json('site_id'), $second->json('site_id'));
        $this->assertSame(1, Site::query()->count());
        $this->assertSame('Nochmal', Site::first()->support_note);
    }

    public function test_inbox_lists_open_grants(): void
    {
        $this->postJson('/api/agent/support-grant', [
            'site_url' => 'https://kunde.example',
            'contact' => 'Anna',
        ])->assertCreated();

        $this->withToken($this->token)
            ->getJson('/api/support-sites')
            ->assertOk()
            ->assertJsonPath('open', 1)
            ->assertJsonPath('data.0.support_contact', 'Anna');
    }

    public function test_revoke_disconnects_support_site(): void
    {
        $grant = $this->postJson('/api/agent/support-grant', [
            'site_url' => 'https://kunde.example',
        ])->assertCreated();

        $site = Site::findOrFail($grant->json('site_id'));
        $this->agentPost($site, $grant->json('hmac_secret'), '/api/agent/support-revoke', [])
            ->assertOk();

        $site->refresh();
        $this->assertNotNull($site->support_revoked_at);
        $this->assertNull($site->getHmacSecret());
        $this->assertNull($site->paired_at);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function agentPost(Site $site, string $secret, string $path, array $body)
    {
        $json = json_encode($body === [] ? new \stdClass : $body, JSON_THROW_ON_ERROR);
        if ($body === []) {
            $json = '';
        }
        $ts = (string) time();
        $nonce = bin2hex(random_bytes(8));
        $sig = HmacSigner::sign('POST', $path, $ts, $nonce, $json, $secret);

        return $this->call(
            'POST',
            $path,
            [],
            [],
            [],
            [
                'HTTP_X_WWC_SITE_ID' => $site->id,
                'HTTP_X_WWC_TIMESTAMP' => $ts,
                'HTTP_X_WWC_NONCE' => $nonce,
                'HTTP_X_WWC_SIGNATURE' => $sig,
                'CONTENT_TYPE' => 'application/json',
            ],
            $json
        );
    }
}
