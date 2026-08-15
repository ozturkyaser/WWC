<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TeamController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->attributes->get('organization_id');
        $members = Membership::where('organization_id', $orgId)
            ->with('user:id,name,email')
            ->get()
            ->map(fn (Membership $m) => [
                'id' => $m->id,
                'user_id' => $m->user_id,
                'role' => $m->role,
                'name' => $m->user?->name,
                'email' => $m->user?->email,
            ]);

        $invites = OrganizationInvite::where('organization_id', $orgId)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->get(['id', 'email', 'role', 'expires_at', 'created_at']);

        return response()->json([
            'data' => $members,
            'invites' => $invites,
            'me_role' => $request->attributes->get('membership_role'),
        ]);
    }

    public function invite(Request $request)
    {
        $orgId = $request->attributes->get('organization_id');
        $data = $request->validate([
            'email' => 'required|email',
            'role' => 'required|in:admin,technician,viewer',
        ]);

        $exists = Membership::where('organization_id', $orgId)
            ->whereHas('user', fn ($q) => $q->where('email', $data['email']))
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'Diese Person ist bereits Mitglied.'], 422);
        }

        OrganizationInvite::where('organization_id', $orgId)
            ->where('email', $data['email'])
            ->whereNull('accepted_at')
            ->delete();

        $invite = OrganizationInvite::create([
            'organization_id' => $orgId,
            'email' => $data['email'],
            'role' => $data['role'],
            'token' => Str::random(48),
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(7),
        ]);

        AuditLogger::log('team.invited', $orgId, $request->user(), null, [
            'email' => $data['email'],
            'role' => $data['role'],
        ], $request);

        $portal = rtrim((string) config('wwc.portal_url'), '/');

        return response()->json([
            'data' => $invite,
            'accept_url' => $portal.'/login?invite='.$invite->token,
        ], 201);
    }

    public function updateRole(Request $request, string $id)
    {
        $orgId = $request->attributes->get('organization_id');
        $data = $request->validate(['role' => 'required|in:owner,admin,technician,viewer']);
        $membership = Membership::where('organization_id', $orgId)->findOrFail($id);

        if ($membership->role === 'owner' && $data['role'] !== 'owner') {
            $owners = Membership::where('organization_id', $orgId)->where('role', 'owner')->count();
            if ($owners <= 1) {
                return response()->json(['message' => 'Der letzte Inhaber kann nicht herabgestuft werden.'], 422);
            }
        }

        $membership->update(['role' => $data['role']]);

        return response()->json(['data' => $membership]);
    }

    public function destroy(Request $request, string $id)
    {
        $orgId = $request->attributes->get('organization_id');
        $membership = Membership::where('organization_id', $orgId)->findOrFail($id);
        if ($membership->user_id === $request->user()->id) {
            return response()->json(['message' => 'Du kannst dich nicht selbst entfernen.'], 422);
        }
        if ($membership->role === 'owner') {
            return response()->json(['message' => 'Inhaber können nicht entfernt werden.'], 422);
        }
        $membership->delete();

        return response()->json(['ok' => true]);
    }

    public function revokeInvite(Request $request, string $id)
    {
        $orgId = $request->attributes->get('organization_id');
        OrganizationInvite::where('organization_id', $orgId)->whereNull('accepted_at')->findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    public function accept(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string',
            'name' => 'required|string|max:120',
            'password' => 'required|string|min:8',
        ]);

        $invite = OrganizationInvite::where('token', $data['token'])->first();
        if (! $invite || ! $invite->isOpen()) {
            return response()->json(['message' => 'Einladung ungültig oder abgelaufen.'], 422);
        }

        $user = User::where('email', $invite->email)->first();
        if (! $user) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $invite->email,
                'password' => $data['password'],
                'current_organization_id' => $invite->organization_id,
            ]);
        } else {
            $user->current_organization_id = $invite->organization_id;
            $user->save();
        }

        Membership::firstOrCreate(
            ['organization_id' => $invite->organization_id, 'user_id' => $user->id],
            ['role' => $invite->role]
        );

        $invite->update(['accepted_at' => now()]);
        $token = $user->createToken('portal')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->load('currentOrganization'),
        ]);
    }
}
