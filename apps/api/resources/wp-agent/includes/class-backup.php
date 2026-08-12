<?php

declare(strict_types=1);

final class WWC_Agent_Backup
{
    public static function root(): string
    {
        $dir = trailingslashit(WP_CONTENT_DIR).'wwc-backups';
        if (! is_dir($dir)) {
            wp_mkdir_p($dir);
            file_put_contents($dir.'/.htaccess', "Deny from all\n");
            file_put_contents($dir.'/index.php', "<?php\n// Silence.\n");
        }

        return $dir;
    }

    public static function list(): array
    {
        $items = [];
        foreach (glob(self::root().'/*/manifest.json') ?: [] as $manifestFile) {
            $json = json_decode((string) file_get_contents($manifestFile), true);
            if (! is_array($json)) {
                continue;
            }
            $dir = dirname($manifestFile);
            $json['path'] = $dir;
            // Off-site backups keep only manifest.json locally – size comes from the manifest
            if (empty($json['offsite']) || empty($json['size_bytes'])) {
                $json['size_bytes'] = self::dir_size($dir);
            }
            $json['offsite'] = ! empty($json['offsite']);
            // File map is huge and only needed on disk (incremental diffs) – not in listings
            unset($json['files']);
            $items[] = $json;
        }
        usort($items, static fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return ['ok' => true, 'backups' => $items];
    }

    /**
     * Backup exclusion settings: defaults ← saved option ← per-job overrides.
     *
     * @return array{max_file_bytes:int, excludes:list<string>}
     */
    public static function settings(array $overrides = []): array
    {
        $defaults = [
            'max_file_mb' => 100, // skip single files larger than this (0 = unlimited)
            'excludes' => [],     // additional relative path prefixes, e.g. "wp-content/uploads/videos/"
        ];
        $saved = get_option('wwc_agent_backup_settings');
        $merged = array_merge($defaults, is_array($saved) ? $saved : [], $overrides);

        $maxMb = max(0, (int) ($merged['max_file_mb'] ?? 0));
        $excludes = [];
        foreach ((array) ($merged['excludes'] ?? []) as $entry) {
            // Entries may be directories or single files (relative to WP root)
            $entry = strtolower(trim(str_replace('\\', '/', (string) $entry), '/ '));
            if ($entry !== '') {
                $excludes[] = $entry;
            }
        }

        return apply_filters('wwc_agent_backup_settings', [
            'max_file_bytes' => $maxMb * 1024 * 1024,
            'excludes' => $excludes,
        ]);
    }

    /**
     * Pre-backup analysis: scans the whole install and reports what a backup
     * would contain, the largest files/directories and what gets skipped –
     * so exclusions can be chosen in the portal before running the backup.
     */
    public static function scan(array $options = []): array
    {
        @set_time_limit(600);
        $settings = self::settings($options);
        $maxBytes = (int) $settings['max_file_bytes'];
        $excludes = (array) $settings['excludes'];

        WWC_Agent_Job_Progress::report(5, 'Backup-Analyse: Dateien scannen…', true);

        $includedBytes = 0;
        $includedFiles = 0;
        $excludedBytes = 0;
        $excludedFiles = 0;
        $autoBytes = 0;
        $autoFiles = 0;
        $autoGroups = [];
        $topFiles = [];
        $dirSizes = [];
        $scanned = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(ABSPATH, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }
            $absolute = $file->getRealPath();
            if ($absolute === false) {
                continue;
            }
            $rel = ltrim(str_replace('\\', '/', substr($absolute, strlen(rtrim(ABSPATH, '/\\')))), '/');
            $size = (int) $file->getSize();
            $scanned++;
            if ($scanned % 800 === 0) {
                WWC_Agent_Job_Progress::report(min(85, 5 + (int) floor($scanned / 400)), 'Analyse… '.$scanned.' Dateien');
            }

            if (self::should_skip($rel)) {
                $autoFiles++;
                $autoBytes += $size;
                $parts = explode('/', $rel);
                $group = implode('/', array_slice($parts, 0, min(2, count($parts) - 1))) ?: $parts[0];
                $autoGroups[$group] = ($autoGroups[$group] ?? 0) + $size;
                continue;
            }

            $relLower = strtolower($rel);
            $status = 'included';
            foreach ($excludes as $entry) {
                if ($relLower === $entry || str_starts_with($relLower, $entry.'/')) {
                    $status = 'excluded';
                    break;
                }
            }
            if ($status === 'included' && $maxBytes > 0 && $size > $maxBytes) {
                $status = 'too_large';
            }

            if ($status === 'included') {
                $includedFiles++;
                $includedBytes += $size;
            } else {
                $excludedFiles++;
                $excludedBytes += $size;
            }

            // Track top files (everything above 1 MB is a candidate)
            if ($size >= 1024 * 1024) {
                $topFiles[] = ['path' => $rel, 'size_bytes' => $size, 'status' => $status];
            }

            $parts = explode('/', $rel);
            if (count($parts) > 1) {
                $dir = implode('/', array_slice($parts, 0, min(3, count($parts) - 1)));
                $dirSizes[$dir] = ($dirSizes[$dir] ?? 0) + $size;
            }
        }

        usort($topFiles, static fn ($a, $b) => $b['size_bytes'] <=> $a['size_bytes']);
        $topFiles = array_slice($topFiles, 0, 30);

        arsort($dirSizes);
        $topDirs = [];
        foreach (array_slice($dirSizes, 0, 12, true) as $dir => $bytes) {
            $topDirs[] = ['path' => $dir, 'size_bytes' => $bytes];
        }

        arsort($autoGroups);
        $autoList = [];
        foreach (array_slice($autoGroups, 0, 8, true) as $group => $bytes) {
            $autoList[] = ['path' => $group, 'size_bytes' => $bytes];
        }

        // Rough DB size for the estimate
        global $wpdb;
        $dbBytes = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s',
            defined('DB_NAME') ? DB_NAME : ''
        ));

        WWC_Agent_Job_Progress::report(96, sprintf(
            'Analyse fertig: %s Backup-Größe (%d Dateien), %s ausgeschlossen',
            self::format_bytes($includedBytes + $dbBytes),
            $includedFiles,
            self::format_bytes($excludedBytes)
        ), true);

        return [
            'ok' => true,
            'scanned_at' => gmdate('c'),
            'included' => ['files' => $includedFiles, 'bytes' => $includedBytes],
            'excluded' => ['files' => $excludedFiles, 'bytes' => $excludedBytes],
            'auto_skipped' => ['files' => $autoFiles, 'bytes' => $autoBytes, 'groups' => $autoList],
            'db_bytes' => $dbBytes,
            'estimated_backup_bytes' => $includedBytes + $dbBytes,
            'top_files' => $topFiles,
            'top_dirs' => $topDirs,
            'settings' => [
                'max_file_mb' => $maxBytes > 0 ? (int) round($maxBytes / (1024 * 1024)) : 0,
                'excludes' => $excludes,
            ],
        ];
    }

    public static function create_full(string $label = 'manual', array $options = []): array
    {
        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        WWC_Agent_Job_Progress::report(4, 'Full-Backup starten…', true);
        WWC_Agent_Job_Progress::log('Backup-ID wird erzeugt, Zielverzeichnis anlegen');

        $id = 'full-'.gmdate('Ymd-His').'-'.wp_generate_password(6, false, false);
        $dir = self::root().'/'.$id;
        wp_mkdir_p($dir);
        WWC_Agent_Job_Progress::log('Ziel: '.$dir, 6);

        $dbFile = $dir.'/database.sql';
        $filesZip = $dir.'/files.zip';
        WWC_Agent_Job_Progress::report(12, 'Datenbank exportieren…', true);
        $db = self::export_database($dbFile);
        if (! $db['ok']) {
            self::rrmdir($dir);

            return $db;
        }
        WWC_Agent_Job_Progress::log(
            'Datenbank exportiert ('.(int) ($db['tables'] ?? 0).' Tabellen, '.self::format_bytes((int) ($db['bytes'] ?? 0)).')',
            28,
            true
        );

        WWC_Agent_Job_Progress::report(32, 'Dateien scannen…', true);
        $settings = self::settings($options);
        $skipped = ['count' => 0, 'bytes' => 0, 'samples' => []];
        $fileMap = self::build_file_map($settings, $skipped);
        $fileCount = count($fileMap);
        WWC_Agent_Job_Progress::log($fileCount.' Dateien erfasst', 48, true);
        if ($skipped['count'] > 0) {
            WWC_Agent_Job_Progress::log(sprintf(
                '%d Datei(en) ausgeschlossen (%s gespart): %s',
                $skipped['count'],
                self::format_bytes((int) $skipped['bytes']),
                implode(', ', array_slice($skipped['samples'], 0, 5)).($skipped['count'] > 5 ? ', …' : '')
            ));
        }

        WWC_Agent_Job_Progress::report(50, 'Dateien in ZIP packen…', true);
        $zip = self::zip_paths($filesZip, array_keys($fileMap), ABSPATH, 50, 86);
        if (! $zip['ok']) {
            self::rrmdir($dir);

            return $zip;
        }
        WWC_Agent_Job_Progress::log(
            'ZIP fertig ('.self::format_bytes((int) ($zip['bytes'] ?? @filesize($filesZip))).', '.(int) ($zip['added'] ?? $fileCount).' Dateien)',
            88,
            true
        );

        WWC_Agent_Job_Progress::report(90, 'Manifest schreiben…', true);
        $manifest = [
            'id' => $id,
            'type' => 'full',
            'label' => $label,
            'created_at' => gmdate('c'),
            'wp_version' => get_bloginfo('version'),
            'site_url' => home_url('/'),
            'parent_id' => null,
            'file_count' => $fileCount,
            'files' => $fileMap,
            'database' => 'database.sql',
            'archive' => 'files.zip',
            'skipped' => ['count' => $skipped['count'], 'bytes' => $skipped['bytes']],
            'settings' => ['max_file_bytes' => $settings['max_file_bytes'], 'excludes' => $settings['excludes']],
        ];
        file_put_contents($dir.'/manifest.json', wp_json_encode($manifest));
        $sizeBytes = self::dir_size($dir);

        WWC_Agent_Event_Queue::push('backup_created', 'Full backup '.$id, 'info', ['id' => $id, 'type' => 'full']);

        $offsite = WWC_Agent_Backup_Uploader::upload($id, 91, 96);
        if (! ($offsite['ok'] ?? false)) {
            WWC_Agent_Job_Progress::log('Off-site-Upload fehlgeschlagen (Backup bleibt lokal): '.($offsite['error'] ?? '?'), 96, true);
            WWC_Agent_Event_Queue::push('backup_offsite_failed', 'Off-site upload failed for '.$id, 'warning', [
                'id' => $id,
                'error' => $offsite['error'] ?? null,
            ]);
        }
        WWC_Agent_Job_Progress::report(96, 'Full-Backup fertiggestellt', true);

        return [
            'ok' => true,
            'backup' => [
                'id' => $id,
                'type' => 'full',
                'label' => $label,
                'created_at' => $manifest['created_at'],
                'size_bytes' => $sizeBytes,
                'file_count' => $fileCount,
                'offsite' => (bool) ($offsite['ok'] ?? false),
            ],
        ];
    }

    public static function create_incremental(string $label = 'auto', array $options = []): array
    {
        $list = self::list();
        $parent = null;
        foreach ($list['backups'] as $b) {
            if (($b['type'] ?? '') === 'full') {
                $parent = $b;
                break;
            }
        }
        if (! $parent) {
            return self::create_full($label.'-full-base', $options);
        }

        @set_time_limit(600);
        $parentManifest = json_decode((string) file_get_contents(self::root().'/'.$parent['id'].'/manifest.json'), true);
        $oldFiles = is_array($parentManifest['files'] ?? null) ? $parentManifest['files'] : [];
        $current = self::build_file_map(self::settings($options));
        $changed = [];
        foreach ($current as $rel => $meta) {
            if (! isset($oldFiles[$rel]) || ($oldFiles[$rel]['hash'] ?? '') !== ($meta['hash'] ?? '')) {
                $changed[$rel] = $meta;
            }
        }

        $id = 'incr-'.gmdate('Ymd-His').'-'.wp_generate_password(6, false, false);
        $dir = self::root().'/'.$id;
        wp_mkdir_p($dir);
        $dbFile = $dir.'/database.sql';
        $db = self::export_database($dbFile);
        if (! $db['ok']) {
            self::rrmdir($dir);

            return $db;
        }

        $filesZip = $dir.'/files.zip';
        if ($changed !== []) {
            $zip = self::zip_paths($filesZip, array_keys($changed), ABSPATH);
            if (! $zip['ok']) {
                self::rrmdir($dir);

                return $zip;
            }
        } else {
            // empty archive marker
            file_put_contents($dir.'/files.empty', '1');
        }

        $manifest = [
            'id' => $id,
            'type' => 'incremental',
            'label' => $label,
            'created_at' => gmdate('c'),
            'wp_version' => get_bloginfo('version'),
            'site_url' => home_url('/'),
            'parent_id' => $parent['id'],
            'file_count' => count($changed),
            'files' => $changed,
            'database' => 'database.sql',
            'archive' => file_exists($filesZip) ? 'files.zip' : null,
        ];
        file_put_contents($dir.'/manifest.json', wp_json_encode($manifest));
        $sizeBytes = self::dir_size($dir);
        WWC_Agent_Event_Queue::push('backup_created', 'Incremental backup '.$id, 'info', ['id' => $id, 'type' => 'incremental']);

        $offsite = WWC_Agent_Backup_Uploader::upload($id, 91, 96);
        if (! ($offsite['ok'] ?? false)) {
            WWC_Agent_Job_Progress::log('Off-site-Upload fehlgeschlagen (Backup bleibt lokal): '.($offsite['error'] ?? '?'));
        }

        return [
            'ok' => true,
            'backup' => [
                'id' => $id,
                'type' => 'incremental',
                'label' => $label,
                'parent_id' => $parent['id'],
                'created_at' => $manifest['created_at'],
                'size_bytes' => $sizeBytes,
                'file_count' => count($changed),
                'offsite' => (bool) ($offsite['ok'] ?? false),
            ],
        ];
    }

    public static function latest_full(): ?array
    {
        foreach (self::list()['backups'] as $b) {
            if (($b['type'] ?? '') === 'full') {
                return $b;
            }
        }

        return null;
    }

    public static function ensure_export(string $backupId): array
    {
        $backupId = self::sanitize_id($backupId);
        $dir = self::root().'/'.$backupId;
        if (! is_dir($dir) || ! file_exists($dir.'/manifest.json')) {
            return ['ok' => false, 'error' => 'Backup not found'];
        }

        $export = $dir.'/wwc-export.zip';
        $manifestMtime = (int) filemtime($dir.'/manifest.json');
        if (file_exists($export) && (int) filemtime($export) >= $manifestMtime) {
            return [
                'ok' => true,
                'path' => $export,
                'filename' => $backupId.'.zip',
                'size_bytes' => (int) filesize($export),
                'backup_id' => $backupId,
            ];
        }

        if (! class_exists('ZipArchive')) {
            return ['ok' => false, 'error' => 'ZipArchive PHP extension missing'];
        }

        $zip = new ZipArchive();
        if ($zip->open($export, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return ['ok' => false, 'error' => 'Cannot create export zip'];
        }
        foreach (['manifest.json', 'database.sql', 'files.zip', 'changed.zip'] as $name) {
            $file = $dir.'/'.$name;
            if (is_file($file)) {
                $zip->addFile($file, $name);
            }
        }
        $zip->close();

        return [
            'ok' => true,
            'path' => $export,
            'filename' => $backupId.'.zip',
            'size_bytes' => (int) filesize($export),
            'backup_id' => $backupId,
        ];
    }

    public static function delete(string $backupId): array
    {
        $backupId = self::sanitize_id($backupId);
        $dir = self::root().'/'.$backupId;
        if (! is_dir($dir)) {
            return ['ok' => false, 'error' => 'Backup not found'];
        }
        self::rrmdir($dir);
        WWC_Agent_Backup_Uploader::delete_remote($backupId);
        WWC_Agent_Event_Queue::push('backup_deleted', 'Backup '.$backupId.' deleted', 'info', ['id' => $backupId]);

        return ['ok' => true, 'deleted' => $backupId];
    }

    public static function delete_all(): array
    {
        $deleted = [];
        foreach (self::list()['backups'] as $b) {
            $id = (string) ($b['id'] ?? '');
            if ($id === '') {
                continue;
            }
            self::rrmdir(self::root().'/'.$id);
            WWC_Agent_Backup_Uploader::delete_remote($id);
            $deleted[] = $id;
        }

        return ['ok' => true, 'deleted' => $deleted, 'count' => count($deleted)];
    }

    /**
     * Remove all WWC-managed data on the site (staging + backups).
     */
    public static function purge_managed(): array
    {
        $staging = ['ok' => true, 'skipped' => true];
        if (class_exists('WWC_Agent_Staging')) {
            $staging = WWC_Agent_Staging::destroy();
        }
        $backups = self::delete_all();
        WWC_Agent_Event_Queue::push('wwc_purged', 'WWC staging and backups purged', 'warning', [
            'backups_deleted' => $backups['count'] ?? 0,
        ]);

        return [
            'ok' => true,
            'staging' => $staging,
            'backups' => $backups,
        ];
    }

    private static function sanitize_id(string $backupId): string
    {
        return preg_replace('/[^a-zA-Z0-9\-_]/', '', $backupId) ?: '';
    }

    public static function restore(string $backupId): array
    {
        @set_time_limit(900);
        $manifestFile = self::root().'/'.$backupId.'/manifest.json';
        if (! file_exists($manifestFile)) {
            return ['ok' => false, 'error' => 'Backup not found'];
        }
        $manifest = json_decode((string) file_get_contents($manifestFile), true);
        if (! is_array($manifest)) {
            return ['ok' => false, 'error' => 'Invalid manifest'];
        }

        // Safety snapshot before restore
        $safety = self::create_incremental('pre-restore-safety');
        if (! ($safety['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'Could not create safety backup before restore', 'details' => $safety];
        }

        $chain = [$manifest];
        if (($manifest['type'] ?? '') === 'incremental' && ! empty($manifest['parent_id'])) {
            $parentFile = self::root().'/'.$manifest['parent_id'].'/manifest.json';
            if (! file_exists($parentFile)) {
                return ['ok' => false, 'error' => 'Parent full backup missing'];
            }
            $parent = json_decode((string) file_get_contents($parentFile), true);
            $chain = [$parent, $manifest];
        }

        // Off-site backups: fetch payload files back from the WWC server first
        foreach ($chain as $step) {
            $local = WWC_Agent_Backup_Uploader::ensure_local((string) $step['id']);
            if (! ($local['ok'] ?? false)) {
                return ['ok' => false, 'error' => 'Backup nicht verfügbar: '.($local['error'] ?? $step['id'])];
            }
        }

        foreach ($chain as $step) {
            $dir = self::root().'/'.$step['id'];
            if (! empty($step['archive']) && file_exists($dir.'/'.$step['archive'])) {
                $unzip = self::unzip_to($dir.'/'.$step['archive'], ABSPATH);
                if (! $unzip['ok']) {
                    return $unzip;
                }
            }
        }

        $last = end($chain);
        $dbPath = self::root().'/'.$last['id'].'/database.sql';
        $import = self::import_database($dbPath);
        if (! $import['ok']) {
            return $import;
        }

        wp_cache_flush();
        WWC_Agent_Event_Queue::push('backup_restored', 'Restored backup '.$backupId, 'warning', ['id' => $backupId]);

        return ['ok' => true, 'restored' => $backupId, 'safety_backup' => $safety['backup']['id'] ?? null];
    }

    /** @return array<string, array{hash:string,mtime:int,size:int}> */
    private static function build_file_map(array $settings = [], ?array &$skipped = null): array
    {
        $maxBytes = (int) ($settings['max_file_bytes'] ?? 0);
        $excludes = (array) ($settings['excludes'] ?? []);

        $map = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(ABSPATH, FilesystemIterator::SKIP_DOTS)
        );
        $scanned = 0;
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }
            $absolute = $file->getRealPath();
            if ($absolute === false) {
                continue;
            }
            $rel = ltrim(str_replace('\\', '/', substr($absolute, strlen(rtrim(ABSPATH, '/\\')))), '/');
            if (self::should_skip($rel)) {
                continue;
            }
            $size = (int) $file->getSize();

            // Custom excludes + per-file size cap keep backups slim
            $skipReason = null;
            $relLower = strtolower($rel);
            foreach ($excludes as $entry) {
                if ($relLower === $entry || str_starts_with($relLower, $entry.'/')) {
                    $skipReason = 'exclude';
                    break;
                }
            }
            if ($skipReason === null && $maxBytes > 0 && $size > $maxBytes) {
                $skipReason = 'size';
            }
            if ($skipReason !== null) {
                if ($skipped !== null) {
                    $skipped['count']++;
                    $skipped['bytes'] += $size;
                    if (count($skipped['samples']) < 10) {
                        $skipped['samples'][] = $rel.' ('.self::format_bytes($size).')';
                    }
                }
                continue;
            }

            $hash = $size > 20 * 1024 * 1024
                ? 'size:'.$size.':mtime:'.$file->getMTime()
                : (md5_file($absolute) ?: ('mtime:'.$file->getMTime()));
            $map[$rel] = [
                'hash' => $hash,
                'mtime' => (int) $file->getMTime(),
                'size' => $size,
            ];
            $scanned++;
            if ($scanned % 400 === 0) {
                $pct = min(47, 32 + (int) floor(($scanned / max(400, $scanned)) * 14));
                WWC_Agent_Job_Progress::report($pct, 'Dateien scannen… '.$scanned);
            }
        }

        return $map;
    }

    private static function should_skip(string $rel): bool
    {
        $rel = strtolower($rel);
        $skipPrefixes = [
            // WWC-managed data
            'wp-content/wwc-backups/',
            'wp-content/wwc-staging/',
            // Caches / temp
            'wp-content/cache/',
            'wp-content/upgrade/',
            'wp-content/et-cache/',
            'wp-content/litespeed/',
            'wp-content/wp-rocket-config/',
            'wp-content/uploads/wc-logs/',
            // Backups anderer Plugins (sonst wachsen Backups exponentiell)
            'wp-content/updraft/',
            'wp-content/ai1wm-backups/',
            'wp-content/backups-dup-pro/',
            'wp-content/backups-dup-lite/',
            'wp-content/wpvividbackups/',
            'wp-content/backuply/',
            'wp-content/backup-db/',
            'wp-content/backupbuddy_backups/',
            'wp-content/uploads/backwpup-',
            'wp-content/uploads/wp-staging/',
            // Entwicklung
            '.git/',
            'node_modules/',
        ];
        foreach ($skipPrefixes as $prefix) {
            if (str_starts_with($rel, $prefix)) {
                return true;
            }
        }

        // Backup-Archive anderer Tools im Root/wp-content
        if (str_ends_with($rel, '.wpress')) {
            return true;
        }

        return str_ends_with($rel, '.log') || str_ends_with($rel, '.tmp');
    }

    private static function zip_paths(string $zipFile, array $relativePaths, string $base, int $pctFrom = 50, int $pctTo = 86): array
    {
        @set_time_limit(900);
        $base = rtrim($base, '/\\').'/';
        $paths = [];
        foreach ($relativePaths as $rel) {
            $relNorm = ltrim(str_replace('\\', '/', (string) $rel), '/');
            if ($relNorm === '') {
                continue;
            }
            $abs = $base.$relNorm;
            if (is_file($abs) && is_readable($abs)) {
                $paths[] = $relNorm;
            }
        }
        $total = max(1, count($paths));
        if ($paths === []) {
            // empty zip marker
            if (class_exists('ZipArchive')) {
                $z = new ZipArchive();
                if ($z->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                    $z->addFromString('.wwc-empty', '');
                    $z->close();
                }
            }

            return ['ok' => true, 'added' => 0, 'bytes' => is_file($zipFile) ? (int) filesize($zipFile) : 0];
        }

        // Prefer system zip: grows archive in chunks, no silent multi-GB close() hang.
        $cli = self::zip_paths_cli($zipFile, $paths, $base, $pctFrom, $pctTo);
        if ($cli !== null) {
            return $cli;
        }

        return self::zip_paths_php($zipFile, $paths, $base, $pctFrom, $pctTo);
    }

    /**
     * @param  list<string>  $paths  relative paths
     * @return array{ok:bool,added?:int,bytes?:int,error?:string}|null  null = CLI unavailable
     */
    private static function zip_paths_cli(string $zipFile, array $paths, string $base, int $pctFrom, int $pctTo): ?array
    {
        $bin = self::find_binary('zip');
        if ($bin === null || ! self::shell_available()) {
            return null;
        }

        if (is_file($zipFile)) {
            @unlink($zipFile);
        }

        $chunkSize = 250;
        $chunks = array_chunk($paths, $chunkSize);
        $added = 0;
        $total = max(1, count($paths));
        $cwd = rtrim($base, '/\\');

        foreach ($chunks as $index => $chunk) {
            $listFile = tempnam(sys_get_temp_dir(), 'wwczip');
            if ($listFile === false) {
                return ['ok' => false, 'error' => 'Cannot create temp file list'];
            }
            $fh = fopen($listFile, 'wb');
            if (! $fh) {
                @unlink($listFile);

                return ['ok' => false, 'error' => 'Cannot write temp file list'];
            }
            foreach ($chunk as $rel) {
                // zip -@ wants one path per line, relative to cwd
                fwrite($fh, $rel."\n");
            }
            fclose($fh);

            $grow = $index > 0 ? ' -g' : '';
            // -0 = store only (no deflate) — dramatically faster on large media
            $cmd = 'cd '.escapeshellarg($cwd).
                ' && '.escapeshellarg($bin).' -0'.$grow.' -q '.escapeshellarg($zipFile).
                ' -@ < '.escapeshellarg($listFile).' 2>&1';

            WWC_Agent_Job_Progress::report(
                (int) round($pctFrom + ($added / $total) * ($pctTo - $pctFrom)),
                'System-ZIP Chunk '.($index + 1).'/'.count($chunks).' ('.$added.'/'.$total.')',
                true
            );

            $out = [];
            $code = 0;
            @exec($cmd, $out, $code);
            @unlink($listFile);
            @set_time_limit(900);

            // zip exit 0=ok, 12=nothing to do; treat others carefully
            if ($code !== 0 && $code !== 12) {
                // Fall back to PHP if first chunk fails hard
                if ($index === 0) {
                    @unlink($zipFile);

                    return null;
                }

                return [
                    'ok' => false,
                    'error' => 'zip CLI failed (code '.$code.'): '.mb_substr(implode(' ', $out), 0, 200),
                ];
            }

            $added += count($chunk);
            WWC_Agent_Job_Progress::report(
                (int) round($pctFrom + ($added / $total) * ($pctTo - $pctFrom)),
                'System-ZIP '.$added.'/'.$total.' ('.(int) round(($added / $total) * 100).'%)',
                true
            );
        }

        WWC_Agent_Job_Progress::report($pctTo, 'System-ZIP fertig', true);

        return [
            'ok' => true,
            'added' => $added,
            'bytes' => is_file($zipFile) ? (int) filesize($zipFile) : 0,
            'engine' => 'cli-zip',
        ];
    }

    /**
     * ZipArchive fallback: flush by bytes / isolate large files so close() never stalls.
     *
     * @param  list<string>  $paths
     */
    private static function zip_paths_php(string $zipFile, array $paths, string $base, int $pctFrom, int $pctTo): array
    {
        if (! class_exists('ZipArchive')) {
            return ['ok' => false, 'error' => 'ZipArchive PHP extension missing'];
        }

        if (is_file($zipFile)) {
            @unlink($zipFile);
        }

        $base = rtrim($base, '/\\').'/';
        $total = max(1, count($paths));
        $added = 0;
        $batchCount = 0;
        $batchBytes = 0;
        $maxBatchFiles = 80;
        $maxBatchBytes = 12 * 1024 * 1024; // 12 MB per close()
        $largeFile = 3 * 1024 * 1024; // flush alone if >= 3 MB

        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return ['ok' => false, 'error' => 'Cannot create zip'];
        }
        $open = true;

        $closeOnly = static function () use (&$zip, &$open): void {
            if ($open) {
                $zip->close();
                $open = false;
            }
        };
        $reopen = static function () use (&$zip, &$open, $zipFile): bool {
            $zip = new ZipArchive();
            if ($zip->open($zipFile, ZipArchive::CREATE) !== true) {
                $open = false;

                return false;
            }
            $open = true;

            return true;
        };
        $flush = static function (string $why) use (&$closeOnly, &$reopen, &$batchCount, &$batchBytes, $pctFrom, $pctTo, &$added, $total): bool {
            if ($batchCount === 0) {
                return true;
            }
            $pct = (int) round($pctFrom + ($added / $total) * ($pctTo - $pctFrom));
            WWC_Agent_Job_Progress::report(
                $pct,
                'ZIP Flush '.$why.' · '.$added.'/'.$total.' · '.self::format_bytes($batchBytes),
                true
            );
            $closeOnly();
            $batchCount = 0;
            $batchBytes = 0;
            @set_time_limit(900);
            if (! $reopen()) {
                return false;
            }

            return true;
        };

        foreach ($paths as $relNorm) {
            $abs = $base.$relNorm;
            $size = (int) @filesize($abs);

            // Large file: flush pending, then write this file alone
            if ($size >= $largeFile) {
                if (! $flush('vor großer Datei')) {
                    return ['ok' => false, 'error' => 'Cannot reopen zip'];
                }
                WWC_Agent_Job_Progress::report(
                    (int) round($pctFrom + ($added / $total) * ($pctTo - $pctFrom)),
                    'Große Datei: '.$relNorm.' ('.self::format_bytes($size).')',
                    true
                );
                $zip->addFile($abs, $relNorm);
                if (method_exists($zip, 'setCompressionName')) {
                    $zip->setCompressionName($relNorm, ZipArchive::CM_STORE);
                }
                $added++;
                $closeOnly();
                @set_time_limit(900);
                if (! $reopen()) {
                    return ['ok' => false, 'error' => 'Cannot reopen zip after large file'];
                }
                continue;
            }

            $zip->addFile($abs, $relNorm);
            if (method_exists($zip, 'setCompressionName')) {
                $zip->setCompressionName($relNorm, ZipArchive::CM_STORE);
            }
            $added++;
            $batchCount++;
            $batchBytes += max(0, $size);

            if ($added % 200 === 0) {
                WWC_Agent_Job_Progress::report(
                    (int) round($pctFrom + ($added / $total) * ($pctTo - $pctFrom)),
                    'ZIP sammeln '.$added.'/'.$total.' ('.(int) round(($added / $total) * 100).'%)'
                );
            }

            if ($batchCount >= $maxBatchFiles || $batchBytes >= $maxBatchBytes) {
                if (! $flush('Batch')) {
                    return ['ok' => false, 'error' => 'Cannot reopen zip after batch'];
                }
            }
        }

        if ($batchCount > 0) {
            WWC_Agent_Job_Progress::report($pctTo - 1, 'ZIP letzter Flush…', true);
            $closeOnly();
        } elseif ($open) {
            $closeOnly();
        }

        WWC_Agent_Job_Progress::report($pctTo, 'ZIP geschrieben', true);

        return [
            'ok' => true,
            'added' => $added,
            'bytes' => is_file($zipFile) ? (int) filesize($zipFile) : 0,
            'engine' => 'php-zip',
        ];
    }

    private static function shell_available(): bool
    {
        if (! function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array('exec', $disabled, true);
    }

    private static function find_binary(string $name): ?string
    {
        if (! self::shell_available()) {
            return null;
        }
        $out = [];
        $code = 0;
        @exec('command -v '.escapeshellarg($name).' 2>/dev/null', $out, $code);
        if ($code === 0 && ! empty($out[0]) && is_executable(trim($out[0]))) {
            return trim($out[0]);
        }
        foreach (['/usr/bin/'.$name, '/bin/'.$name, '/usr/local/bin/'.$name] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    private static function unzip_to(string $zipFile, string $dest): array
    {
        if (! class_exists('ZipArchive')) {
            return ['ok' => false, 'error' => 'ZipArchive PHP extension missing'];
        }
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            return ['ok' => false, 'error' => 'Cannot open zip'];
        }
        $zip->extractTo(rtrim($dest, '/\\'));
        $zip->close();

        return ['ok' => true];
    }

    private static function export_database(string $targetFile): array
    {
        global $wpdb;
        $fh = fopen($targetFile, 'wb');
        if (! $fh) {
            return ['ok' => false, 'error' => 'Cannot write database dump'];
        }
        fwrite($fh, "-- WWC Agent DB dump\n-- ".gmdate('c')."\nSET NAMES utf8mb4;\nSET foreign_key_checks=0;\n\n");
        $tables = $wpdb->get_col('SHOW TABLES');
        if (! is_array($tables)) {
            fclose($fh);

            return ['ok' => false, 'error' => 'Could not list tables'];
        }
        $total = max(1, count($tables));
        $i = 0;
        foreach ($tables as $table) {
            $i++;
            $create = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
            if (! $create) {
                continue;
            }
            fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n{$create[1]};\n\n");
            $rows = $wpdb->get_results("SELECT * FROM `{$table}`", ARRAY_A);
            if (! $rows) {
                if ($i === 1 || $i % 5 === 0 || $i === $total) {
                    WWC_Agent_Job_Progress::report(12 + (int) round(($i / $total) * 14), 'DB-Export '.$i.'/'.$total.': '.$table);
                }
                continue;
            }
            foreach ($rows as $row) {
                $vals = [];
                foreach ($row as $v) {
                    if ($v === null) {
                        $vals[] = 'NULL';
                    } else {
                        $vals[] = "'".$wpdb->_real_escape((string) $v)."'";
                    }
                }
                fwrite($fh, 'INSERT INTO `'.$table.'` VALUES ('.implode(',', $vals).");\n");
            }
            fwrite($fh, "\n");
            if ($i === 1 || $i % 5 === 0 || $i === $total) {
                WWC_Agent_Job_Progress::report(12 + (int) round(($i / $total) * 14), 'DB-Export '.$i.'/'.$total.': '.$table);
            }
        }
        fwrite($fh, "SET foreign_key_checks=1;\n");
        fclose($fh);

        return [
            'ok' => true,
            'tables' => count($tables),
            'bytes' => is_file($targetFile) ? (int) filesize($targetFile) : 0,
        ];
    }

    private static function format_bytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / (1024 * 1024), 1).' MB';
    }

    private static function import_database(string $sqlFile): array
    {
        global $wpdb;
        if (! file_exists($sqlFile)) {
            return ['ok' => false, 'error' => 'SQL file missing'];
        }
        $sql = file_get_contents($sqlFile);
        if ($sql === false || $sql === '') {
            return ['ok' => false, 'error' => 'Empty SQL file'];
        }
        // Split on ";\n" while keeping it simple for our dump format
        $statements = preg_split('/;\s*\n/', $sql) ?: [];
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '' || str_starts_with($statement, '--')) {
                continue;
            }
            $wpdb->query($statement);
        }

        return ['ok' => true];
    }

    private static function dir_size(string $dir): int
    {
        $size = 0;
        if (! is_dir($dir)) {
            return 0;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $size += (int) $file->getSize();
            }
        }

        return $size;
    }

    private static function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
