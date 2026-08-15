<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\BuildDevCloneJob;
use App\Models\Site;
use App\Services\AuditLogger;
use App\Services\DevCloneService;
use Illuminate\Http\Request;

class DevCloneController extends Controller
{
    public function create(Request $request, string $id, DevCloneService $clones)
    {
        $orgId = $request->attributes->get('organization_id');
        $site = Site::where('organization_id', $orgId)->findOrFail($id);

        if (! $clones->latestUsableBackup($site)) {
            return response()->json([
                'message' => 'Kein Backup auf dem WWC-Server vorhanden. Bitte zuerst ein Voll-Backup erstellen.',
            ], 422);
        }

        $current = $site->dev_clone['status'] ?? null;
        if ($current === 'building') {
            return response()->json(['message' => 'Dev-Kopie wird bereits gebaut.'], 409);
        }

        $site->update(['dev_clone' => array_merge($site->dev_clone ?? [], ['status' => 'building', 'error' => null])]);
        BuildDevCloneJob::dispatch($site->id);
        AuditLogger::log('site.dev_clone.build', $orgId, $request->user(), $site->id, [], $request);

        return response()->json(['data' => $clones->payload($site->fresh())], 202);
    }

    public function destroy(Request $request, string $id, DevCloneService $clones)
    {
        $orgId = $request->attributes->get('organization_id');
        $site = Site::where('organization_id', $orgId)->findOrFail($id);

        $clones->destroy($site);
        AuditLogger::log('site.dev_clone.destroy', $orgId, $request->user(), $site->id, [], $request);

        return response()->json(['ok' => true]);
    }
}
