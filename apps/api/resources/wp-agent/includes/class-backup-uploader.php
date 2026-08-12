<?php

declare(strict_types=1);

/**
 * Uploads backup archives to the WWC server (off-site storage) and
 * downloads them back for restores. After a successful upload the heavy
 * local files are removed – only the small manifest.json stays so that
 * incremental backups keep working.
 */
final class WWC_Agent_Backup_Uploader
{
    private const CHUNK_BYTES = 4 * 1024 * 1024; // 4 MB raw per chunk

    /**
     * Upload a backup's export archive to the WWC server.
     * Reports progress between $pctFrom and $pctTo.
     */
    public static function upload(string $backupId, int $pctFrom = 90, int $pctTo = 97): array
    {
        if (! WWC_Agent_Config::is_paired()) {
            return ['ok' => false, 'error' => 'Agent nicht gepairt'];
        }

        $export = WWC_Agent_Backup::ensure_export($backupId);
        if (! ($export['ok'] ?? false)) {
            return $export;
        }
        $path = (string) $export['path'];
        $size = (int) $export['size_bytes'];
        $sha256 = (string) hash_file('sha256', $path);

        $dir = dirname($path);
        $manifest = json_decode((string) @file_get_contents($dir.'/manifest.json'), true) ?: [];

        WWC_Agent_Job_Progress::report($pctFrom, 'Backup zum WWC-Server hochladen… ('.self::format_bytes($size).')', true);

        $init = WWC_Agent_Api_Client::request('POST', '/backups/init', [
            'backup_id' => $backupId,
            'type' => (string) ($manifest['type'] ?? 'full'),
            'label' => (string) ($manifest['label'] ?? ''),
            'size_bytes' => $size,
            'sha256' => $sha256,
            'wp_version' => (string) ($manifest['wp_version'] ?? ''),
            'file_count' => (int) ($manifest['file_count'] ?? 0),
            'parent_backup_id' => (string) ($manifest['parent_id'] ?? ''),
            'created_at' => (string) ($manifest['created_at'] ?? ''),
        ], 60);
        if (is_wp_error($init)) {
            return ['ok' => false, 'error' => 'Upload-Init fehlgeschlagen: '.$init->get_error_message()];
        }

        $fh = fopen($path, 'rb');
        if (! $fh) {
            return ['ok' => false, 'error' => 'Export-Archiv nicht lesbar'];
        }

        $offset = 0;
        $chunkIndex = 0;
        $totalChunks = max(1, (int) ceil($size / self::CHUNK_BYTES));
        while (! feof($fh)) {
            $chunk = fread($fh, self::CHUNK_BYTES);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $chunkIndex++;

            $attempts = 0;
            do {
                $attempts++;
                $res = WWC_Agent_Api_Client::request('POST', '/backups/chunk', [
                    'backup_id' => $backupId,
                    'offset' => $offset,
                    'data' => base64_encode($chunk),
                ], 120);
                $retry = is_wp_error($res) && $attempts < 3;
                if ($retry) {
                    sleep(2);
                }
            } while ($retry);

            if (is_wp_error($res)) {
                fclose($fh);

                return ['ok' => false, 'error' => 'Chunk-Upload fehlgeschlagen: '.$res->get_error_message()];
            }

            $offset += strlen($chunk);
            @set_time_limit(600);
            $pct = $pctFrom + (int) floor(($offset / max(1, $size)) * max(1, $pctTo - 1 - $pctFrom));
            WWC_Agent_Job_Progress::report(
                min($pctTo - 1, $pct),
                sprintf('Upload %d/%d (%s / %s)', $chunkIndex, $totalChunks, self::format_bytes($offset), self::format_bytes($size))
            );
        }
        fclose($fh);

        $complete = WWC_Agent_Api_Client::request('POST', '/backups/complete', ['backup_id' => $backupId], 120);
        if (is_wp_error($complete)) {
            return ['ok' => false, 'error' => 'Upload-Abschluss fehlgeschlagen: '.$complete->get_error_message()];
        }

        self::cleanup_local($backupId, $size, $sha256);
        WWC_Agent_Job_Progress::report($pctTo, 'Backup auf WWC-Server gespeichert', true);
        WWC_Agent_Event_Queue::push('backup_offsite', 'Backup '.$backupId.' auf WWC-Server gespeichert', 'info', [
            'id' => $backupId,
            'size_bytes' => $size,
        ]);

        return ['ok' => true, 'offsite' => true, 'size_bytes' => $size, 'sha256' => $sha256];
    }

    /**
     * Remove heavy local files after upload; keep manifest.json (needed for
     * incremental diffs) and mark it as stored off-site.
     */
    private static function cleanup_local(string $backupId, int $size, string $sha256): void
    {
        $dir = WWC_Agent_Backup::root().'/'.$backupId;
        $manifestFile = $dir.'/manifest.json';
        $manifest = json_decode((string) @file_get_contents($manifestFile), true) ?: [];
        $manifest['offsite'] = true;
        $manifest['offsite_at'] = gmdate('c');
        $manifest['size_bytes'] = $size;
        $manifest['sha256'] = $sha256;
        @file_put_contents($manifestFile, wp_json_encode($manifest));

        foreach (['database.sql', 'files.zip', 'changed.zip', 'wwc-export.zip', 'files.empty'] as $name) {
            $file = $dir.'/'.$name;
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Make sure a backup's payload files exist locally (download from the
     * WWC server if they were cleaned up after an off-site upload).
     */
    public static function ensure_local(string $backupId): array
    {
        $dir = WWC_Agent_Backup::root().'/'.$backupId;
        $manifestFile = $dir.'/manifest.json';
        if (! file_exists($manifestFile)) {
            return ['ok' => false, 'error' => 'Backup '.$backupId.' nicht gefunden'];
        }
        $manifest = json_decode((string) file_get_contents($manifestFile), true) ?: [];

        $needsArchive = ! empty($manifest['archive']) && ! is_file($dir.'/'.$manifest['archive']);
        $needsDb = ! empty($manifest['database']) && ! is_file($dir.'/'.$manifest['database']);
        if (! $needsArchive && ! $needsDb) {
            return ['ok' => true, 'downloaded' => false];
        }

        if (empty($manifest['offsite'])) {
            return ['ok' => false, 'error' => 'Backup-Dateien fehlen lokal und sind nicht auf dem Server gespeichert'];
        }

        WWC_Agent_Job_Progress::log('Backup '.$backupId.' vom WWC-Server laden…');
        $tmp = $dir.'/wwc-download.zip';
        $result = self::download_to_file('/backups/download?backup_id='.rawurlencode($backupId), $tmp);
        if (! ($result['ok'] ?? false)) {
            return $result;
        }

        if (! empty($manifest['sha256']) && ! hash_equals((string) $manifest['sha256'], (string) hash_file('sha256', $tmp))) {
            @unlink($tmp);

            return ['ok' => false, 'error' => 'Prüfsumme des Server-Backups stimmt nicht'];
        }

        if (! class_exists('ZipArchive')) {
            @unlink($tmp);

            return ['ok' => false, 'error' => 'ZipArchive PHP extension missing'];
        }
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);

            return ['ok' => false, 'error' => 'Server-Backup-Archiv nicht lesbar'];
        }
        $zip->extractTo($dir);
        $zip->close();
        @unlink($tmp);

        // The archived manifest predates the upload – keep the off-site markers
        $extracted = json_decode((string) @file_get_contents($manifestFile), true) ?: [];
        $extracted['offsite'] = true;
        $extracted['offsite_at'] = $manifest['offsite_at'] ?? gmdate('c');
        $extracted['size_bytes'] = $manifest['size_bytes'] ?? null;
        $extracted['sha256'] = $manifest['sha256'] ?? null;
        @file_put_contents($manifestFile, wp_json_encode($extracted));

        WWC_Agent_Job_Progress::log('Backup '.$backupId.' vom Server wiederhergestellt (lokal verfügbar)');

        return ['ok' => true, 'downloaded' => true];
    }

    /**
     * HMAC-signed GET streamed into a file (wp_remote_get with stream option).
     */
    private static function download_to_file(string $path, string $target): array
    {
        $cfg = WWC_Agent_Config::all();
        $url = rtrim((string) $cfg['api_url'], '/').$path;
        $timestamp = (string) time();
        $nonce = wp_generate_password(32, false, false);
        $signPath = '/api/agent'.strtok($path, '?');
        $signature = WWC_Agent_Hmac::sign('GET', $signPath, $timestamp, $nonce, '', (string) $cfg['hmac_secret']);

        $response = wp_remote_get($url, [
            'timeout' => 600,
            'stream' => true,
            'filename' => $target,
            'headers' => [
                'Accept' => 'application/zip',
                'X-WWC-Site-Id' => (string) $cfg['site_id'],
                'X-WWC-Timestamp' => $timestamp,
                'X-WWC-Nonce' => $nonce,
                'X-WWC-Key-Id' => ($cfg['key_id'] ?? '') !== '' ? (string) $cfg['key_id'] : 'primary',
                'X-WWC-Signature' => $signature,
            ],
        ]);

        if (is_wp_error($response)) {
            @unlink($target);

            return ['ok' => false, 'error' => 'Download fehlgeschlagen: '.$response->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code >= 400 || ! is_file($target) || (int) filesize($target) === 0) {
            @unlink($target);

            return ['ok' => false, 'error' => 'Download fehlgeschlagen (HTTP '.$code.')'];
        }

        return ['ok' => true];
    }

    /** Notify the server that a backup was deleted on the agent. */
    public static function delete_remote(string $backupId): void
    {
        if (! WWC_Agent_Config::is_paired()) {
            return;
        }
        WWC_Agent_Api_Client::request('POST', '/backups/delete', ['backup_id' => $backupId], 30);
    }

    private static function format_bytes(int $bytes): string
    {
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1).' MB';
        }

        return round($bytes / (1024 * 1024 * 1024), 2).' GB';
    }
}
