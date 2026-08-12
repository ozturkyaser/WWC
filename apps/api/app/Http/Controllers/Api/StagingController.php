<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\AgentDispatcher;
use App\Services\AuditLogger;
use App\Services\StagingPortalService;
use Illuminate\Http\Request;

class StagingController extends Controller
{
    public function showBySlug(Request $request, string $slug, StagingPortalService $staging)
    {
        $orgId = $request->attributes->get('organization_id');
        $site = Site::where('organization_id', $orgId)
            ->where('staging_slug', $slug)
            ->firstOrFail();

        return response()->json([
            'data' => [
                'site' => [
                    'id' => $site->id,
                    'name' => $site->name,
                    'url' => $site->url,
                    'status' => $site->status,
                ],
                'staging' => $staging->toPortalPayload($site),
            ],
        ]);
    }

    public function grantAdmin(Request $request, string $id, AgentDispatcher $dispatcher, StagingPortalService $staging)
    {
        $orgId = $request->attributes->get('organization_id');
        $site = Site::where('organization_id', $orgId)->findOrFail($id);

        if (! $site->staging_slug) {
            $site->staging_slug = $staging->uniqueSlug($site);
            $site->save();
        }

        $job = $dispatcher->dispatch($site, 'staging_grant_admin', [], $request->user());
        AuditLogger::log('staging.grant_admin', $orgId, $request->user(), $site->id, [
            'job_id' => $job->id,
        ], $request);

        return response()->json([
            'data' => $job,
            'staging' => $staging->toPortalPayload($site->fresh()),
        ], 202);
    }
}
