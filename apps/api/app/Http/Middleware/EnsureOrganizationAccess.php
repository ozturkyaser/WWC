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

        $membership = Membership::where('user_id', $user->id)
            ->where('organization_id', $user->current_organization_id)
            ->first();

        if (! $membership) {
            return response()->json(['message' => 'Forbidden for organization.'], 403);
        }

        // Viewer duerfen nur lesen.
        if ($membership->role === 'viewer' && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return response()->json(['message' => 'Nur-Lese-Zugriff: Diese Aktion erfordert Schreibrechte.'], 403);
        }

        $request->attributes->set('organization_id', $user->current_organization_id);
        $request->attributes->set('membership_role', $membership->role);

        return $next($request);
    }
}
