<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_wizard_can_create_project_without_site_name(): void
    {
        $org = Organization::create(['name' => 'Haus', 'slug' => 'haus']);
        $user = User::factory()->create(['current_organization_id' => $org->id]);
        Membership::create(['organization_id' => $org->id, 'user_id' => $user->id, 'role' => 'owner']);
        $token = $user->createToken('t')->plainTextToken;

        $res = $this->withToken($token)->postJson('/api/onboarding/projects', [
            'site_url' => 'https://kunde.example',
            'maintenance_tier' => '2',
            'client' => [
                'name' => 'Kunde GmbH',
                'email' => 'office@kunde.example',
            ],
        ])->assertCreated();

        $this->assertSame('kunde.example', $res->json('install.site_name'));
        $this->assertSame('https://kunde.example', $res->json('install.site_url'));
        $this->assertNotEmpty($res->json('install.pairing_code'));
        $this->assertSame(1, Project::count());
        $this->assertSame(1, $res->json('data.sites') ? count($res->json('data.sites')) : 0);
    }
}
