<?php

declare(strict_types=1);

/**
 * Nimmt ein Slim-Paket von der isolierten Proxmox-Kopie entgegen:
 * Plugins, Themes, MU-Plugins und Datenbank – keine Uploads.
 */
final class WWC_Agent_Clone_Promote
{
    public static function apply(array $payload = []): array
    {
        WWC_Agent_Job_Progress::report(8, 'Promote-Paket vom WWC-Server laden…', true);
        $tmp = trailingslashit(WP_CONTENT_DIR).'wwc-promote-in.zip';
        $download = WWC_Agent_Backup_Uploader::download_signed('/promotes/download', $tmp);
        if (! ($download['ok'] ?? false)) {
            return $download;
        }

        $want = (string) ($payload['sha256'] ?? '');
        if ($want !== '' && ! hash_equals($want, (string) hash_file('sha256', $tmp))) {
            @unlink($tmp);

            return ['ok' => false, 'error' => 'Prüfsumme des Promote-Pakets stimmt nicht'];
        }

        if (! class_exists('ZipArchive')) {
            @unlink($tmp);

            return ['ok' => false, 'error' => 'ZipArchive fehlt'];
        }

        $work = trailingslashit(WP_CONTENT_DIR).'wwc-promote-work';
        self::rrmdir($work);
        wp_mkdir_p($work);
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);

            return ['ok' => false, 'error' => 'Promote-Archiv nicht lesbar'];
        }
        $zip->extractTo($work);
        $zip->close();
        @unlink($tmp);

        $manifest = json_decode((string) @file_get_contents($work.'/manifest.json'), true) ?: [];
        if (($manifest['type'] ?? '') !== 'clone-promote') {
            self::rrmdir($work);

            return ['ok' => false, 'error' => 'Kein gültiges Promote-Paket'];
        }

        WWC_Agent_Job_Progress::report(35, 'Plugins, Themes und MU-Plugins übernehmen (ohne Medien)…', true);
        $copied = [];
        foreach (['plugins' => 'wp-content/plugins', 'themes' => 'wp-content/themes', 'mu-plugins' => 'wp-content/mu-plugins'] as $src => $destRel) {
            $from = $work.'/'.$src;
            if (! is_dir($from)) {
                continue;
            }
            $to = rtrim(ABSPATH, '/\\').'/'.$destRel;
            $copy = self::copy_tree($from, $to);
            if (! ($copy['ok'] ?? false)) {
                self::rrmdir($work);

                return $copy;
            }
            $copied[] = $destRel;
        }

        $dbFile = $work.'/database.sql';
        $hadDb = is_file($dbFile) && (int) filesize($dbFile) > 20;
        if ($hadDb) {
            WWC_Agent_Job_Progress::report(70, 'Datenbank von der isolierten Umgebung einspielen…', true);
            $import = WWC_Agent_Backup::import_database_file($dbFile);
            if (! ($import['ok'] ?? false)) {
                self::rrmdir($work);

                return $import;
            }
        }

        self::rrmdir($work);
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        WWC_Agent_Event_Queue::push('clone_promoted', 'Isolierte Umgebung auf Live übernommen (ohne Medien)', 'warning', [
            'copied' => $copied,
        ]);
        WWC_Agent_Job_Progress::report(96, 'Live-Zug fertig – Uploads unverändert', true);

        return ['ok' => true, 'copied' => $copied, 'database' => $hadDb];
    }

    /**
     * @return array{ok:bool,error?:string}
     */
    private static function copy_tree(string $from, string $to): array
    {
        if (! is_dir($to)) {
            wp_mkdir_p($to);
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $file) {
            $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($from))), '/');
            if ($rel === '' || str_contains($rel, '..')) {
                continue;
            }
            $dest = $to.'/'.$rel;
            if ($file->isDir()) {
                wp_mkdir_p($dest);
                continue;
            }
            if (! @copy($file->getPathname(), $dest)) {
                return ['ok' => false, 'error' => 'Konnte nicht schreiben: '.$rel];
            }
        }

        return ['ok' => true];
    }

    private static function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
