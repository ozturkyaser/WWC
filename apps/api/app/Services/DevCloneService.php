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

        return [
            'status' => $clone['status'] ?? 'unknown',
            'url' => $clone['url'] ?? null,
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
            $this->resetDirectory($dir);

            // 1. Dateien aus der Backup-Kette extrahieren (voll, dann inkrementell darueber)
            $manifest = [];
            foreach ($chain as $i => $step) {
                $manifest = $this->extractArchive($step, $dir, $i === 0) ?: $manifest;
            }

            // 2. Laufzeit konfigurieren
            $port = $this->allocatePort($site);
            $phpImage = $this->matchPhpImage($site);
            $cloneUrl = $this->clonePublicUrl($port);
            $tablePrefix = $this->detectTablePrefix($dir.'/html/wp-config.php');
            $this->writeCloneWpConfig($dir, $tablePrefix, $cloneUrl);
            $this->writeGuardMuPlugin($dir);
            $this->writeComposeFile($dir, $site, $port, $phpImage);

            // 3. Erst NUR die Datenbank starten (importiert database.sql beim ersten Start).
            //    Der Webserver bleibt aus, bis alle Bereinigungen durch sind — sonst koennten
            //    ueberfaellige Cron-Events der Live-Site im Clone feuern (Heartbeat, Self-Update).
            $project = $this->projectName($site);
            $this->docker($dir, ['compose', '-p', $project, 'down', '--volumes', '--remove-orphans'], 120, true);
            $this->docker($dir, ['compose', '-p', $project, 'up', '-d', '--wait', 'db'], 600);

            // Dateirechte fuer den Webserver-Benutzer
            $this->docker($dir, ['compose', '-p', $project, 'run', '--rm', '--user', 'root', 'cli', 'chown', '-R', '33:33', '/var/www/html'], 300);

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
        $this->setState($site, ['last_dry_run' => $report, 'status' => 'ready']);

        return $report;
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

        $useConfigured = $host !== '' && ! $this->isLoopbackHost($host);
        if (! $useConfigured && app()->environment('local') && $host !== '') {
            $useConfigured = true;
        }

        if (! $useConfigured) {
            foreach ([
                (string) config('wwc.clone_base_url'),
                (string) config('wwc.public_api_url'),
                (string) config('wwc.portal_url'),
                (string) config('app.url'),
                'https://wwc.kiservicehub.de',
            ] as $candidate) {
                $ch = strtolower((string) (parse_url($candidate, PHP_URL_HOST) ?: ''));
                if ($ch !== '' && ! $this->isLoopbackHost($ch)) {
                    $host = $ch;
                    $scheme = 'http';
                    break;
                }
            }
        }

        if ($host === '') {
            $host = 'localhost';
            $scheme = 'http';
        }

        return $scheme.'://'.$host.':'.$port;
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

        $config = <<<PHP
<?php
// Von WWC generierte Dev-Clone-Konfiguration (Original: wp-config.orig.php)
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
define('WP_ENVIRONMENT_TYPE', 'staging');
define('AUTOMATIC_UPDATER_DISABLED', true);
define('DISALLOW_FILE_EDIT', true);
define('WP_DEBUG', false);

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
require_once ABSPATH . 'wp-settings.php';
PHP;
        file_put_contents($dir.'/html/wp-config.php', $config);
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
    environment:
      MYSQL_DATABASE: wordpress
      MYSQL_USER: wordpress
      MYSQL_PASSWORD: wordpress
      MYSQL_ROOT_PASSWORD: wordpress
    volumes:
      - {$host}/database.sql:/docker-entrypoint-initdb.d/000-import.sql:ro
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 5s
      timeout: 5s
      retries: 60

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
YAML;
        file_put_contents($dir.'/docker-compose.yml', $compose);
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
        return $this->docker($dir, ['compose', '-p', $project, 'run', '--rm', 'cli', 'wp', ...$args], 300, $allowFailure);
    }

    private function waitForWordPress(string $dir, string $project): void
    {
        $deadline = time() + 180;
        do {
            $process = new Process(['docker', 'compose', '-p', $project, 'run', '--rm', 'cli', 'wp', 'core', 'is-installed'], $dir, null, null, 60);
            $process->run();
            if ($process->isSuccessful()) {
                return;
            }
            sleep(5);
        } while (time() < $deadline);

        throw new RuntimeException('WordPress im Clone antwortet nicht (Datenbank-Import fehlgeschlagen?).');
    }

    // ---------------------------------------------------------------
    // Pfade, Ports, Status
    // ---------------------------------------------------------------

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
            return $containerPath; // API laeuft direkt auf dem Host
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
