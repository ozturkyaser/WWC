<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'Test Org',
            'slug' => 'test-org',
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['current_organization_id' => $this->org->id]);
        Membership::create([
            'organization_id' => $this->org->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);

        return $user;
    }

    public function test_viewer_can_read_but_not_write(): void
    {
        $token = $this->userWithRole('viewer')->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/sites')->assertOk();

        $this->withToken($token)->postJson('/api/sites', [
            'name' => 'Neue Site',
            'url' => 'https://example.com',
        ])->assertForbidden();
    }

    public function test_technician_can_write_but_not_administer(): void
    {
        $token = $this->userWithRole('technician')->createToken('t')->plainTextToken;

        // Schreiben erlaubt.
        $this->withToken($token)->postJson('/api/sites', [
            'name' => 'Neue Site',
            'url' => 'https://example.com',
        ])->assertCreated();

        // Admin-Aktionen verboten.
        $this->withToken($token)->putJson('/api/organization', ['name' => 'Neu'])->assertForbidden();
    }

    public function test_owner_has_full_access(): void
    {
        $token = $this->userWithRole('owner')->createToken('t')->plainTextToken;

        $this->withToken($token)->putJson('/api/organization', ['name' => 'Neuer Name'])->assertOk();
    }

    public function test_user_without_membership_is_blocked(): void
    {
        $user = User::factory()->create(['current_organization_id' => $this->org->id]);
        $token = $user->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/sites')->assertForbidden();
    }
}
