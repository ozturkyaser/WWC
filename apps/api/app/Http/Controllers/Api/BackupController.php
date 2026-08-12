<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\AgentClient;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class BackupController extends Controller
{
    public function downloadLatest(Request $request, string $siteId, AgentClient $agent)
    {
        return $this->download($request, $siteId, 'latest-full', $agent);
    }

    public function download(Request $request, string $siteId, string $backupId, AgentClient $agent)
    {
        $orgId = $request->attributes->get('organization_id');
        $site = Site::where('organization_id', $orgId)->findOrFail($siteId);

        if (! $site->getHmacSecret()) {
            return response()->json(['message' => 'Site ist nicht verbunden'], 422);
        }

        try {
            $file = $agent->downloadBackup($site, $backupId);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        AuditLogger::log('backup.downloaded', $orgId, $request->user(), $site->id, [
            'backup_id' => $file['backup_id'] ?? $backupId,
            'filename' => $file['filename'],
        ], $request);

        return response($file['body'], 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
            'X-WWC-Backup-Id' => (string) ($file['backup_id'] ?? $backupId),
        ]);
    }
}
