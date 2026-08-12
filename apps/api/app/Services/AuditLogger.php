<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogger
{
    public static function log(
        string $action,
        ?string $organizationId = null,
        ?User $user = null,
        ?string $siteId = null,
        array $meta = [],
        ?Request $request = null
    ): AuditLog {
        return AuditLog::create([
            'organization_id' => $organizationId,
            'user_id' => $user?->id,
            'site_id' => $siteId,
            'action' => $action,
            'meta' => $meta,
            'ip' => $request?->ip(),
        ]);
    }
}
