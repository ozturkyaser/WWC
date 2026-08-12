<?php

namespace App\Services;

use App\Models\Site;
use App\Models\SiteBackup;
use Illuminate\Support\Facades\Log;

/**
 * Stores site backup archives on the WWC server (off-site from the WP install).
 * Agents upload in base64 chunks; files live under storage/app/wwc-backups/{site}/.
 */
class BackupStorageService
{
    public function directory(Site $site): string
    {
        $dir = storage_path('app/wwc-backups/'.$site->id);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }

    public function finalPath(Site $site, string $backupId): string
    {
        return $this->directory($site).'/'.$this->sanitizeId($backupId).'.zip';
    }

    public function partPath(Site $site, string $backupId): string
    {
        return $this->finalPath($site, $backupId).'.part';
    }

    public function sanitizeId(string $backupId): string
    {
        return preg_replace('/[^a-zA-Z0-9\-_]/', '', $backupId) ?: 'backup';
    }

    /**
     * Begin (or restart) an upload. Returns the DB record.
     */
    public function init(Site $site, array $meta): SiteBackup
    {
        $backupId = $this->sanitizeId((string) ($meta['backup_id'] ?? ''));
        if ($backupId === '' || $backupId === 'backup') {
            abort(422, 'backup_id fehlt');
        }

        // Restart: drop stale partial file
        $part = $this->partPath($site, $backupId);
        if (is_file($part)) {
            @unlink($part);
        }

        return SiteBackup::updateOrCreate(
            ['site_id' => $site->id, 'backup_id' => $backupId],
            [
                'organization_id' => $site->organization_id,
                'type' => in_array($meta['type'] ?? 'full', ['full', 'incremental'], true) ? $meta['type'] : 'full',
                'label' => mb_substr((string) ($meta['label'] ?? ''), 0, 100) ?: null,
                'status' => 'uploading',
                'size_bytes' => max(0, (int) ($meta['size_bytes'] ?? 0)),
                'sha256' => preg_match('/^[a-f0-9]{64}$/', (string) ($meta['sha256'] ?? '')) ? $meta['sha256'] : null,
                'storage_path' => null,
                'wp_version' => mb_substr((string) ($meta['wp_version'] ?? ''), 0, 20) ?: null,
                'file_count' => max(0, (int) ($meta['file_count'] ?? 0)),
                'parent_backup_id' => mb_substr((string) ($meta['parent_backup_id'] ?? ''), 0, 100) ?: null,
                'backup_created_at' => $meta['created_at'] ?? null,
                'uploaded_at' => null,
            ]
        );
    }

    /**
     * Append a chunk. Offset must match the current partial size (sequential upload).
     */
    public function appendChunk(Site $site, string $backupId, int $offset, string $binary): array
    {
        $record = SiteBackup::where('site_id', $site->id)
            ->where('backup_id', $this->sanitizeId($backupId))
            ->where('status', 'uploading')
            ->first();
        if (! $record) {
            abort(404, 'Upload nicht initialisiert');
        }

        $part = $this->partPath($site, $backupId);
        $current = is_file($part) ? (int) filesize($part) : 0;
        if ($offset !== $current) {
            return ['ok' => false, 'expected_offset' => $current];
        }

        $written = file_put_contents($part, $binary, FILE_APPEND | LOCK_EX);
        if ($written === false || $written !== strlen($binary)) {
            abort(500, 'Chunk konnte nicht geschrieben werden');
        }

        return ['ok' => true, 'received_bytes' => $current + $written];
    }

    /**
     * Verify checksum + size and promote the partial file to the final archive.
     */
    public function complete(Site $site, string $backupId): SiteBackup
    {
        $backupId = $this->sanitizeId($backupId);
        $record = SiteBackup::where('site_id', $site->id)
            ->where('backup_id', $backupId)
            ->where('status', 'uploading')
            ->firstOrFail();

        $part = $this->partPath($site, $backupId);
        if (! is_file($part)) {
            abort(422, 'Keine Upload-Daten vorhanden');
        }

        $size = (int) filesize($part);
        if ($record->size_bytes > 0 && $size !== (int) $record->size_bytes) {
            @unlink($part);
            $record->update(['status' => 'failed']);
            abort(422, sprintf('Größe stimmt nicht (%d ≠ %d Bytes)', $size, $record->size_bytes));
        }
        if ($record->sha256 && ! hash_equals($record->sha256, (string) hash_file('sha256', $part))) {
            @unlink($part);
            $record->update(['status' => 'failed']);
            abort(422, 'SHA-256-Prüfsumme stimmt nicht');
        }

        $final = $this->finalPath($site, $backupId);
        if (! rename($part, $final)) {
            abort(500, 'Archiv konnte nicht abgelegt werden');
        }

        $record->update([
            'status' => 'stored',
            'size_bytes' => $size,
            'storage_path' => $final,
            'uploaded_at' => now(),
        ]);

        $this->prune($site);

        return $record->fresh();
    }

    public function delete(Site $site, string $backupId): bool
    {
        $backupId = $this->sanitizeId($backupId);
        $record = SiteBackup::where('site_id', $site->id)->where('backup_id', $backupId)->first();
        foreach ([$this->finalPath($site, $backupId), $this->partPath($site, $backupId)] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $record?->delete();

        return true;
    }

    public function deleteAllForSite(Site $site): void
    {
        SiteBackup::where('site_id', $site->id)->delete();
        $dir = storage_path('app/wwc-backups/'.$site->id);
        if (is_dir($dir)) {
            foreach (glob($dir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    /**
     * Keep the most recent N stored full backups (incrementals of kept fulls stay too).
     */
    public function prune(Site $site): void
    {
        $keep = max(1, (int) config('wwc.backups_keep_per_site', 5));
        $fulls = SiteBackup::where('site_id', $site->id)
            ->where('status', 'stored')
            ->where('type', 'full')
            ->orderByDesc('backup_created_at')
            ->get();

        $keptFullIds = $fulls->take($keep)->pluck('backup_id')->all();
        foreach ($fulls->slice($keep) as $old) {
            $this->deleteWithChildren($site, $old, $keptFullIds);
        }
    }

    private function deleteWithChildren(Site $site, SiteBackup $full, array $keptFullIds): void
    {
        $children = SiteBackup::where('site_id', $site->id)
            ->where('parent_backup_id', $full->backup_id)
            ->get();
        foreach ($children as $child) {
            $this->delete($site, $child->backup_id);
        }
        $this->delete($site, $full->backup_id);
        Log::info('backup pruned from server storage', ['site' => $site->id, 'backup' => $full->backup_id]);
    }
}
