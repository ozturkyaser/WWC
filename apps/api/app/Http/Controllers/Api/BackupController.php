<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\AgentClient;
use App\Services\AgentDispatcher;
use App\Services\AuditLogger;
use App\Services\BackupStorageService;
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

        // Prefer the copy stored on the WWC server (off-site backup)
        $storage = app(\App\Services\BackupStorageService::class);
        $stored = \App\Models\SiteBackup::query()
            ->where('site_id', $site->id)
            ->where('status', 'stored')
            ->when(
                $backupId === 'latest-full',
                fn ($q) => $q->where('type', 'full')->orderByDesc('backup_created_at'),
                fn ($q) => $q->where('backup_id', $storage->sanitizeId($backupId))
            )
            ->first();

        if ($stored && is_file((string) $stored->storage_path)) {
            AuditLogger::log('backup.downloaded', $orgId, $request->user(), $site->id, [
                'backup_id' => $stored->backup_id,
                'source' => 'server',
            ], $request);

            return response()->download($stored->storage_path, $stored->backup_id.'.zip', [
                'Content-Type' => 'application/zip',
                'X-WWC-Backup-Id' => $stored->backup_id,
            ]);
        }

        // Fallback: fetch directly from the agent (legacy/local-only backups)
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

    public function destroy(Request $request, string $siteId, string $backupId, AgentDispatcher $dispatcher)
    {
        $orgId = $request->attributes->get('organization_id');
        $site = Site::where('organization_id', $orgId)->findOrFail($siteId);
        $storage = app(BackupStorageService::class);
        $id = $storage->sanitizeId($backupId);
        if ($id === '' || $id === 'backup') {
            return response()->json(['message' => 'Ungültige Backup-ID'], 422);
        }

        $storage->delete($site, $id);

        $health = is_array($site->health) ? $site->health : [];
        if (is_array($health['backups'] ?? null)) {
            $health['backups'] = array_values(array_filter(
                $health['backups'],
                static fn ($row) => (string) ($row['id'] ?? '') !== $id
            ));
            $site->health = $health;
            $site->save();
        }

        $job = null;
        if ($site->getHmacSecret()) {
            try {
                $job = $dispatcher->dispatch($site, 'delete_backup', ['backup_id' => $id], $request->user());
            } catch (\Throwable $e) {
                // Server copy is already gone; WordPress cleanup can wait for the next pairing.
            }
        }

        AuditLogger::log('backup.deleted', $orgId, $request->user(), $site->id, [
            'backup_id' => $id,
        ], $request);

        return response()->json(['ok' => true, 'deleted' => $id, 'job' => $job]);
    }
}
