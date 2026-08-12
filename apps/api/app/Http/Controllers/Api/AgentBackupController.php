<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\BackupStorageService;
use Illuminate\Http\Request;

/**
 * HMAC-protected endpoints for agents to store backups on the WWC server.
 */
class AgentBackupController extends Controller
{
    public function __construct(private BackupStorageService $storage) {}

    public function init(Request $request)
    {
        /** @var Site $site */
        $site = $request->attributes->get('agent_site');
        $data = $request->validate([
            'backup_id' => 'required|string|max:100',
            'type' => 'sometimes|string|in:full,incremental',
            'label' => 'nullable|string|max:100',
            'size_bytes' => 'required|integer|min:1',
            'sha256' => 'required|string|size:64',
            'wp_version' => 'nullable|string|max:20',
            'file_count' => 'nullable|integer|min:0',
            'parent_backup_id' => 'nullable|string|max:100',
            'created_at' => 'nullable|date',
        ]);

        $record = $this->storage->init($site, $data);

        return response()->json(['ok' => true, 'backup' => $record->only(['backup_id', 'status'])]);
    }

    public function chunk(Request $request)
    {
        /** @var Site $site */
        $site = $request->attributes->get('agent_site');
        $data = $request->validate([
            'backup_id' => 'required|string|max:100',
            'offset' => 'required|integer|min:0',
            'data' => 'required|string',
        ]);

        $binary = base64_decode($data['data'], true);
        if ($binary === false || $binary === '') {
            return response()->json(['message' => 'Ungültige Chunk-Daten'], 422);
        }
        if (strlen($binary) > 8 * 1024 * 1024) {
            return response()->json(['message' => 'Chunk zu groß (max 8 MB)'], 422);
        }

        $result = $this->storage->appendChunk($site, $data['backup_id'], (int) $data['offset'], $binary);
        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'message' => 'Offset-Konflikt',
                'expected_offset' => $result['expected_offset'] ?? 0,
            ], 409);
        }

        return response()->json($result);
    }

    public function complete(Request $request)
    {
        /** @var Site $site */
        $site = $request->attributes->get('agent_site');
        $data = $request->validate(['backup_id' => 'required|string|max:100']);

        $record = $this->storage->complete($site, $data['backup_id']);

        return response()->json([
            'ok' => true,
            'backup' => $record->only(['backup_id', 'status', 'size_bytes', 'uploaded_at']),
        ]);
    }

    public function download(Request $request)
    {
        /** @var Site $site */
        $site = $request->attributes->get('agent_site');
        $backupId = $this->storage->sanitizeId((string) $request->query('backup_id', ''));
        $record = \App\Models\SiteBackup::where('site_id', $site->id)
            ->where('backup_id', $backupId)
            ->where('status', 'stored')
            ->first();
        $path = $this->storage->finalPath($site, $backupId);
        if (! $record || ! is_file($path)) {
            return response()->json(['message' => 'Backup nicht auf dem Server gespeichert'], 404);
        }

        return response()->download($path, $backupId.'.zip', [
            'Content-Type' => 'application/zip',
            'X-WWC-Backup-Sha256' => (string) $record->sha256,
        ]);
    }

    public function delete(Request $request)
    {
        /** @var Site $site */
        $site = $request->attributes->get('agent_site');
        $data = $request->validate(['backup_id' => 'required|string|max:100']);
        $this->storage->delete($site, $data['backup_id']);

        return response()->json(['ok' => true]);
    }
}
