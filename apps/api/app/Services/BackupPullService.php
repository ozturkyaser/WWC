<?php

namespace App\Services;

use App\Models\Site;
use App\Models\SiteBackup;
use RuntimeException;

/**
 * Holt ein lokales (mehrteiliges) Agent-Backup stückweise auf den WWC-Server.
 * Große Sites packen kein Mega-Export-Zip; die Teile bleiben files.zip, files-2.zip, …
 */
class BackupPullService
{
    public function __construct(
        private AgentClient $agent,
        private BackupStorageService $storage,
    ) {}

    /**
     * @param  (callable(string): void)|null  $onProgress
     */
    public function pullLatestFull(Site $site, ?callable $onProgress = null): SiteBackup
    {
        if (! $site->getHmacSecret()) {
            throw new RuntimeException('Site ist nicht verbunden.');
        }

        $listed = $this->agent->listBackupParts($site, 'latest-full');
        $backupId = $this->storage->sanitizeId((string) $listed['backup_id']);
        $files = $listed['files'];
        if ($backupId === '' || $backupId === 'backup' || $files === []) {
            throw new RuntimeException('Agent hat kein vollständiges Backup zum Holen.');
        }

        $hasSql = false;
        $hasFiles = false;
        foreach ($files as $file) {
            $name = (string) ($file['name'] ?? '');
            if ($name === 'database.sql') {
                $hasSql = true;
            }
            if ($name === 'files.zip' || preg_match('/^files-\d+\.zip$/', $name) === 1) {
                $hasFiles = true;
            }
        }
        if (! $hasSql || ! $hasFiles) {
            throw new RuntimeException('Backup auf der Kundenseite ist unvollständig (database.sql oder files.zip fehlt).');
        }

        $dir = $this->storage->payloadDir($site, $backupId);
        $total = count($files);
        foreach ($files as $i => $file) {
            $name = basename((string) ($file['name'] ?? ''));
            $expected = (int) ($file['size'] ?? 0);
            if ($name === '') {
                continue;
            }
            $target = $dir.'/'.$name;
            if (is_file($target) && ($expected <= 0 || (int) filesize($target) === $expected)) {
                $onProgress && $onProgress(sprintf('Backup %d/%d schon vorhanden: %s', $i + 1, $total, $name));
                continue;
            }
            $onProgress && $onProgress(sprintf('Backup vom Kundenserver holen %d/%d: %s', $i + 1, $total, $name));
            $this->agent->downloadBackupPartTo($site, $backupId, $name, $target);
            if ($expected > 0 && (int) filesize($target) !== $expected) {
                @unlink($target);
                throw new RuntimeException("Größe von {$name} stimmt nicht.");
            }
        }

        $size = 0;
        foreach (glob($dir.'/*') ?: [] as $path) {
            if (is_file($path)) {
                $size += (int) filesize($path);
            }
        }

        $record = SiteBackup::updateOrCreate(
            ['site_id' => $site->id, 'backup_id' => $backupId],
            [
                'organization_id' => $site->organization_id,
                'type' => in_array($listed['type'] ?? 'full', ['full', 'incremental'], true) ? $listed['type'] : 'full',
                'label' => 'vom Kundenserver geholt',
                'status' => 'stored',
                'size_bytes' => $size,
                'storage_path' => $dir,
                'wp_version' => isset($listed['wp_version']) ? mb_substr((string) $listed['wp_version'], 0, 20) : null,
                'file_count' => max(0, (int) ($listed['file_count'] ?? 0)),
                'backup_created_at' => $listed['created_at'] ?? now(),
                'uploaded_at' => now(),
            ]
        );

        return $record->fresh() ?? $record;
    }
}
