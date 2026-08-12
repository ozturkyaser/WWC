<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
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

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => ['Invalid credentials.']]);
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
        return response()->json([
            'user' => $request->user()->load('currentOrganization', 'memberships.organization'),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }
}
