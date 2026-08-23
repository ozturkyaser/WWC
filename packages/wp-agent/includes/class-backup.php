<?php

declare(strict_types=1);

final class WWC_Agent_Backup
{
    /** UpdraftPlus-style: never grow one ZIP past this – cheap hosts choke on zip -g. */
    private const ZIP_SPLIT_BYTES = 80 * 1024 * 1024;

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

    public static function list(bool $includeSizes = true): array
    {
        $items = [];
        foreach (glob(self::root().'/*/manifest.json') ?: [] as $manifestFile) {
            $json = json_decode((string) file_get_contents($manifestFile), true);
            if (! is_array($json)) {
                continue;
            }
            $dir = dirname($manifestFile);
            $json['path'] = $dir;
            // Off-site backups keep only manifest.json locally – size comes from the manifest.
            // Heartbeats skip dir_size(): walking a partial backup tree can exhaust PHP memory.
            if ($includeSizes && (empty($json['offsite']) || empty($json['size_bytes']))) {
                $json['size_bytes'] = self::dir_size($dir);
            }
            $json['offsite'] = ! empty($json['offsite']);
            // File map is huge and only needed on disk (incremental diffs) – not in listings
            unset($json['files']);
            $items[] = $json;
        }
        $seen = [];
        foreach ($items as $item) {
            $seen[(string) ($item['id'] ?? '')] = true;
        }
        foreach (glob(self::root().'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $id = basename($dir);
            if ($id === '' || isset($seen[$id]) || is_file($dir.'/manifest.json')) {
                continue;
            }
            if (! is_file($dir.'/work.json') && self::list_file_archives($dir) === [] && ! is_file($dir.'/database.sql')) {
                continue;
            }
            $work = self::read_json_file($dir.'/work.json');
            $size = 0;
            foreach (array_merge(self::list_file_archives($dir), ['database.sql']) as $name) {
                if (is_file($dir.'/'.$name)) {
                    $size += (int) filesize($dir.'/'.$name);
                }
            }
            $items[] = [
                'id' => $id,
                'type' => str_starts_with($id, 'incr-') ? 'incremental' : 'full',
                'label' => 'unvollständig',
                'created_at' => gmdate('c', (int) (@filemtime($dir) ?: time())),
                'size_bytes' => $size,
                'offsite' => false,
                'incomplete' => true,
                'phase' => is_array($work) ? ($work['phase'] ?? null) : null,
            ];
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
        self::prepare_runtime();
        $forceFresh = ! empty($options['fresh']);
        unset($options['fresh']);
        if ($forceFresh) {
            self::discard_unfinished_work();
        }
        $jobId = WWC_Agent_Job_Progress::currentJobId() ?: ('local-'.wp_generate_password(8, false, false));
        $work = $forceFresh ? null : self::load_work($jobId);
        $adopted = false;
        if ($work === null && ! $forceFresh) {
            $leftover = self::find_unfinished_work();
            if ($leftover !== null) {
                $work = $leftover['work'];
                $work['job_id'] = $jobId;
                $oldJobId = (string) ($leftover['old_job_id'] ?? '');
                if ($oldJobId !== '' && $oldJobId !== $jobId) {
                    self::clear_work($oldJobId);
                }
                self::save_work($jobId, $work);
                $adopted = true;
                WWC_Agent_Job_Progress::report(
                    (int) ($work['percent'] ?? 8),
                    'Unvollständiges Backup fortsetzen ('.$work['phase'].')…',
                    true
                );
            }
        }
        if ($work === null) {
            $work = self::start_full_work($jobId, $label, $options);
        } elseif (! $adopted) {
            WWC_Agent_Job_Progress::report(
                (int) ($work['percent'] ?? 8),
                'Backup fortsetzen ('.$work['phase'].')…',
                true
            );
        }

        $started = microtime(true);

        while (! self::slice_exhausted($started, self::slice_budget((string) ($work['phase'] ?? '')))) {
            $budget = self::slice_budget((string) ($work['phase'] ?? ''));
            if (($work['phase'] ?? '') === 'db') {
                $step = self::export_database_slice($work, $started, $budget);
                self::save_work($jobId, $work);
                if (! ($step['ok'] ?? false)) {
                    return $step;
                }
                continue;
            }
            if (($work['phase'] ?? '') === 'scan') {
                $step = self::scan_files_slice($work, $started, $budget);
                self::save_work($jobId, $work);
                if (! ($step['ok'] ?? false)) {
                    return $step;
                }
                continue;
            }
            if (($work['phase'] ?? '') === 'zip') {
                $step = self::zip_files_slice($work, $started, $budget);
                self::save_work($jobId, $work);
                if (! ($step['ok'] ?? false)) {
                    return $step;
                }
                continue;
            }
            if (($work['phase'] ?? '') === 'finish' || ($work['phase'] ?? '') === 'offsite') {
                $result = self::finish_full($work);
                self::clear_work($jobId);

                return $result;
            }

            return ['ok' => false, 'error' => 'Unbekannte Backup-Phase'];
        }

        self::save_work($jobId, $work);
        WWC_Agent_Job_Progress::report(
            (int) ($work['percent'] ?? 10),
            'Hosting-Limit: Backup wird gleich fortgesetzt…',
            true
        );

        return ['ok' => true, 'continue' => true, 'phase' => (string) ($work['phase'] ?? 'db')];
    }

    public static function has_work(string $jobId): bool
    {
        return self::load_work($jobId) !== null;
    }

    /**
     * @return array{work: array<string, mixed>, old_job_id: string}|null
     */
    public static function find_unfinished_work(): ?array
    {
        $candidates = [];
        $map = get_option('wwc_agent_backup_jobs', []);
        if (is_array($map)) {
            foreach ($map as $oldJobId => $meta) {
                $dir = is_array($meta) ? rtrim((string) ($meta['dir'] ?? ''), '/') : '';
                $work = $dir !== '' ? self::read_json_file($dir.'/work.json') : null;
                if (! self::is_resumable_work($work)) {
                    continue;
                }
                $candidates[] = [
                    'work' => $work,
                    'old_job_id' => (string) $oldJobId,
                    'updated' => (int) ($meta['updated_at'] ?? 0),
                ];
            }
        }
        foreach (glob(self::root().'/full-*/work.json') ?: [] as $file) {
            $work = self::read_json_file($file);
            if (! self::is_resumable_work($work)) {
                continue;
            }
            $dir = dirname($file);
            foreach ($candidates as $candidate) {
                if (rtrim((string) ($candidate['work']['dir'] ?? ''), '/') === $dir) {
                    continue 2;
                }
            }
            $candidates[] = [
                'work' => $work,
                'old_job_id' => (string) ($work['job_id'] ?? ''),
                'updated' => (int) (@filemtime($file) ?: 0),
            ];
        }
        if ($candidates === []) {
            return null;
        }
        usort($candidates, static fn ($a, $b) => ($b['updated'] <=> $a['updated']));

        return ['work' => $candidates[0]['work'], 'old_job_id' => (string) $candidates[0]['old_job_id']];
    }

    /**
     * @param  array<string, mixed>|null  $work
     */
    private static function is_resumable_work(?array $work): bool
    {
        if ($work === null) {
            return false;
        }
        $dir = rtrim((string) ($work['dir'] ?? ''), '/');
        $id = (string) ($work['id'] ?? '');
        $phase = (string) ($work['phase'] ?? '');

        return $dir !== ''
            && is_dir($dir)
            && str_starts_with($id, 'full-')
            && in_array($phase, ['db', 'scan', 'zip', 'finish', 'offsite'], true);
    }

    private static function discard_unfinished_work(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $leftover = self::find_unfinished_work();
            if ($leftover === null) {
                return;
            }
            $oldJobId = (string) ($leftover['old_job_id'] ?? '');
            if ($oldJobId !== '') {
                self::clear_work($oldJobId);
            }
            $dir = rtrim((string) ($leftover['work']['dir'] ?? ''), '/');
            if ($dir !== '' && is_dir($dir) && ! is_file($dir.'/manifest.json')) {
                self::rrmdir($dir);
            }
        }
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
        $oldFiles = self::backup_filemap((string) $parent['id']);
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
            'archives' => file_exists($filesZip) ? ['files.zip'] : [],
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

    /**
     * @return array<string, array{hash:string,mtime:int,size:int}>
     */
    private static function backup_filemap(string $backupId): array
    {
        $dir = self::root().'/'.$backupId;
        $map = self::read_json_file($dir.'/filemap.json');
        if (is_array($map) && $map !== []) {
            return $map;
        }
        $manifest = self::read_json_file($dir.'/manifest.json') ?? [];

        return is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
    }

    public static function ensure_export(string $backupId): array
    {
        $backupId = self::sanitize_id($backupId);
        $dir = self::root().'/'.$backupId;
        if (! is_dir($dir) || ! file_exists($dir.'/manifest.json')) {
            return ['ok' => false, 'error' => 'Backup not found'];
        }

        $archives = self::list_file_archives($dir);
        if (count($archives) > 1) {
            return ['ok' => false, 'error' => 'Mehrteiliges Backup – kein einzelnes Export-Zip'];
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
        $names = array_merge(['manifest.json', 'database.sql', 'changed.zip'], self::list_file_archives($dir));
        foreach (array_unique($names) as $name) {
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
        if ($backupId === '') {
            return ['ok' => false, 'error' => 'Ungültige Backup-ID'];
        }
        $dir = self::root().'/'.$backupId;
        $hadLocal = is_dir($dir);
        if ($hadLocal) {
            self::rrmdir($dir);
        }
        self::forget_work_for_backup($backupId);
        WWC_Agent_Backup_Uploader::delete_remote($backupId);
        WWC_Agent_Event_Queue::push('backup_deleted', 'Backup '.$backupId.' deleted', 'info', ['id' => $backupId]);

        return ['ok' => true, 'deleted' => $backupId, 'local' => $hadLocal];
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

    private static function forget_work_for_backup(string $backupId): void
    {
        $map = get_option('wwc_agent_backup_jobs', []);
        if (! is_array($map)) {
            return;
        }
        $changed = false;
        foreach ($map as $jobId => $meta) {
            $id = is_array($meta) ? (string) ($meta['id'] ?? '') : '';
            $dir = is_array($meta) ? basename((string) ($meta['dir'] ?? '')) : '';
            if ($id === $backupId || $dir === $backupId) {
                unset($map[$jobId]);
                $changed = true;
            }
        }
        if ($changed) {
            update_option('wwc_agent_backup_jobs', $map, false);
        }
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
            $archives = [];
            if (is_array($step['archives'] ?? null)) {
                $archives = array_values(array_filter(array_map('strval', $step['archives'])));
            } elseif (! empty($step['archive'])) {
                $archives = [(string) $step['archive']];
            }
            if ($archives === []) {
                $archives = self::list_file_archives($dir);
            }
            foreach ($archives as $archive) {
                $path = $dir.'/'.$archive;
                if (! is_file($path)) {
                    continue;
                }
                $unzip = self::unzip_to($path, ABSPATH);
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
            'wp-content/backups/',
            'wp-content/akeeba_backup/',
            'wp-content/plugins/akeebabackupwp/backups/',
            'wp-content/plugins/akeebabackupwp/app/backups/',
            'wp-content/wflogs/',
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

    public static function skips_rel(string $rel): bool
    {
        return self::should_skip($rel);
    }

    public static function skips_table_data(string $table): bool
    {
        return self::skip_table_data($table);
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
        $work = [
            'dir' => dirname($targetFile),
            'phase' => 'db',
            'percent' => 12,
            'tables' => [],
            'table_i' => 0,
            'table_started' => false,
            'pk' => null,
            'last_pk' => null,
            'offset' => 0,
            'table_total_rows' => 0,
            'table_done_rows' => 0,
            'db_header' => false,
            'db_file' => $targetFile,
        ];
        $started = microtime(true);
        do {
            $step = self::export_database_slice($work, $started, 3600);
            if (! ($step['ok'] ?? false)) {
                return $step;
            }
        } while (($work['phase'] ?? '') === 'db');

        return [
            'ok' => true,
            'tables' => count($work['tables'] ?? []),
            'bytes' => is_file($targetFile) ? (int) filesize($targetFile) : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $work
     * @return array{ok:bool,error?:string}
     */
    private static function export_database_slice(array &$work, float $started, int $budget): array
    {
        global $wpdb;
        $dbFile = (string) ($work['db_file'] ?? ($work['dir'].'/database.sql'));
        $fh = fopen($dbFile, ! empty($work['db_header']) ? 'ab' : 'wb');
        if (! $fh) {
            return ['ok' => false, 'error' => 'Cannot write database dump'];
        }

        if (empty($work['db_header'])) {
            fwrite($fh, "-- WWC Agent DB dump\n-- ".gmdate('c')."\nSET NAMES utf8mb4;\nSET foreign_key_checks=0;\n\n");
            $tables = $wpdb->get_col('SHOW TABLES');
            if (! is_array($tables) || $tables === []) {
                fclose($fh);

                return ['ok' => false, 'error' => 'Could not list tables'];
            }
            $work['tables'] = array_values(array_filter($tables, static fn ($t) => is_string($t) && preg_match('/^[A-Za-z0-9_]+$/', $t)));
            $work['db_header'] = true;
            $work['table_i'] = 0;
            $work['table_started'] = false;
            WWC_Agent_Job_Progress::report(12, 'Datenbank exportieren ('.count($work['tables']).' Tabellen)…', true);
        }

        $tables = $work['tables'] ?? [];
        $total = max(1, count($tables));

        while ((int) $work['table_i'] < count($tables)) {
            if (self::slice_exhausted($started, $budget)) {
                fclose($fh);

                return ['ok' => true];
            }

            $i = (int) $work['table_i'];
            $table = (string) $tables[$i];

            if (! empty($work['table_started']) && self::skip_table_data($table)) {
                fwrite($fh, "-- WWC: Rest übersprungen (Protokoll): {$table}\n\n");
                $work['table_i'] = $i + 1;
                $work['table_started'] = false;
                $work['pk'] = null;
                $work['last_pk'] = null;
                $work['offset'] = 0;
                $work['percent'] = 12 + (int) round((($i + 1) / $total) * 16);
                WWC_Agent_Job_Progress::report(
                    (int) $work['percent'],
                    'DB '.$table.' übersprungen (Protokoll, Rest)',
                    true
                );
                continue;
            }

            if (empty($work['table_started'])) {
                $create = $wpdb->get_row('SHOW CREATE TABLE `'.$table.'`', ARRAY_N);
                if (! $create) {
                    $work['table_i'] = $i + 1;
                    continue;
                }
                fwrite($fh, 'DROP TABLE IF EXISTS `'.$table."`;\n".$create[1].";\n\n");
                if (self::skip_table_data($table)) {
                    fwrite($fh, "-- WWC: Daten übersprungen (Cache/Protokoll): {$table}\n\n");
                    $work['table_i'] = $i + 1;
                    $work['table_started'] = false;
                    $work['percent'] = 12 + (int) round((($i + 1) / $total) * 16);
                    WWC_Agent_Job_Progress::report(
                        (int) $work['percent'],
                        'DB-Export '.($i + 1).'/'.$total.': '.$table.' übersprungen (Cache)',
                        true
                    );
                    continue;
                }
                $count = (int) $wpdb->get_var('SELECT COUNT(*) FROM `'.$table.'`');
                $work['table_started'] = true;
                $work['table_total_rows'] = $count;
                $work['table_done_rows'] = 0;
                $work['offset'] = 0;
                $work['pk'] = self::table_primary_key($table);
                $work['last_pk'] = null;
                $work['percent'] = 12 + (int) round(($i / $total) * 16);
                WWC_Agent_Job_Progress::report(
                    (int) $work['percent'],
                    'DB-Export '.($i + 1).'/'.$total.': '.$table.' ('.$count.' Zeilen)',
                    true
                );
            }

            $chunk = self::table_chunk_size($table, (int) $work['table_total_rows']);
            $rows = self::fetch_table_chunk($table, $work['pk'] ?? null, $work['last_pk'] ?? null, (int) $work['offset'], $chunk);
            if ($rows === []) {
                fwrite($fh, "\n");
                $work['table_i'] = $i + 1;
                $work['table_started'] = false;
                $work['pk'] = null;
                $work['last_pk'] = null;
                $work['offset'] = 0;
                $work['percent'] = 12 + (int) round((($i + 1) / $total) * 16);
                continue;
            }

            foreach ($rows as $row) {
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = $v === null ? 'NULL' : "'".$wpdb->_real_escape((string) $v)."'";
                }
                fwrite($fh, 'INSERT INTO `'.$table.'` VALUES ('.implode(',', $vals).");\n");
            }

            $prevPk = $work['last_pk'] ?? null;
            $work['offset'] = (int) $work['offset'] + count($rows);
            $work['table_done_rows'] = (int) $work['table_done_rows'] + count($rows);
            if (is_string($work['pk'] ?? null) && $work['pk'] !== '') {
                $last = $rows[array_key_last($rows)];
                $work['last_pk'] = $last[$work['pk']] ?? $work['last_pk'];
                if ($work['last_pk'] === $prevPk) {
                    $work['pk'] = null;
                }
            }

            $frac = $work['table_total_rows'] > 0
                ? min(1, $work['table_done_rows'] / $work['table_total_rows'])
                : 1;
            $work['percent'] = 12 + (int) round((($i + $frac) / $total) * 16);
            if ($work['table_total_rows'] > 500 || $work['table_done_rows'] % 400 === 0) {
                WWC_Agent_Job_Progress::report(
                    (int) $work['percent'],
                    'DB '.$table.': '.$work['table_done_rows'].'/'.$work['table_total_rows']
                );
            }

            $wpdb->flush();
            unset($rows);
        }

        fwrite($fh, "SET foreign_key_checks=1;\n");
        fclose($fh);
        $work['phase'] = 'scan';
        $work['percent'] = 28;
        WWC_Agent_Job_Progress::log(
            'Datenbank exportiert ('.count($tables).' Tabellen, '.self::format_bytes((int) @filesize($dbFile)).')',
            28,
            true
        );

        return ['ok' => true];
    }

    private static function skip_table_data(string $table): bool
    {
        $name = strtolower($table);
        // Wordfence settings / 2FA must stay restorable.
        foreach (['wfconfig', 'wfls_2fa', 'wfls_settings'] as $keep) {
            if (str_contains($name, $keep)) {
                return false;
            }
        }
        foreach ([
            'actionscheduler_logs',
            'woocommerce_sessions',
            'woocommerce_log',
            'imagify_files',
            'imagify_folders',
            'litespeed_img_optming',
            // Wordfence: scan results, caches, traffic/login logs – rebuilt after restore
            'wffilemods',
            'wffilechanges',
            'wfknownfilelist',
            'wfhits',
            'wfhoover',
            'wfleechers',
            'wfstatus',
            'wflogins',
            'wflocs',
            'wfreversecache',
            'wfsnipcache',
            'wfpendingissues',
            'wfnotifications',
            'wflivetraffichuman',
            'wfcrawlers',
            'wftrafficrates',
            'wfwaffailures',
            'redirection_404',
            'redirection_logs',
            'itsec_logs',
            'itsec_lockouts',
            'e_events',
        ] as $needle) {
            if (str_contains($name, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function table_chunk_size(string $table, int $rows): int
    {
        $name = strtolower($table);
        if (str_contains($name, 'postmeta') || str_contains($name, 'options') || str_contains($name, 'yoast')) {
            return $rows > 20000 ? 15 : 40;
        }
        if ($rows > 100000) {
            return 15;
        }
        if ($rows > 20000) {
            return 40;
        }

        return 120;
    }

    private static function table_primary_key(string $table): ?string
    {
        global $wpdb;
        $keys = $wpdb->get_results('SHOW KEYS FROM `'.$table."` WHERE Key_name = 'PRIMARY'", ARRAY_A);
        if (! is_array($keys) || count($keys) !== 1) {
            return null;
        }
        $col = $keys[0]['Column_name'] ?? null;

        return is_string($col) && preg_match('/^[A-Za-z0-9_]+$/', $col) ? $col : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function fetch_table_chunk(string $table, ?string $pk, mixed $lastPk, int $offset, int $limit): array
    {
        global $wpdb;
        $limit = max(1, min(500, $limit));
        if (is_string($pk) && $pk !== '') {
            if ($lastPk === null) {
                $sql = 'SELECT * FROM `'.$table.'` ORDER BY `'.$pk.'` ASC LIMIT '.$limit;
            } else {
                $sql = $wpdb->prepare(
                    'SELECT * FROM `'.$table.'` WHERE `'.$pk.'` > %s ORDER BY `'.$pk.'` ASC LIMIT %d',
                    $lastPk,
                    $limit
                );
            }
        } else {
            $sql = 'SELECT * FROM `'.$table.'` LIMIT '.$limit.' OFFSET '.max(0, $offset);
        }
        $rows = $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param  array<string, mixed>  $work
     * @return array{ok:bool,error?:string}
     */
    private static function scan_files_slice(array &$work, float $started, int $budget): array
    {
        $settings = self::settings(is_array($work['options'] ?? null) ? $work['options'] : []);
        $skipped = is_array($work['skipped'] ?? null) ? $work['skipped'] : ['count' => 0, 'bytes' => 0, 'samples' => []];
        $map = self::read_json_file($work['dir'].'/filemap.json') ?? [];
        $scanned = (int) ($work['scan_count'] ?? count($map));
        $last = (string) ($work['scan_last'] ?? '');
        $skipUntil = $last !== '';

        WWC_Agent_Job_Progress::report((int) ($work['percent'] ?? 32), 'Dateien scannen…', $scanned === 0);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(ABSPATH, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (self::slice_exhausted($started, $budget)) {
                $work['scan_last'] = $last;
                $work['scan_count'] = $scanned;
                $work['skipped'] = $skipped;
                self::write_json_file($work['dir'].'/filemap.json', $map);
                $work['percent'] = min(47, 32 + (int) floor($scanned / 800));

                return ['ok' => true];
            }
            if (! $file->isFile()) {
                continue;
            }
            $absolute = $file->getRealPath();
            if ($absolute === false) {
                continue;
            }
            $rel = ltrim(str_replace('\\', '/', substr($absolute, strlen(rtrim(ABSPATH, '/\\')))), '/');
            if ($skipUntil) {
                if ($rel === $last) {
                    $skipUntil = false;
                }
                continue;
            }
            $last = $rel;
            if (self::should_skip($rel)) {
                continue;
            }
            $size = (int) $file->getSize();
            $skipReason = null;
            $relLower = strtolower($rel);
            foreach ((array) $settings['excludes'] as $entry) {
                if ($relLower === $entry || str_starts_with($relLower, $entry.'/')) {
                    $skipReason = 'exclude';
                    break;
                }
            }
            if ($skipReason === null && $settings['max_file_bytes'] > 0 && $size > $settings['max_file_bytes']) {
                $skipReason = 'size';
            }
            if ($skipReason !== null) {
                $skipped['count']++;
                $skipped['bytes'] += $size;
                if (count($skipped['samples']) < 10) {
                    $skipped['samples'][] = $rel.' ('.self::format_bytes($size).')';
                }
                continue;
            }
            $hash = $size > 20 * 1024 * 1024
                ? 'size:'.$size.':mtime:'.$file->getMTime()
                : (md5_file($absolute) ?: ('mtime:'.$file->getMTime()));
            $map[$rel] = ['hash' => $hash, 'mtime' => (int) $file->getMTime(), 'size' => $size];
            $scanned++;
            if ($scanned % 300 === 0) {
                $work['percent'] = min(47, 32 + (int) floor($scanned / 800));
                WWC_Agent_Job_Progress::report((int) $work['percent'], 'Dateien scannen… '.$scanned);
            }
        }

        if ($skipUntil) {
            $work['scan_last'] = '';
            $work['scan_count'] = $scanned;
            $work['skipped'] = $skipped;
            self::write_json_file($work['dir'].'/filemap.json', $map);

            return ['ok' => true];
        }
        $work['scan_last'] = $last;
        $work['scan_count'] = $scanned;
        $work['skipped'] = $skipped;
        $work['file_count'] = count($map);
        self::write_json_file($work['dir'].'/filemap.json', $map);
        $work['phase'] = 'zip';
        $work['zip_index'] = 0;
        $work['zip_part'] = 1;
        $work['zip_part_from'] = 0;
        $work['percent'] = 48;
        WWC_Agent_Job_Progress::log($work['file_count'].' Dateien erfasst', 48, true);

        return ['ok' => true];
    }

    /**
     * UpdraftPlus-style split: files.zip, files-2.zip, … each ~80 MB.
     * Growing one multi-GB ZIP with `zip -g` is what fails on shared hosts.
     *
     * @return list<string>
     */
    public static function list_file_archives(string $dir): array
    {
        $dir = rtrim($dir, '/');
        $names = [];
        foreach (glob($dir.'/files*.zip') ?: [] as $path) {
            if (! is_file($path)) {
                continue;
            }
            $base = basename($path);
            if ($base === 'files.zip' || preg_match('/^files-\d+\.zip$/', $base) === 1) {
                $names[] = $base;
            }
        }
        usort($names, static function (string $a, string $b): int {
            $na = $a === 'files.zip' ? 1 : (int) preg_replace('/\D+/', '', $a);
            $nb = $b === 'files.zip' ? 1 : (int) preg_replace('/\D+/', '', $b);

            return $na <=> $nb;
        });

        return $names;
    }

    private static function zip_part_file(string $dir, int $part): string
    {
        $dir = rtrim($dir, '/');

        return $part <= 1 ? $dir.'/files.zip' : $dir.'/files-'.$part.'.zip';
    }

    /**
     * @param  array<string, mixed>  $work
     * @return array{0:string,1:bool}
     */
    private static function prepare_zip_part(array &$work, int $from): array
    {
        $dir = rtrim((string) $work['dir'], '/');
        $part = max(1, (int) ($work['zip_part'] ?? 1));
        $file = self::zip_part_file($dir, $part);
        $size = is_file($file) ? (int) filesize($file) : 0;
        $rotated = false;
        while ($size >= self::ZIP_SPLIT_BYTES) {
            $part++;
            $file = self::zip_part_file($dir, $part);
            $size = is_file($file) ? (int) filesize($file) : 0;
            $rotated = true;
        }
        if ($rotated) {
            $work['zip_part'] = $part;
            if ($size === 0) {
                $work['zip_part_from'] = $from;
            }
            WWC_Agent_Job_Progress::log(
                'ZIP-Teil '.$part.' (max 80 MB, wie UpdraftPlus)',
                (int) ($work['percent'] ?? 50),
                true
            );
        }
        if (! isset($work['zip_part'])) {
            $work['zip_part'] = $part;
        }
        if (! isset($work['zip_part_from'])) {
            $work['zip_part_from'] = $part <= 1 ? 0 : $from;
        }

        return [$file, $size === 0];
    }

    private static function zip_files_slice(array &$work, float $started, int $budget): array
    {
        [$map, $paths] = self::cached_filemap($work['dir']);
        $from = (int) ($work['zip_index'] ?? 0);
        $total = max(1, count($paths));
        if ($from === 0 && $paths === []) {
            $work['phase'] = 'finish';
            $work['percent'] = 88;

            return ['ok' => true];
        }
        if ($from >= count($paths)) {
            $work['phase'] = 'finish';
            $work['percent'] = 88;

            return ['ok' => true];
        }

        $lastReport = $from;
        while ($from < count($paths) && ! self::slice_exhausted($started, max(8, $budget - 2))) {
            [$zipFile, $create] = self::prepare_zip_part($work, $from);
            $partFrom = (int) ($work['zip_part_from'] ?? 0);
            $part = (int) ($work['zip_part'] ?? 1);

            $batch = [];
            $batchBytes = 0;
            $limit = count($paths);
            for ($i = $from; $i < $limit; $i++) {
                $rel = $paths[$i];
                $batch[] = $rel;
                $batchBytes += (int) ($map[$rel]['size'] ?? 0);
                if (count($batch) >= 400 || $batchBytes >= 24 * 1024 * 1024) {
                    break;
                }
            }

            $append = self::zip_append($zipFile, $batch, ABSPATH, $create);
            if (! ($append['ok'] ?? false)) {
                $kept = self::recover_zip_progress($zipFile);
                if ($kept >= 0) {
                    $from = $partFrom + $kept;
                    $work['zip_index'] = $from;
                    $work['percent'] = 50 + (int) round(($from / $total) * 36);
                    WWC_Agent_Job_Progress::report(
                        (int) $work['percent'],
                        'ZIP-Teil '.$part.' repariert, weiter bei '.$from.'/'.$total,
                        true
                    );

                    return ['ok' => true];
                }
                if ($from > $partFrom) {
                    @unlink($zipFile);
                    $work['zip_index'] = $partFrom;
                    $work['percent'] = 50 + (int) round(($partFrom / $total) * 36);
                    self::clear_filemap_cache();
                    WWC_Agent_Job_Progress::report(
                        (int) $work['percent'],
                        'ZIP-Teil '.$part.' neu aufbauen…',
                        true
                    );

                    return ['ok' => true];
                }

                return $append;
            }

            $from += count($batch);
            $work['zip_index'] = $from;
            $work['percent'] = 50 + (int) round(($from / $total) * 36);
            if ($from - $lastReport >= 400 || $from >= count($paths)) {
                WWC_Agent_Job_Progress::report(
                    (int) $work['percent'],
                    'ZIP '.$from.'/'.$total.' (Teil '.$part.')',
                    true
                );
                $lastReport = $from;
            }
        }

        if ($from > $lastReport) {
            WWC_Agent_Job_Progress::report(
                (int) $work['percent'],
                'ZIP '.$from.'/'.$total.' (Teil '.((int) ($work['zip_part'] ?? 1)).')',
                true
            );
        }

        if ($from >= count($paths)) {
            $work['phase'] = 'finish';
            $work['percent'] = 88;
        }

        return ['ok' => true];
    }

    /**
     * @param  list<string>  $batch
     * @return array{ok:bool,error?:string}
     */
    private static function zip_append(string $zipFile, array $batch, string $base, bool $create): array
    {
        $lock = @fopen($zipFile.'.lock', 'c');
        if (is_resource($lock)) {
            flock($lock, LOCK_EX);
        }
        try {
            $cli = self::zip_append_cli($zipFile, $batch, $base, $create);
            if (is_array($cli) && ($cli['ok'] ?? false)) {
                return $cli;
            }

            $php = self::zip_append_php($zipFile, $batch, $base, $create);
            if ($php['ok'] ?? false) {
                return $php;
            }
            $cliError = is_array($cli) ? ($cli['error'] ?? null) : null;

            return ['ok' => false, 'error' => (string) ($php['error'] ?? $cliError ?? 'ZIP schreiben fehlgeschlagen')];
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private static function zip_entry_count(string $zipFile): int
    {
        if (! is_file($zipFile) || ! class_exists('ZipArchive')) {
            return -1;
        }
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) {
            return -1;
        }
        $count = (int) $zip->numFiles;
        $zip->close();

        return $count;
    }

    private static function zip_try_fix(string $zipFile): bool
    {
        $bin = self::find_binary('zip');
        if ($bin === null || ! self::shell_available() || ! is_file($zipFile)) {
            return false;
        }
        $fixed = $zipFile.'.fix';
        @unlink($fixed);
        $out = [];
        $code = 0;
        @exec(escapeshellarg($bin).' -F '.escapeshellarg($zipFile).' --out '.escapeshellarg($fixed).' 2>&1', $out, $code);
        if (self::zip_entry_count($fixed) >= 0) {
            @unlink($zipFile);
            @rename($fixed, $zipFile);

            return true;
        }
        @unlink($fixed);
        $code = 0;
        @exec(escapeshellarg($bin).' -FF '.escapeshellarg($zipFile).' --out '.escapeshellarg($fixed).' 2>&1', $out, $code);
        if (self::zip_entry_count($fixed) >= 0) {
            @unlink($zipFile);
            @rename($fixed, $zipFile);

            return true;
        }
        @unlink($fixed);

        return false;
    }

    private static function recover_zip_progress(string $zipFile): int
    {
        $count = self::zip_entry_count($zipFile);
        if ($count >= 0) {
            return $count;
        }
        if (self::zip_try_fix($zipFile)) {
            return self::zip_entry_count($zipFile);
        }

        return -1;
    }

    /**
     * @param  list<string>  $batch
     * @return array{ok:bool,error?:string}|null
     */
    private static function zip_append_cli(string $zipFile, array $batch, string $base, bool $create): ?array
    {
        $bin = self::find_binary('zip');
        if ($bin === null || ! self::shell_available()) {
            return null;
        }
        if ($create && is_file($zipFile)) {
            @unlink($zipFile);
        }
        $listFile = tempnam(sys_get_temp_dir(), 'wwczip');
        if ($listFile === false) {
            return ['ok' => false, 'error' => 'Cannot create temp file list'];
        }
        file_put_contents($listFile, implode("\n", $batch)."\n");
        $cwd = rtrim($base, '/\\');
        $grow = (! $create && is_file($zipFile)) ? ' -g' : '';
        $cmd = 'cd '.escapeshellarg($cwd).
            ' && '.escapeshellarg($bin).' -0'.$grow.' -q '.escapeshellarg($zipFile).
            ' -@ < '.escapeshellarg($listFile).' 2>&1';
        $out = [];
        $code = 0;
        @exec($cmd, $out, $code);
        @unlink($listFile);
        if ($code === 0) {
            return ['ok' => true];
        }

        return [
            'ok' => false,
            'error' => 'zip CLI failed (code '.$code.')'.($out !== [] ? ': '.mb_substr(implode(' ', $out), 0, 180) : ''),
        ];
    }

    /**
     * @param  list<string>  $batch
     * @return array{ok:bool,error?:string}
     */
    private static function zip_append_php(string $zipFile, array $batch, string $base, bool $create): array
    {
        if (! class_exists('ZipArchive')) {
            return ['ok' => false, 'error' => 'ZipArchive PHP extension missing'];
        }
        $base = rtrim($base, '/\\').'/';
        $zip = new ZipArchive();
        $flags = $create ? (ZipArchive::CREATE | ZipArchive::OVERWRITE) : ZipArchive::CREATE;
        if ($zip->open($zipFile, $flags) !== true) {
            return ['ok' => false, 'error' => 'Cannot open zip'];
        }
        foreach ($batch as $rel) {
            $abs = $base.$rel;
            if (! is_file($abs)) {
                continue;
            }
            $zip->addFile($abs, $rel);
            if (method_exists($zip, 'setCompressionName')) {
                $zip->setCompressionName($rel, ZipArchive::CM_STORE);
            }
        }
        $zip->close();

        return ['ok' => true];
    }

    /**
     * @param  array<string, mixed>  $work
     * @return array{ok:bool,backup?:array<string,mixed>,error?:string}
     */
    private static function finish_full(array $work): array
    {
        $id = (string) $work['id'];
        $dir = (string) $work['dir'];
        $label = (string) ($work['label'] ?? 'manual');
        $settings = self::settings(is_array($work['options'] ?? null) ? $work['options'] : []);
        $fileMap = self::read_json_file($dir.'/filemap.json') ?? [];
        $skipped = is_array($work['skipped'] ?? null) ? $work['skipped'] : ['count' => 0, 'bytes' => 0];
        $fileCount = count($fileMap);
        if ($fileCount === 0) {
            $fileCount = (int) ($work['file_count'] ?? 0);
        }

        $archives = self::list_file_archives($dir);
        if ($archives === []) {
            $archives = ['files.zip'];
        }
        if (! is_file($dir.'/manifest.json')) {
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
                'filemap' => 'filemap.json',
                'database' => 'database.sql',
                'archive' => $archives[0],
                'archives' => $archives,
                'skipped' => ['count' => (int) ($skipped['count'] ?? 0), 'bytes' => (int) ($skipped['bytes'] ?? 0)],
                'settings' => ['max_file_bytes' => $settings['max_file_bytes'], 'excludes' => $settings['excludes']],
            ];
            file_put_contents($dir.'/manifest.json', wp_json_encode($manifest));
        } else {
            WWC_Agent_Job_Progress::report(90, 'Manifest vorhanden, Abschluss…', true);
            $manifest = self::read_json_file($dir.'/manifest.json') ?? [
                'id' => $id,
                'type' => 'full',
                'label' => $label,
                'created_at' => gmdate('c'),
            ];
        }
        // Keep filemap.json for incrementals – do not embed 47k hashes in manifest.json.
        unset($fileMap);
        $sizeBytes = 0;
        foreach (array_merge($archives, ['database.sql', 'filemap.json', 'manifest.json']) as $name) {
            if (is_file($dir.'/'.$name)) {
                $sizeBytes += (int) filesize($dir.'/'.$name);
            }
        }

        WWC_Agent_Event_Queue::push('backup_created', 'Full backup '.$id, 'info', ['id' => $id, 'type' => 'full']);

        $offsite = WWC_Agent_Backup_Uploader::upload($id, 91, 96);
        if (! ($offsite['ok'] ?? false)) {
            WWC_Agent_Job_Progress::log('Off-site-Upload übersprungen (Backup bleibt lokal): '.($offsite['error'] ?? '?'), 96, true);
            WWC_Agent_Event_Queue::push('backup_offsite_failed', 'Off-site upload skipped for '.$id, 'warning', [
                'id' => $id,
                'error' => $offsite['error'] ?? null,
            ]);
        }
        @unlink($dir.'/work.json');
        WWC_Agent_Job_Progress::report(96, 'Full-Backup fertiggestellt ('.count($archives).' ZIP-Teile)', true);

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

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function start_full_work(string $jobId, string $label, array $options): array
    {
        WWC_Agent_Job_Progress::report(4, 'Full-Backup starten…', true);
        WWC_Agent_Job_Progress::log('Backup-ID wird erzeugt, Zielverzeichnis anlegen');
        $id = 'full-'.gmdate('Ymd-His').'-'.wp_generate_password(6, false, false);
        $dir = self::root().'/'.$id;
        wp_mkdir_p($dir);
        WWC_Agent_Job_Progress::log('Ziel: '.$dir, 6);
        $work = [
            'job_id' => $jobId,
            'id' => $id,
            'dir' => $dir,
            'label' => $label,
            'options' => $options,
            'phase' => 'db',
            'percent' => 8,
            'db_file' => $dir.'/database.sql',
            'db_header' => false,
            'tables' => [],
            'table_i' => 0,
            'table_started' => false,
            'skipped' => ['count' => 0, 'bytes' => 0, 'samples' => []],
            'zip_index' => 0,
            'zip_part' => 1,
            'zip_part_from' => 0,
        ];
        self::save_work($jobId, $work);

        return $work;
    }

    private static function prepare_runtime(): void
    {
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        if (function_exists('wp_raise_memory_limit')) {
            @wp_raise_memory_limit('admin');
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private static function cached_filemap(string $dir): array
    {
        static $cachedDir = '';
        static $map = [];
        static $paths = [];
        $dir = rtrim($dir, '/');
        if ($dir === '') {
            $cachedDir = '';
            $map = [];
            $paths = [];

            return [[], []];
        }
        if ($cachedDir !== $dir) {
            $map = self::read_json_file($dir.'/filemap.json') ?? [];
            $paths = array_keys($map);
            $cachedDir = $dir;
        }

        return [$map, $paths];
    }

    private static function clear_filemap_cache(): void
    {
        self::cached_filemap('');
    }

    private static function slice_budget(string $phase = ''): int
    {
        return $phase === 'zip' ? 42 : 18;
    }

    private static function slice_exhausted(float $started, int $budget): bool
    {
        $fromRequest = isset($_SERVER['REQUEST_TIME_FLOAT'])
            ? microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT']
            : (microtime(true) - $started);

        return $fromRequest >= $budget || (microtime(true) - $started) >= $budget;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function load_work(string $jobId): ?array
    {
        $map = get_option('wwc_agent_backup_jobs', []);
        if (! is_array($map) || empty($map[$jobId]['dir'])) {
            return null;
        }
        $work = self::read_json_file(rtrim((string) $map[$jobId]['dir'], '/').'/work.json');

        return is_array($work) ? $work : null;
    }

    /**
     * @param  array<string, mixed>  $work
     */
    private static function save_work(string $jobId, array $work): void
    {
        $dir = (string) ($work['dir'] ?? '');
        if ($dir === '') {
            return;
        }
        self::write_json_file($dir.'/work.json', $work);
        $map = get_option('wwc_agent_backup_jobs', []);
        if (! is_array($map)) {
            $map = [];
        }
        $map[$jobId] = ['id' => $work['id'] ?? null, 'dir' => $dir, 'updated_at' => time()];
        if (count($map) > 20) {
            $map = array_slice($map, -20, null, true);
        }
        update_option('wwc_agent_backup_jobs', $map, false);
    }

    private static function clear_work(string $jobId): void
    {
        $map = get_option('wwc_agent_backup_jobs', []);
        if (is_array($map) && isset($map[$jobId])) {
            unset($map[$jobId]);
            update_option('wwc_agent_backup_jobs', $map, false);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function read_json_file(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $json = json_decode((string) file_get_contents($path), true);

        return is_array($json) ? $json : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function write_json_file(string $path, array $data): void
    {
        file_put_contents($path, wp_json_encode($data));
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
