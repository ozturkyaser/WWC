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

        if (! $clones->canBuild($site)) {
            return response()->json([
                'message' => 'Site ist nicht verbunden und es liegt kein Backup auf dem WWC-Server. Zuerst koppeln oder ein Voll-Backup anlegen.',
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

    public function dryRun(Request $request, string $id, DevCloneService $clones)
    {
        $orgId = $request->attributes->get('organization_id');
        $site = Site::where('organization_id', $orgId)->findOrFail($id);

        if (($site->dev_clone['status'] ?? '') !== 'ready') {
            return response()->json(['message' => 'Dev-Kopie ist nicht bereit. Bitte zuerst erstellen.'], 422);
        }

        $data = $request->validate([
            'items' => 'required|array|min:1|max:50',
            'items.*.type' => 'required|string|in:plugin,theme,core',
            'items.*.slug' => 'nullable|string|max:200',
        ]);

        $site->update(['dev_clone' => array_merge($site->dev_clone ?? [], ['last_dry_run' => ['at' => now()->toIso8601String(), 'running' => true]])]);
        \App\Jobs\CloneDryRunJob::dispatch($site->id, $data['items']);
        AuditLogger::log('site.dev_clone.dry_run', $orgId, $request->user(), $site->id, ['items' => $data['items']], $request);

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
