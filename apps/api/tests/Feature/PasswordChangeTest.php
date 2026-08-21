<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private function registerUser(): array
    {
        $res = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'secret-password',
            'organization_name' => 'Test Org',
        ])->assertCreated();

        return [$res->json('token'), User::where('email', 'test@example.com')->firstOrFail()];
    }

    public function test_user_can_change_password(): void
    {
        [$token, $user] = $this->registerUser();

        $this->withToken($token)->postJson('/api/auth/password', [
            'current_password' => 'secret-password',
            'password' => 'new-secret-1',
            'password_confirmation' => 'new-secret-1',
        ])->assertOk()->assertJson(['ok' => true]);

        $user->refresh();
        $this->assertTrue(Hash::check('new-secret-1', $user->password));

        $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'new-secret-1',
        ])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        [$token] = $this->registerUser();

        $this->withToken($token)->postJson('/api/auth/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-secret-1',
            'password_confirmation' => 'new-secret-1',
        ])->assertStatus(422);
    }

    public function test_confirmation_must_match(): void
    {
        [$token] = $this->registerUser();

        $this->withToken($token)->postJson('/api/auth/password', [
            'current_password' => 'secret-password',
            'password' => 'new-secret-1',
            'password_confirmation' => 'other-secret',
        ])->assertStatus(422);
    }
}
