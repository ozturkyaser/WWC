<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TotpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'organization_name' => 'required|string|max:120',
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        $org = Organization::create([
            'name' => $data['organization_name'],
            'slug' => Str::slug($data['organization_name']).'-'.Str::lower(Str::random(4)),
            'billing_profile' => [
                'company' => $data['organization_name'],
                'address' => '',
                'tax_rate' => 19,
                'small_business' => false,
            ],
        ]);

        Membership::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $user->current_organization_id = $org->id;
        $user->save();

        $token = $user->createToken('portal')->plainTextToken;

        AuditLogger::log('auth.register', $org->id, $user, null, [], $request);

        return response()->json([
            'token' => $token,
            'user' => $user->load('currentOrganization'),
        ], 201);
    }

    public function login(Request $request, TotpService $totp)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'code' => 'nullable|string|max:32',
        ]);

        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['Invalid credentials.']]);
        }

        if ($user->hasTwoFactorEnabled()) {
            $code = trim((string) ($data['code'] ?? ''));
            if ($code === '') {
                return response()->json(['requires_2fa' => true], 200);
            }
            if (! $this->verifySecondFactor($user, $code, $totp)) {
                AuditLogger::log('auth.2fa_failed', $user->current_organization_id, $user, null, [], $request);
                throw ValidationException::withMessages(['code' => ['Ungültiger 2FA-Code.']]);
            }
        }

        $token = $user->createToken('portal')->plainTextToken;
        AuditLogger::log('auth.login', $user->current_organization_id, $user, null, [], $request);

        return response()->json([
            'token' => $token,
            'user' => $user->load('currentOrganization'),
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user()->load('currentOrganization', 'memberships.organization');

        return response()->json([
            'user' => $user,
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
        ]);
    }

    /**
     * Schritt 1: Geheimnis erzeugen (noch nicht aktiv). Wird beim naechsten
     * Setup-Aufruf ueberschrieben, solange 2FA nicht bestaetigt wurde.
     */
    public function twoFactorSetup(Request $request, TotpService $totp)
    {
        $user = $request->user();
        if ($user->hasTwoFactorEnabled()) {
            return response()->json(['message' => '2FA ist bereits aktiviert.'], 422);
        }

        $secret = $totp->generateSecret();
        $user->setTwoFactorSecret($secret);
        $user->two_factor_enabled_at = null;
        $user->save();

        return response()->json([
            'secret' => $secret,
            'otpauth_uri' => $totp->otpauthUri($secret, $user->email),
        ]);
    }

    /**
     * Schritt 2: Code aus der Authenticator-App bestaetigen und 2FA aktivieren.
     * Liefert einmalig die Wiederherstellungscodes zurueck.
     */
    public function twoFactorEnable(Request $request, TotpService $totp)
    {
        $data = $request->validate(['code' => 'required|string|max:32']);
        $user = $request->user();

        $secret = $user->getTwoFactorSecret();
        if (! $secret || $user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Kein 2FA-Setup ausstehend.'], 422);
        }

        if (! $totp->verify($secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => ['Ungültiger 2FA-Code.']]);
        }

        $recoveryCodes = collect(range(1, 8))
            ->map(fn () => Str::upper(Str::random(5)).'-'.Str::upper(Str::random(5)))
            ->all();

        $user->setRecoveryCodes($recoveryCodes);
        $user->two_factor_enabled_at = now();
        $user->save();

        AuditLogger::log('auth.2fa_enabled', $user->current_organization_id, $user, null, [], $request);

        return response()->json([
            'enabled' => true,
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    public function twoFactorDisable(Request $request, TotpService $totp)
    {
        $data = $request->validate([
            'password' => 'required|string',
            'code' => 'required|string|max:32',
        ]);
        $user = $request->user();

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => ['Falsches Passwort.']]);
        }
        if (! $user->hasTwoFactorEnabled() || ! $this->verifySecondFactor($user, trim($data['code']), $totp)) {
            throw ValidationException::withMessages(['code' => ['Ungültiger 2FA-Code.']]);
        }

        $user->setTwoFactorSecret(null);
        $user->two_factor_recovery_codes = null;
        $user->two_factor_enabled_at = null;
        $user->save();

        AuditLogger::log('auth.2fa_disabled', $user->current_organization_id, $user, null, [], $request);

        return response()->json(['enabled' => false]);
    }

    private function verifySecondFactor(User $user, string $code, TotpService $totp): bool
    {
        $secret = $user->getTwoFactorSecret();
        if ($secret && $totp->verify($secret, $code)) {
            return true;
        }

        // Wiederherstellungscode: einmalig gueltig, wird nach Nutzung entfernt.
        $codes = $user->getRecoveryCodes();
        $normalized = Str::upper(trim($code));
        $index = array_search($normalized, $codes, true);
        if ($index !== false) {
            unset($codes[$index]);
            $user->setRecoveryCodes($codes);
            $user->save();

            return true;
        }

        return false;
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }
}
