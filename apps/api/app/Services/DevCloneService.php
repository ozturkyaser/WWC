<?php

namespace App\Services;

use App\Models\Site;
use App\Models\SiteBackup;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Baut eine Dev-Kopie einer Kundensite auf dem WWC-Server: Quelle ist das
 * Off-Site-Backup (Dateien + Datenbank), Laufzeitumgebung ein eigener
 * Docker-Stack mit passender PHP-Version. Der Kundenserver wird dabei
 * nicht belastet.
 */
class DevCloneService
{
    /** WordPress-Image-Tags, die wir passend zur PHP-Version der Site waehlen koennen. */
    private const PHP_IMAGES = ['8.1', '8.2', '8.3', '8.4'];

    public function payload(Site $site): ?array
    {
        $clone = $site->dev_clone;
        if (! is_array($clone) || $clone === []) {
            return null;
        }

        if (($clone['status'] ?? '') === 'ready' && $this->urlNeedsCloudflareRepair((string) ($clone['url'] ?? ''))) {
            $attempted = $clone['url_repair_attempted_at'] ?? null;
            $recent = is_string($attempted) && now()->parse($attempted)->greaterThan(now()->subMinutes(10));
            if (! $recent) {
                try {
                    $this->repairPublicUrl($site);
                    $clone = $site->fresh()->dev_clone ?? $clone;
                } catch (\Throwable $e) {
                    Log::info('clone url repair skipped', ['site' => $site->id, 'error' => $e->getMessage()]);
                    $this->setState($site, ['url_repair_attempted_at' => now()->toIso8601String()]);
                    $clone = $site->fresh()->dev_clone ?? $clone;
                }
            }
        }

        return [
            'status' => $clone['status'] ?? 'unknown',
            'url' => $clone['url'] ?? null,
            'lan_url' => $clone['lan_url'] ?? null,
            'backup_id' => $clone['backup_id'] ?? null,
            'php_image' => $clone['php_image'] ?? null,
            'admin_user' => $clone['admin_user'] ?? null,
            'admin_pass' => isset($clone['admin_pass_encrypted'])
                ? Crypt::decryptString($clone['admin_pass_encrypted'])
                : null,
            'error' => $clone['error'] ?? null,
            'message' => $clone['message'] ?? null,
            'built_at' => $clone['built_at'] ?? null,
            'last_dry_run' => $clone['last_dry_run'] ?? null,
        ];
    }

    public function latestUsableBackup(Site $site): ?SiteBackup
    {
        $candidates = SiteBackup::where('site_id', $site->id)
            ->where('status', 'stored')
            ->orderByDesc('backup_created_at')
            ->get();

        foreach ($candidates as $backup) {
            $path = (string) $backup->storage_path;
            if ($path !== '' && (is_file($path) || is_dir($path))) {
                return $backup;
            }
        }

        return null;
    }

    public function canBuild(Site $site): bool
    {
        return $this->latestUsableBackup($site) !== null || (bool) $site->getHmacSecret();
    }

    /**
     * Kompletter Aufbau (laeuft im Queue-Worker). Schreibt Fortschritt in sites.dev_clone.
     */
    public function build(Site $site): void
    {
        try {
            if (! $this->dockerAvailable()) {
                throw new RuntimeException('Docker fehlt auf dem WWC-Server – die isolierte Umgebung kann nicht starten.');
            }

            $backup = $this->latestUsableBackup($site);
            if (! $backup) {
                $this->setState($site, [
                    'status' => 'building',
                    'error' => null,
                    'message' => 'Backup vom Kundenserver auf den WWC-Server holen…',
                ]);
                $backup = app(BackupPullService::class)->pullLatestFull(
                    $site,
                    function (string $msg) use ($site): void {
                        $this->setState($site, ['status' => 'building', 'message' => $msg, 'error' => null]);
                    }
                );
            }

            $this->setState($site, [
                'status' => 'building',
                'error' => null,
                'message' => 'Dev-Kopie auf dem WWC-Server bauen…',
                'backup_id' => $backup->backup_id,
            ]);

            $chain = $this->backupChain($site, $backup);
            $dir = $this->cloneDir($site);
            $reuseFiles = is_file($dir.'/html/wp-settings.php') && is_file($dir.'/database.sql');
            $manifest = [];
            if ($reuseFiles) {
                $this->setState($site, ['status' => 'building', 'message' => 'Vorhandene Dateien nutzen…', 'error' => null]);
                $manifestFile = rtrim((string) $backup->storage_path, '/').'/manifest.json';
                if (is_file($manifestFile)) {
                    $manifest = json_decode((string) file_get_contents($manifestFile), true) ?: [];
                }
            } else {
                $this->resetDirectory($dir);
                foreach ($chain as $i => $step) {
                    $manifest = $this->extractArchive($step, $dir, $i === 0) ?: $manifest;
                }
            }

            // 2. Laufzeit konfigurieren
            $port = $this->allocatePort($site);
            $phpImage = $this->matchPhpImage($site);
            $cloneUrl = $this->clonePublicUrl($port);
            $tablePrefix = $this->detectTablePrefix($dir.'/html/wp-config.php');
            $this->writeCloneWpConfig($dir, $tablePrefix, $cloneUrl);
            $this->neutralizeCloneHtaccess($dir);
            $this->writeGuardMuPlugin($dir);
            $this->installIntelOnClone($site);
            $this->writeComposeFile($dir, $site, $port, $phpImage);

            // 3. Erst NUR die Datenbank starten (Dump erst danach importieren).
            //    Initdb + Healthcheck gleichzeitig funktionieren nicht: MariaDB
            //    lauscht waehrend des Imports ohne TCP, Compose markiert unhealthy.
            //    Bei einem Retry NICHT --volumes loeschen, sonst geht ein
            //    bereits fertiger Import verloren.
            $project = $this->projectName($site);
            $this->setState($site, ['status' => 'building', 'message' => 'Datenbank starten…', 'error' => null]);
            $this->docker($dir, ['compose', '-p', $project, 'up', '-d', 'db'], 120);
            $this->waitForHealthyDb($dir, $project);
            $tables = $this->countImportedTables($dir, $project);
            if ($tables < 20) {
                if ($tables > 0) {
                    $this->setState($site, ['status' => 'building', 'message' => 'Unvollständigen Import verwerfen…', 'error' => null]);
                    $this->docker($dir, ['compose', '-p', $project, 'down', '--volumes', '--remove-orphans'], 120, true);
                    $this->docker($dir, ['compose', '-p', $project, 'up', '-d', 'db'], 120);
                    $this->waitForHealthyDb($dir, $project);
                }
                $this->setState($site, ['status' => 'building', 'message' => 'WordPress-Datenbank importieren…', 'error' => null]);
                $this->importDatabase($site, $dir, $project);
            } else {
                $this->setState($site, ['status' => 'building', 'message' => "Vorhandene Datenbank nutzen ({$tables} Tabellen)…", 'error' => null]);
            }

            // Dateirechte fuer den Webserver-Benutzer. --no-deps, weil die DB
            // schon laeuft; allowFailure, weil html nach dem Entpacken oft
            // schon www-data gehoert und ein Image-Pull den Worker killen kann.
            $this->docker($dir, ['compose', '-p', $project, 'run', '--rm', '--no-deps', '--user', 'root', 'cli', 'chown', '-R', '33:33', '/var/www/html'], 300, true);

            // 4. Nacharbeiten per wp-cli: URLs umschreiben, noindex, Admin-Zugang
            $this->waitForWordPress($dir, $project);

            $oldUrl = rtrim((string) ($manifest['site_url'] ?? $site->url), '/');
            if ($oldUrl !== '' && $oldUrl !== $cloneUrl) {
                $this->wp($dir, $project, ['search-replace', $oldUrl, $cloneUrl, '--all-tables', '--report-changed-only'], true);
            }
            $this->wp($dir, $project, ['option', 'update', 'blog_public', '0'], true);

            // Der WWC-Agent darf im Clone nicht laufen: er wuerde sich mit den
            // Zugangsdaten der Live-Site an der API melden und Status/Inventar verfaelschen.
            $this->wp($dir, $project, ['plugin', 'deactivate', 'wwc-agent']);
            $this->wp($dir, $project, ['plugin', 'deactivate', 'wp-fastest-cache'], true);
            $this->wp($dir, $project, ['plugin', 'deactivate', 'really-simple-ssl'], true);
            $this->wp($dir, $project, ['option', 'delete', 'wwc_agent'], true);

            $adminUser = 'wwc-dev';
            $adminPass = Str::password(20, symbols: false);
            $this->wp($dir, $project, ['user', 'create', $adminUser, 'dev-clone@wwc.local', '--role=administrator', '--user_pass='.$adminPass], true);
            // Falls der User schon existiert (Rebuild): Passwort setzen
            $this->wp($dir, $project, ['user', 'update', $adminUser, '--user_pass='.$adminPass, '--role=administrator'], true);

            // Alte Cron-Events der Live-Site verwerfen, dann erst den Webserver starten
            $this->wp($dir, $project, ['option', 'delete', 'cron'], true);
            $this->docker($dir, ['compose', '-p', $project, 'up', '-d', '--wait'], 300);

            $this->setState($site, [
                'status' => 'ready',
                'port' => $port,
                'url' => $cloneUrl,
                'lan_url' => $this->cloneLanUrl($port),
                'backup_id' => $backup->backup_id,
                'php_image' => $phpImage,
                'admin_user' => $adminUser,
                'admin_pass_encrypted' => Crypt::encryptString($adminPass),
                'error' => null,
                'message' => null,
                'built_at' => now()->toIso8601String(),
            ]);

            // Erfolgreicher Clone-Bau = bestandener Restore-Test fuer die ganze Kette
            foreach ($chain as $step) {
                $step->update(['verified_at' => now()]);
            }
        } catch (\Throwable $e) {
            Log::error('Dev clone build failed', ['site' => $site->id, 'error' => $e->getMessage()]);
            $this->setState($site, ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'message' => null]);
        }
    }

    /**
     * Fuehrt Updates in der Dev-Kopie aus (Dry-Run ohne Last auf dem Kundenserver).
     * Ergebnis wird in sites.dev_clone.last_dry_run gespeichert.
     *
     * @param  list<array{type:string,slug:string}>  $items
     */
    public function dryRun(Site $site, array $items): array
    {
        $clone = $site->dev_clone ?? [];
        if (($clone['status'] ?? '') !== 'ready') {
            throw new RuntimeException('Dev-Kopie ist nicht bereit.');
        }

        $dir = $this->cloneDir($site);
        $project = $this->projectName($site);
        $results = [];

        foreach ($items as $item) {
            $type = (string) ($item['type'] ?? '');
            $slug = (string) ($item['slug'] ?? '');
            if ($slug === '' && $type !== 'core') {
                continue;
            }

            $args = match ($type) {
                'plugin' => ['plugin', 'update', $slug],
                'theme' => ['theme', 'update', $slug],
                'core' => ['core', 'update'],
                default => null,
            };
            if ($args === null) {
                continue;
            }

            try {
                $this->docker($dir, ['compose', '-p', $project, 'run', '--rm', 'cli', 'wp', ...$args], 600);
                $results[] = ['type' => $type, 'slug' => $slug ?: 'wordpress', 'ok' => true, 'error' => null];
            } catch (\Throwable $e) {
                $results[] = ['type' => $type, 'slug' => $slug ?: 'wordpress', 'ok' => false, 'error' => mb_substr($e->getMessage(), 0, 300)];
            }
        }

        // Gesundheitscheck: WordPress laeuft noch und das Frontend liefert 200
        $siteOk = true;
        $healthError = null;
        try {
            $this->wp($dir, $project, ['core', 'is-installed']);
            $status = trim($this->docker($dir, ['compose', '-p', $project, 'exec', '-T', 'wp', 'curl', '-s', '-o', '/dev/null', '-w', '%{http_code}', 'http://localhost/'], 60));
            if (! in_array($status, ['200', '301', '302'], true)) {
                $siteOk = false;
                $healthError = "Frontend antwortet mit HTTP {$status}";
            }
        } catch (\Throwable $e) {
            $siteOk = false;
            $healthError = mb_substr($e->getMessage(), 0, 300);
        }

        $report = [
            'at' => now()->toIso8601String(),
            'items' => $results,
            'site_ok' => $siteOk,
            'health_error' => $healthError,
            'ok' => $siteOk && collect($results)->every(fn ($r) => $r['ok']),
        ];

        try {
            $this->prepareLogsAfterUpdates($dir, $project);
            $logs = $this->collectRuntimeLogs($site);
            $review = app(CloneLogReviewService::class)->reviewAndNotify($site->fresh() ?? $site, $report, $logs);
            $report['ai_review'] = $review;
            $report['ok'] = $report['ok'] && ($review['ok'] ?? false);
        } catch (\Throwable $e) {
            Log::warning('Clone log review failed', ['site' => $site->id, 'error' => $e->getMessage()]);
            $report['ai_review'] = [
                'ok' => $report['ok'],
                'summary' => 'Log-Prüfung nicht möglich: '.mb_substr($e->getMessage(), 0, 200),
                'findings' => [],
                'source' => 'error',
            ];
        }

        $this->setState($site, ['last_dry_run' => $report, 'status' => 'ready']);

        return $report;
    }

    private function prepareLogsAfterUpdates(string $dir, string $project): void
    {
        $this->wp($dir, $project, ['config', 'set', 'WP_DEBUG', 'true', '--raw'], true);
        $this->wp($dir, $project, ['config', 'set', 'WP_DEBUG_LOG', 'true', '--raw'], true);
        $this->wp($dir, $project, ['config', 'set', 'WP_DEBUG_DISPLAY', 'false', '--raw'], true);

        foreach (['http://localhost/', 'http://localhost/wp-login.php'] as $url) {
            $this->docker($dir, ['compose', '-p', $project, 'exec', '-T', 'wp', 'curl', '-s', '-o', '/dev/null', '-w', '%{http_code}', $url], 30, true);
        }
    }

    public function collectRuntimeLogs(Site $site): string
    {
        $dir = $this->cloneDir($site);
        $project = $this->projectName($site);
        $chunks = [];

        foreach (['wp', 'db'] as $svc) {
            $out = $this->docker($dir, ['compose', '-p', $project, 'logs', '--no-color', '--tail=120', $svc], 45, true);
            if (trim($out) !== '') {
                $chunks[] = "=== docker {$svc} ===\n".mb_substr($out, -8000);
            }
        }

        $apache = $this->docker(
            $dir,
            ['compose', '-p', $project, 'exec', '-T', 'wp', 'sh', '-c', 'tail -n 80 /var/log/apache2/error.log 2>/dev/null || true'],
            30,
            true
        );
        if (trim($apache) !== '') {
            $chunks[] = "=== apache error.log ===\n".mb_substr($apache, -4000);
        }

        $debug = $dir.'/html/wp-content/debug.log';
        if (is_file($debug)) {
            $chunks[] = "=== wp-content/debug.log ===\n".mb_substr((string) file_get_contents($debug), -12000);
        }

        return implode("\n\n", $chunks);
    }

    public function destroy(Site $site): void
    {
        $dir = $this->cloneDir($site);
        if (is_dir($dir)) {
            $this->docker($dir, ['compose', '-p', $this->projectName($site), 'down', '--volumes', '--remove-orphans'], 180, true);
            $this->removeDirectory($dir);
        }
        $site->update(['dev_clone' => null]);
    }

    // ---------------------------------------------------------------
    // Backup-Kette und Extraktion
    // ---------------------------------------------------------------

    /** @return list<SiteBackup> aeltestes (Voll-Backup) zuerst */
    private function backupChain(Site $site, SiteBackup $latest): array
    {
        $chain = [$latest];
        $guard = 0;
        $current = $latest;
        while ($current->type === 'incremental' && $current->parent_backup_id && $guard++ < 30) {
            $parent = SiteBackup::where('site_id', $site->id)
                ->where('backup_id', $current->parent_backup_id)
                ->where('status', 'stored')
                ->first();
            if (! $parent) {
                throw new RuntimeException("Backup-Kette unvollständig: {$current->parent_backup_id} fehlt auf dem Server.");
            }
            array_unshift($chain, $parent);
            $current = $parent;
        }
        if ($chain[0]->type !== 'full') {
            throw new RuntimeException('Backup-Kette enthält kein Voll-Backup.');
        }

        return $chain;
    }

    /**
     * Entpackt ein Server-Archiv (einzelnes Zip oder Verzeichnis mit files*.zip)
     * in das Clone-Verzeichnis. Liefert das Manifest zurueck.
     */
    private function extractArchive(SiteBackup $backup, string $dir, bool $isBase): array
    {
        $path = (string) $backup->storage_path;
        $work = $dir.'/work';
        $cleanupWork = true;

        if ($path !== '' && is_dir($path)) {
            $work = $path;
            $cleanupWork = false;
        } elseif ($path !== '' && is_file($path)) {
            @mkdir($work, 0755, true);
            $zip = new ZipArchive;
            if ($zip->open($path) !== true) {
                throw new RuntimeException("Archiv nicht lesbar: {$backup->backup_id}");
            }
            $zip->extractTo($work);
            $zip->close();
        } else {
            throw new RuntimeException("Archiv fehlt auf dem Server: {$backup->backup_id}");
        }

        // database.sql: die neueste in der Kette gewinnt
        if (is_file($work.'/database.sql')) {
            copy($work.'/database.sql', $dir.'/database.sql');
        }

        $html = $dir.'/html';
        @mkdir($html, 0755, true);
        $parts = [];
        foreach (glob($work.'/files*.zip') ?: [] as $part) {
            $base = basename((string) $part);
            if ($base === 'files.zip' || preg_match('/^files-\d+\.zip$/', $base) === 1) {
                $parts[] = (string) $part;
            }
        }
        usort($parts, static function (string $a, string $b): int {
            $na = basename($a) === 'files.zip' ? 1 : (int) preg_replace('/\D+/', '', basename($a));
            $nb = basename($b) === 'files.zip' ? 1 : (int) preg_replace('/\D+/', '', basename($b));

            return $na <=> $nb;
        });
        if ($parts === [] && $isBase) {
            throw new RuntimeException('Voll-Backup enthält kein files.zip.');
        }
        foreach ($parts as $part) {
            $inner = new ZipArchive;
            if ($inner->open($part) !== true) {
                throw new RuntimeException(basename($part).' nicht lesbar: '.$backup->backup_id);
            }
            $inner->extractTo($html);
            $inner->close();
        }

        $manifest = [];
        if (is_file($work.'/manifest.json')) {
            $manifest = json_decode((string) file_get_contents($work.'/manifest.json'), true) ?: [];
        }

        if ($cleanupWork) {
            $this->removeDirectory($work);
        }

        return $manifest;
    }

    public function clonePublicUrl(int $port): string
    {
        $configured = rtrim((string) config('wwc.clone_base_url'), '/');
        $host = strtolower((string) (parse_url($configured, PHP_URL_HOST) ?: ''));
        $scheme = strtolower((string) (parse_url($configured, PHP_URL_SCHEME) ?: 'http'));

        // Explizite LAN-Basis (nicht Cloudflare-Portal, nicht localhost)
        if ($host !== '' && ! $this->isLoopbackHost($host) && ! $this->isProxiedPortalHost($host)) {
            return $scheme.'://'.$host.':'.$port;
        }

        if (app()->environment('local')) {
            $portal = rtrim((string) config('wwc.portal_url', 'http://localhost:3000'), '/');
            if ($this->isLoopbackHost((string) parse_url($portal, PHP_URL_HOST))) {
                return $portal.'/clone/'.$port;
            }

            return 'http://localhost:'.$port;
        }

        return $this->publicPortalOrigin().'/clone/'.$port;
    }

    public function cloneLanUrl(int $port): string
    {
        $configured = rtrim((string) config('wwc.clone_base_url'), '/');
        $host = strtolower((string) (parse_url($configured, PHP_URL_HOST) ?: ''));
        if ($host !== '' && ! $this->isLoopbackHost($host) && ! $this->isProxiedPortalHost($host)) {
            $scheme = strtolower((string) (parse_url($configured, PHP_URL_SCHEME) ?: 'http'));

            return $scheme.'://'.$host.':'.$port;
        }

        $lan = (string) config('wwc.clone_lan_host', '192.168.1.248');

        return 'http://'.$lan.':'.$port;
    }

    public function urlNeedsCloudflareRepair(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: 0);

        return $port >= 9100 && $port <= 9299 && $this->isProxiedPortalHost($host);
    }

    public function repairPublicUrl(Site $site): void
    {
        $clone = $site->dev_clone ?? [];
        $port = (int) ($clone['port'] ?? 0);
        if ($port < 9100) {
            return;
        }
        $old = (string) ($clone['url'] ?? '');
        $new = $this->clonePublicUrl($port);
        if ($old === $new) {
            $this->setState($site, ['lan_url' => $this->cloneLanUrl($port)]);

            return;
        }

        $dir = $this->cloneDir($site);
        $this->rewriteCloneHome($dir, $new);
        if ($old !== '') {
            $this->wp($dir, $this->projectName($site), ['search-replace', $old, $new, '--all-tables', '--report-changed-only'], true);
        }
        $this->setState($site, [
            'url' => $new,
            'lan_url' => $this->cloneLanUrl($port),
            'url_repaired' => now()->toIso8601String(),
        ]);
    }

    private function publicPortalOrigin(): string
    {
        foreach ([
            (string) config('wwc.portal_url'),
            (string) config('app.url'),
            'https://wwc.kiservicehub.de',
        ] as $candidate) {
            $host = strtolower((string) (parse_url($candidate, PHP_URL_HOST) ?: ''));
            if ($host !== '' && ! $this->isLoopbackHost($host)) {
                $scheme = strtolower((string) (parse_url($candidate, PHP_URL_SCHEME) ?: 'https'));

                return $scheme.'://'.$host;
            }
        }

        return 'https://wwc.kiservicehub.de';
    }

    private function isProxiedPortalHost(string $host): bool
    {
        $host = strtolower($host);

        return $host === 'wwc.kiservicehub.de' || str_ends_with($host, '.kiservicehub.de');
    }

    private function rewriteCloneHome(string $dir, string $cloneUrl): void
    {
        $path = $dir.'/html/wp-config.php';
        if (! is_file($path)) {
            return;
        }
        $src = (string) file_get_contents($path);
        $src = preg_replace("/define\('WP_HOME',\s*'[^']*'\);/", "define('WP_HOME', '{$cloneUrl}');", $src) ?? $src;
        $src = preg_replace("/define\('WP_SITEURL',\s*'[^']*'\);/", "define('WP_SITEURL', '{$cloneUrl}');", $src) ?? $src;
        file_put_contents($path, $src);
    }

    private function isLoopbackHost(string $host): bool
    {
        $host = strtolower($host);

        return $host === '' || $host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || str_ends_with($host, '.localhost');
    }

    // ---------------------------------------------------------------
    // Konfiguration
    // ---------------------------------------------------------------

    /** Waehlt das WordPress-Image passend zur PHP-Version der Site ("gleiches Setup"). */
    private function matchPhpImage(Site $site): string
    {
        $version = (string) ($site->php_version ?? '');
        if (preg_match('/^(\d+\.\d+)/', $version, $m) && in_array($m[1], self::PHP_IMAGES, true)) {
            return $m[1];
        }

        return '8.3';
    }

    private function detectTablePrefix(string $wpConfigPath): string
    {
        if (is_file($wpConfigPath)
            && preg_match('/\$table_prefix\s*=\s*[\'"]([a-zA-Z0-9_]+)[\'"]/', (string) file_get_contents($wpConfigPath), $m)) {
            return $m[1];
        }

        return 'wp_';
    }

    private function writeCloneWpConfig(string $dir, string $tablePrefix, string $cloneUrl): void
    {
        $original = $dir.'/html/wp-config.php';
        if (is_file($original)) {
            @rename($original, $dir.'/html/wp-config.orig.php');
        }

        $salts = '';
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $key) {
            $salts .= sprintf("define('%s', '%s');\n", $key, bin2hex(random_bytes(32)));
        }

        $pathPrefix = rtrim((string) (parse_url($cloneUrl, PHP_URL_PATH) ?: ''), '/');
        $cookie = '';
        if ($pathPrefix !== '') {
            $cookie = "define('COOKIEPATH', '{$pathPrefix}/');\n"
                ."define('SITECOOKIEPATH', '{$pathPrefix}/');\n"
                ."define('ADMIN_COOKIE_PATH', '{$pathPrefix}/wp-admin');\n"
                ."define('COOKIE_DOMAIN', '');\n";
        }

        $config = <<<PHP
<?php
// Von WWC generierte Dev-Clone-Konfiguration (Original: wp-config.orig.php)
if (!empty(\$_SERVER['HTTP_X_FORWARDED_PROTO']) && \$_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    \$_SERVER['HTTPS'] = 'on';
}
if (! empty(\$_SERVER['HTTP_X_FORWARDED_PREFIX'])) {
    \$wwcPrefix = rtrim((string) \$_SERVER['HTTP_X_FORWARDED_PREFIX'], '/');
    \$wwcUri = (string) (\$_SERVER['REQUEST_URI'] ?? '/');
    if (\$wwcPrefix !== '' && strncmp(\$wwcUri, \$wwcPrefix, strlen(\$wwcPrefix)) !== 0) {
        \$_SERVER['REQUEST_URI'] = \$wwcPrefix . (\$wwcUri === '' ? '/' : \$wwcUri);
        foreach (['SCRIPT_NAME', 'PHP_SELF'] as \$wwcKey) {
            if (! empty(\$_SERVER[\$wwcKey]) && strncmp((string) \$_SERVER[\$wwcKey], \$wwcPrefix, strlen(\$wwcPrefix)) !== 0) {
                \$_SERVER[\$wwcKey] = \$wwcPrefix . \$_SERVER[\$wwcKey];
            }
        }
    }
}
define('DB_NAME', 'wordpress');
define('DB_USER', 'wordpress');
define('DB_PASSWORD', 'wordpress');
define('DB_HOST', 'db');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

{$salts}
\$table_prefix = '{$tablePrefix}';

define('WP_HOME', '{$cloneUrl}');
define('WP_SITEURL', '{$cloneUrl}');
{$cookie}define('WP_ENVIRONMENT_TYPE', 'staging');
define('AUTOMATIC_UPDATER_DISABLED', true);
define('DISALLOW_FILE_EDIT', true);
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
require_once ABSPATH . 'wp-settings.php';
PHP;
        file_put_contents($dir.'/html/wp-config.php', $config);
    }

    /**
     * HTTPS terminiert am Portal-Proxy; im Container ist Apache nur HTTP.
     * Force-SSL-Regeln aus der Live-Site (WP Fastest Cache, RSSSL, …)
     * wuerden sonst auf https://127.0.0.1:{port}/ umleiten.
     */
    private function neutralizeCloneHtaccess(string $dir): void
    {
        $path = $dir.'/html/.htaccess';
        if (! is_file($path)) {
            return;
        }
        $src = (string) file_get_contents($path);
        $commentSsl = static function (array $m): string {
            $lines = preg_split("/\r\n|\n|\r/", $m[0]) ?: [];
            $out = ['# WWC-Clone: intern HTTP hinter dem Portal-Proxy'];
            foreach ($lines as $line) {
                $out[] = $line === '' || str_starts_with(ltrim($line), '#') ? $line : '# '.$line;
            }

            return implode("\n", $out);
        };
        $src = preg_replace_callback(
            '/RewriteCond\s+%\{HTTPS\}\s*!?=\s*on\s*\n\s*RewriteRule[^\n]*https:\/\/[^\n]*/i',
            $commentSsl,
            $src
        ) ?? $src;
        $src = preg_replace_callback(
            '/RewriteCond\s+%\{HTTP:X-Forwarded-Proto\}\s*!https\s*\n\s*RewriteRule[^\n]*https:\/\/[^\n]*/i',
            $commentSsl,
            $src
        ) ?? $src;
        file_put_contents($path, $src);
    }

    private function writeGuardMuPlugin(string $dir): void
    {
        $muDir = $dir.'/html/wp-content/mu-plugins';
        @mkdir($muDir, 0755, true);
        $guard = <<<'PHP'
<?php
/**
 * Plugin Name: WWC Clone Guard
 * Description: Verhindert, dass die Dev-Kopie Mails verschickt oder indexiert wird.
 */
add_filter('pre_wp_mail', '__return_false', 100);
add_filter('wp_robots', function (array $robots): array {
    $robots['noindex'] = true;
    $robots['nofollow'] = true;
    return $robots;
}, 100);
add_action('admin_notices', function (): void {
    echo '<div class="notice notice-warning"><p><strong>WWC Dev-Kopie</strong> – Änderungen hier wirken sich nicht auf die Live-Site aus. Mails sind deaktiviert.</p></div>';
});
PHP;
        file_put_contents($muDir.'/wwc-clone-guard.php', $guard);
    }

    private function writeComposeFile(string $dir, Site $site, int $port, string $phpImage): void
    {
        $host = $this->hostPath($dir);
        $compose = <<<YAML
services:
  db:
    image: mariadb:11
    command: --innodb-buffer-pool-size=256M --sql-mode=NO_ENGINE_SUBSTITUTION
    environment:
      MYSQL_DATABASE: wordpress
      MYSQL_USER: wordpress
      MYSQL_PASSWORD: wordpress
      MYSQL_ROOT_PASSWORD: wordpress
    volumes:
      - dbdata:/var/lib/mysql
      - {$host}/database.sql:/import/dump.sql:ro
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 5s
      timeout: 5s
      retries: 24
      start_period: 40s

  wp:
    image: wordpress:php{$phpImage}-apache
    ports:
      - "{$port}:80"
    volumes:
      - {$host}/html:/var/www/html
    depends_on:
      db:
        condition: service_healthy
    restart: unless-stopped

  cli:
    image: wordpress:cli-php{$phpImage}
    user: "33:33"
    volumes:
      - {$host}/html:/var/www/html
    depends_on:
      db:
        condition: service_healthy
    profiles: ["tools"]

volumes:
  dbdata:
YAML;
        file_put_contents($dir.'/docker-compose.yml', $compose);
    }

    private function waitForHealthyDb(string $dir, string $project): void
    {
        $deadline = time() + 180;
        do {
            $process = new Process(
                ['docker', 'compose', '-p', $project, 'exec', '-T', 'db', 'healthcheck.sh', '--connect', '--innodb_initialized'],
                $dir,
                null,
                null,
                20
            );
            $process->run();
            if ($process->isSuccessful()) {
                return;
            }
            sleep(3);
        } while (time() < $deadline);

        throw new RuntimeException('MariaDB im Clone wird nicht gesund (Healthcheck).');
    }

    private function importDatabase(Site $site, string $dir, string $project): void
    {
        $dump = $dir.'/database.sql';
        if (! is_file($dump) || filesize($dump) < 32) {
            throw new RuntimeException('database.sql fehlt oder ist leer.');
        }

        $this->docker($dir, [
            'compose', '-p', $project, 'exec', '-T', 'db',
            'mariadb', '-uroot', '-pwordpress', '-e',
            'SET GLOBAL innodb_flush_log_at_trx_commit=2; SET GLOBAL sync_binlog=0;',
        ], 30, true);

        $process = new Process(
            [
                'docker', 'compose', '-p', $project, 'exec', '-T', 'db',
                'sh', '-c',
                'MYSQL_PWD=wordpress mariadb -uroot --binary-mode --init-command="SET SESSION sql_mode=NO_ENGINE_SUBSTITUTION; SET SESSION foreign_key_checks=0; SET SESSION unique_checks=0;" wordpress < /import/dump.sql',
            ],
            $dir,
            null,
            null,
            1800
        );
        $process->start();
        $lastTables = -1;
        while ($process->isRunning()) {
            $tables = $this->countImportedTables($dir, $project);
            if ($tables !== $lastTables) {
                $lastTables = $tables;
                $this->setState($site, [
                    'status' => 'building',
                    'message' => $tables > 0
                        ? "WordPress-Datenbank importieren ({$tables} Tabellen)…"
                        : 'WordPress-Datenbank importieren…',
                    'error' => null,
                ]);
            }
            sleep(4);
        }
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Datenbank-Import fehlgeschlagen: '.mb_substr(trim($process->getErrorOutput() ?: $process->getOutput()), -400));
        }

        $this->docker($dir, [
            'compose', '-p', $project, 'exec', '-T', 'db',
            'mariadb', '-uroot', '-pwordpress', '-e',
            'SET GLOBAL innodb_flush_log_at_trx_commit=1; SET GLOBAL sync_binlog=1;',
        ], 30, true);
    }

    private function countImportedTables(string $dir, string $project): int
    {
        $process = new Process(
            [
                'docker', 'compose', '-p', $project, 'exec', '-T', 'db',
                'mariadb', '-uroot', '-pwordpress', '-N', '-e',
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema="wordpress"',
            ],
            $dir,
            null,
            null,
            15
        );
        $process->run();
        if (! $process->isSuccessful()) {
            return 0;
        }

        return (int) trim($process->getOutput());
    }

    private function dockerAvailable(): bool
    {
        $process = new Process(['docker', 'compose', 'version'], null, null, null, 8);
        $process->run();

        return $process->isSuccessful();
    }

    // ---------------------------------------------------------------
    // Docker-Ausfuehrung
    // ---------------------------------------------------------------

    private function docker(string $dir, array $args, int $timeout, bool $allowFailure = false): string
    {
        $process = new Process(['docker', ...$args], $dir, null, null, $timeout);
        $process->run();
        if (! $process->isSuccessful()) {
            Log::info('dev-clone docker cmd failed', [
                'args' => implode(' ', $args),
                'exit' => $process->getExitCode(),
                'stderr' => mb_substr(trim($process->getErrorOutput()), -300),
                'stdout' => mb_substr(trim($process->getOutput()), -300),
            ]);
        }
        if (! $process->isSuccessful() && ! $allowFailure) {
            throw new RuntimeException('docker '.implode(' ', array_slice($args, 0, 4)).' fehlgeschlagen: '.mb_substr(trim($process->getErrorOutput() ?: $process->getOutput()), -400));
        }

        return $process->getOutput();
    }

    private function wp(string $dir, string $project, array $args, bool $allowFailure = false): string
    {
        return $this->docker($dir, [
            'compose', '-p', $project, 'run', '--rm', '--no-deps',
            'cli', 'wp', '--skip-plugins', '--skip-themes', ...$args,
        ], 300, $allowFailure);
    }

    private function waitForWordPress(string $dir, string $project): void
    {
        $deadline = time() + 180;
        $lastError = '';
        do {
            $process = new Process(
                [
                    'docker', 'compose', '-p', $project, 'run', '--rm', '--no-deps',
                    'cli', 'wp', '--skip-plugins', '--skip-themes', 'core', 'is-installed',
                ],
                $dir,
                null,
                null,
                60
            );
            $process->run();
            if ($process->isSuccessful()) {
                return;
            }
            $lastError = trim($process->getErrorOutput() ?: $process->getOutput());
            sleep(5);
        } while (time() < $deadline);

        throw new RuntimeException(
            'WordPress im Clone antwortet nicht: '.mb_substr($lastError !== '' ? $lastError : 'wp core is-installed ohne Ausgabe', -400)
        );
    }

    // ---------------------------------------------------------------
    // Pfade, Ports, Status
    // ---------------------------------------------------------------

    public function isReady(Site $site): bool
    {
        return ($site->dev_clone['status'] ?? '') === 'ready';
    }

    public function installIntelOnClone(Site $site): void
    {
        $dir = $this->cloneDir($site);
        $mu = $dir.'/html/wp-content/mu-plugins';
        if (! is_dir($mu) && ! @mkdir($mu, 0755, true) && ! is_dir($mu)) {
            throw new RuntimeException('mu-plugins im Clone nicht schreibbar.');
        }
        $src = $this->intelLibPath();
        copy($src, $mu.'/wwc-site-intel-lib.php');
        file_put_contents($mu.'/wwc-site-intel.php', <<<'PHP'
<?php
/**
 * Plugin Name: WWC Site Intel
 * Description: Scan und Content-Apply für die isolierte Dev-Kopie.
 */
if (! class_exists('WWC_Agent_Site_Intel')) {
    require_once __DIR__ . '/wwc-site-intel-lib.php';
}
PHP);
        // Der Runner darf NICHT in mu-plugins liegen: WordPress laedt jede
        // PHP-Datei dort bei jedem Bootstrap (inkl. `wp core is-installed`)
        // und wuerde sonst sofort scan() ausfuehren, bevor $wp_rewrite da ist.
        @unlink($mu.'/wwc-intel-run.php');
        file_put_contents($dir.'/html/wp-content/wwc-intel-run.php', <<<'PHP'
<?php
if (! class_exists('WWC_Agent_Site_Intel')) {
    require_once WP_CONTENT_DIR . '/mu-plugins/wwc-site-intel-lib.php';
}
$mode = (isset($args[0]) && is_string($args[0])) ? $args[0] : 'scan';
if ($mode === 'apply') {
    $ops = json_decode((string) file_get_contents(WP_CONTENT_DIR . '/wwc-content-ops.json'), true);
    echo json_encode(WWC_Agent_Site_Intel::apply(is_array($ops) ? $ops : []));
    return;
}
echo json_encode(WWC_Agent_Site_Intel::scan());
PHP);
    }

    public function scanClone(Site $site): array
    {
        $this->installIntelOnClone($site);
        $out = $this->wp(
            $this->cloneDir($site),
            $this->projectName($site),
            ['eval-file', '/var/www/html/wp-content/wwc-intel-run.php', 'scan']
        );
        $json = $this->decodeWpJson($out);
        if (! is_array($json) || empty($json['ok'])) {
            throw new RuntimeException('Clone-Scan fehlgeschlagen: '.mb_substr(trim($out), 0, 240));
        }

        return $json;
    }

    /**
     * @param  list<array<string, mixed>>  $ops
     * @return array{ok:bool,results:list<array>}
     */
    public function applyOnClone(Site $site, array $ops): array
    {
        $this->installIntelOnClone($site);
        $dir = $this->cloneDir($site);
        $opsPath = $dir.'/html/wp-content/wwc-content-ops.json';
        file_put_contents($opsPath, json_encode($ops, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        try {
            $out = $this->wp($dir, $this->projectName($site), [
                'eval-file', '/var/www/html/wp-content/wwc-intel-run.php', 'apply',
            ]);
        } finally {
            @unlink($opsPath);
        }
        $json = $this->decodeWpJson($out);
        if (! is_array($json)) {
            throw new RuntimeException('Clone-Apply fehlgeschlagen: '.mb_substr(trim($out), 0, 240));
        }

        return $json;
    }

    public function placeCloneUpload(Site $site, string $absolutePath, string $filename): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename) ?: 'upload.bin';
        $dir = $this->cloneDir($site).'/html/wp-content/uploads/wwc-in';
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException('Upload-Ordner im Clone nicht schreibbar.');
        }
        $target = $dir.'/'.$safe;
        if (! copy($absolutePath, $target)) {
            throw new RuntimeException('Datei konnte nicht in die Dev-Kopie kopiert werden.');
        }

        return '/var/www/html/wp-content/uploads/wwc-in/'.$safe;
    }

    /** @return array<string, mixed>|null */
    private function decodeWpJson(string $out): ?array
    {
        $out = trim($out);
        $json = json_decode($out, true);
        if (is_array($json)) {
            return $json;
        }
        if (preg_match('/\{.*\}\s*$/s', $out, $m) === 1) {
            $json = json_decode($m[0], true);
            if (is_array($json)) {
                return $json;
            }
        }

        return null;
    }

    private function intelLibPath(): string
    {
        foreach ([
            rtrim((string) config('wwc.repo_path'), '/').'/packages/wp-agent/includes/class-site-intel.php',
            base_path('resources/wp-agent/includes/class-site-intel.php'),
        ] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        throw new RuntimeException('Site-Intel-Bibliothek fehlt auf dem Server.');
    }

    public function cloneDir(Site $site): string
    {
        $dir = storage_path('app/wwc-clones/'.$site->id);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }

    /** Uebersetzt einen Container-Pfad in den Host-Pfad fuer Docker-Bind-Mounts. */
    private function hostPath(string $containerPath): string
    {
        $base = rtrim((string) config('wwc.clones_host_dir'), '/');
        if ($base === '') {
            $real = realpath($containerPath);

            return $real !== false ? $real : $containerPath;
        }
        $containerBase = storage_path('app/wwc-clones');

        return $base.substr($containerPath, strlen($containerBase));
    }

    private function projectName(Site $site): string
    {
        return 'wwc-clone-'.substr(str_replace('-', '', $site->id), 0, 12);
    }

    private function allocatePort(Site $site): int
    {
        $existing = (int) (($site->dev_clone ?? [])['port'] ?? 0);
        if ($existing > 0) {
            return $existing;
        }

        $min = (int) config('wwc.clone_port_min', 9100);
        $max = (int) config('wwc.clone_port_max', 9299);
        $used = Site::query()
            ->whereNotNull('dev_clone')
            ->where('id', '!=', $site->id)
            ->get()
            ->map(fn (Site $s) => (int) (($s->dev_clone ?? [])['port'] ?? 0))
            ->filter()
            ->all();

        $candidate = $min + (crc32($site->id) % ($max - $min + 1));
        for ($i = 0; $i <= $max - $min; $i++) {
            $port = $min + (($candidate - $min + $i) % ($max - $min + 1));
            if (! in_array($port, $used, true)) {
                return $port;
            }
        }

        throw new RuntimeException('Kein freier Clone-Port verfügbar.');
    }

    private function setState(Site $site, array $patch): void
    {
        $site->update(['dev_clone' => array_merge($site->fresh()->dev_clone ?? [], $patch)]);
    }

    private function resetDirectory(string $dir): void
    {
        foreach (['html', 'work'] as $sub) {
            $this->removeDirectory($dir.'/'.$sub);
        }
        foreach (['database.sql', 'docker-compose.yml'] as $file) {
            if (is_file($dir.'/'.$file)) {
                @unlink($dir.'/'.$file);
            }
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
