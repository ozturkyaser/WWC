<?php

namespace App\Http\Middleware;

use App\Models\Membership;
use Closure;
use Illuminate\Http\Request;

class EnsureOrganizationAccess
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user || ! $user->current_organization_id) {
            return response()->json(['message' => 'No organization selected.'], 403);
        }

        $ok = Membership::where('user_id', $user->id)
            ->where('organization_id', $user->current_organization_id)
            ->exists();

        if (! $ok) {
            return response()->json(['message' => 'Forbidden for organization.'], 403);
        }

        $request->attributes->set('organization_id', $user->current_organization_id);

        return $next($request);
    }
}
