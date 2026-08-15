<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->attributes->get('organization_id');
        $q = SiteEvent::where('organization_id', $orgId)
            ->with('site:id,name,url')
            ->latest('occurred_at');

        if ($request->filled('site')) {
            $q->where('site_id', $request->query('site'));
        }
        if ($request->filled('type')) {
            $q->where('type', $request->query('type'));
        }
        if ($request->query('suspicious') === '1') {
            $q->where(function ($inner) {
                $inner->whereIn('severity', ['warning', 'critical', 'error'])
                    ->orWhereIn('type', \App\Services\ActivityMonitorService::SUSPICIOUS_TYPES);
            });
        }
        if ($request->filled('q')) {
            $term = '%'.$request->query('q').'%';
            $q->where(function ($inner) use ($term) {
                $inner->where('title', 'like', $term)
                    ->orWhere('type', 'like', $term);
            });
        }

        return response()->json([
            'data' => $q->limit(200)->get(),
        ]);
    }

    public function updateGuard(Request $request, string $id)
    {
        $orgId = $request->attributes->get('organization_id');
        $site = Site::where('organization_id', $orgId)->findOrFail($id);
        $data = $request->validate([
            'enabled' => 'required|boolean',
            'auto_block' => 'sometimes|boolean',
            'block' => 'sometimes|array',
            'block.*' => 'string|in:new_admin,role_escalate,plugin_install,theme_switch,file_edit,user_delete_admin',
        ]);
        $guard = array_merge($site->activity_guard ?? [], $data);
        $site->update(['activity_guard' => $guard]);
        AuditLogger::log('site.activity_guard', $orgId, $request->user(), $site->id, $guard, $request);

        return response()->json(['data' => $site->fresh()]);
    }
}
