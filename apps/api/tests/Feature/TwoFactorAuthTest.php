<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwoFactorAuthTest extends TestCase
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

    public function test_full_two_factor_flow(): void
    {
        [$token, $user] = $this->registerUser();
        $totp = app(TotpService::class);

        // Setup liefert Secret + otpauth-URI.
        $setup = $this->withToken($token)->postJson('/api/auth/2fa/setup')->assertOk();
        $secret = $setup->json('secret');
        $this->assertNotEmpty($secret);
        $this->assertStringContainsString('otpauth://totp/', $setup->json('otpauth_uri'));

        // Falscher Code aktiviert nicht.
        $this->withToken($token)->postJson('/api/auth/2fa/enable', ['code' => '000000'])
            ->assertStatus(422);

        // Richtiger Code aktiviert und liefert Recovery-Codes.
        $code = $totp->codeAt($secret, intdiv(time(), 30));
        $enable = $this->withToken($token)->postJson('/api/auth/2fa/enable', ['code' => $code])->assertOk();
        $recoveryCodes = $enable->json('recovery_codes');
        $this->assertCount(8, $recoveryCodes);

        // Login ohne Code: kein Token, requires_2fa.
        $res = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'secret-password',
        ])->assertOk();
        $this->assertTrue($res->json('requires_2fa'));
        $this->assertNull($res->json('token'));

        // Login mit falschem Code scheitert.
        $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'secret-password',
            'code' => '000000',
        ])->assertStatus(422);

        // Login mit gueltigem TOTP-Code.
        $code = $totp->codeAt($secret, intdiv(time(), 30));
        $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'secret-password',
            'code' => $code,
        ])->assertOk()->assertJsonStructure(['token']);

        // Login mit Recovery-Code funktioniert genau einmal.
        $recovery = $recoveryCodes[0];
        $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'secret-password',
            'code' => $recovery,
        ])->assertOk()->assertJsonStructure(['token']);

        $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'secret-password',
            'code' => $recovery,
        ])->assertStatus(422);
    }

    public function test_two_factor_can_be_disabled(): void
    {
        [$token, $user] = $this->registerUser();
        $totp = app(TotpService::class);

        $setup = $this->withToken($token)->postJson('/api/auth/2fa/setup')->assertOk();
        $secret = $setup->json('secret');
        $code = $totp->codeAt($secret, intdiv(time(), 30));
        $this->withToken($token)->postJson('/api/auth/2fa/enable', ['code' => $code])->assertOk();

        // Falsches Passwort wird abgelehnt.
        $this->withToken($token)->postJson('/api/auth/2fa/disable', [
            'password' => 'wrong',
            'code' => $totp->codeAt($secret, intdiv(time(), 30)),
        ])->assertStatus(422);

        $this->withToken($token)->postJson('/api/auth/2fa/disable', [
            'password' => 'secret-password',
            'code' => $totp->codeAt($secret, intdiv(time(), 30)),
        ])->assertOk();

        $user->refresh();
        $this->assertFalse($user->hasTwoFactorEnabled());

        // Login geht wieder ohne Code.
        $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'secret-password',
        ])->assertOk()->assertJsonStructure(['token']);
    }
}
