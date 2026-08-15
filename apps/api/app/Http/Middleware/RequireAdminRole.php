<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Beschraenkt sensible Aktionen (Organisation, Abrechnung, Loeschen,
 * Secret-Rotation) auf Inhaber und Administratoren.
 */
class RequireAdminRole
{
    public function handle(Request $request, Closure $next)
    {
        $role = $request->attributes->get('membership_role');

        if (! in_array($role, ['owner', 'admin'], true)) {
            return response()->json(['message' => 'Diese Aktion erfordert Administrator-Rechte.'], 403);
        }

        return $next($request);
    }
}
