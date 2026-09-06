<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\Request;

class SupportInboxController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->attributes->get('organization_id');
        $sites = Site::query()
            ->where('organization_id', $orgId)
            ->whereNotNull('support_granted_at')
            ->whereNull('support_revoked_at')
            ->with(['client:id,name', 'project:id,name'])
            ->orderByDesc('support_granted_at')
            ->get();

        return response()->json([
            'data' => $sites,
            'open' => $sites->count(),
        ]);
    }
}
