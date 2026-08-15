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
            'built_at' => $clone['built_at'] ?? null,
        ];
    }

    public function latestUsableBackup(Site $site): ?SiteBackup
    {
        return SiteBackup::where('site_id', $site->id)
            ->where('status', 'stored')
            ->orderByDesc('backup_created_at')
            ->first();
    }

    /**
     * Kompletter Aufbau (laeuft im Queue-Worker). Schreibt Fortschritt in sites.dev_clone.
     */
    public function build(Site $site): void
    {
        $backup = $this->latestUsableBackup($site);
        if (! $backup) {
            $this->setState($site, ['status' => 'failed', 'error' => 'Kein gespeichertes Backup auf dem WWC-Server vorhanden.']);

            return;
        }

        try {
            $this->setState($site, ['status' => 'building', 'error' => null, 'backup_id' => $backup->backup_id]);

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
            $cloneUrl = rtrim((string) config('wwc.clone_base_url'), '/').':'.$port;
            $tablePrefix = $this->detectTablePrefix($dir.'/html/wp-config.php');
            $this->writeCloneWpConfig($dir, $tablePrefix, $cloneUrl);
            $this->writeGuardMuPlugin($dir);
            $this->writeComposeFile($dir, $site, $port, $phpImage);

            // 3. Stack starten (MariaDB importiert database.sql beim ersten Start selbst)
            $project = $this->projectName($site);
            $this->docker($dir, ['compose', '-p', $project, 'down', '--volumes', '--remove-orphans'], 120, true);
            $this->docker($dir, ['compose', '-p', $project, 'up', '-d', '--wait'], 600);

            // Dateirechte fuer den Webserver-Benutzer
            $this->docker($dir, ['compose', '-p', $project, 'run', '--rm', '--user', 'root', 'cli', 'chown', '-R', '33:33', '/var/www/html'], 300);

            // 4. Nacharbeiten per wp-cli: URLs umschreiben, noindex, Admin-Zugang
            $this->waitForWordPress($dir, $project);

            $oldUrl = rtrim((string) ($manifest['site_url'] ?? $site->url), '/');
            if ($oldUrl !== '' && $oldUrl !== $cloneUrl) {
                $this->wp($dir, $project, ['search-replace', $oldUrl, $cloneUrl, '--all-tables', '--report-changed-only'], true);
            }
            $this->wp($dir, $project, ['option', 'update', 'blog_public', '0'], true);

            $adminUser = 'wwc-dev';
            $adminPass = Str::password(20, symbols: false);
            $this->wp($dir, $project, ['user', 'create', $adminUser, 'dev-clone@wwc.local', '--role=administrator', '--user_pass='.$adminPass], true);
            // Falls der User schon existiert (Rebuild): Passwort setzen
            $this->wp($dir, $project, ['user', 'update', $adminUser, '--user_pass='.$adminPass, '--role=administrator'], true);

            $this->setState($site, [
                'status' => 'ready',
                'port' => $port,
                'url' => $cloneUrl,
                'backup_id' => $backup->backup_id,
                'php_image' => $phpImage,
                'admin_user' => $adminUser,
                'admin_pass_encrypted' => Crypt::encryptString($adminPass),
                'error' => null,
                'built_at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Dev clone build failed', ['site' => $site->id, 'error' => $e->getMessage()]);
            $this->setState($site, ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }
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
     * Entpackt ein Server-Archiv (manifest.json, database.sql, files.zip) in das
     * Clone-Verzeichnis. Liefert das Manifest zurueck.
     */
    private function extractArchive(SiteBackup $backup, string $dir, bool $isBase): array
    {
        $path = $backup->storage_path;
        if (! $path || ! is_file($path)) {
            throw new RuntimeException("Archiv fehlt auf dem Server: {$backup->backup_id}");
        }

        $work = $dir.'/work';
        @mkdir($work, 0755, true);

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException("Archiv nicht lesbar: {$backup->backup_id}");
        }
        $zip->extractTo($work);
        $zip->close();

        // database.sql: die neueste in der Kette gewinnt
        if (is_file($work.'/database.sql')) {
            @rename($work.'/database.sql', $dir.'/database.sql');
        }

        $html = $dir.'/html';
        @mkdir($html, 0755, true);
        if (is_file($work.'/files.zip')) {
            $inner = new ZipArchive;
            if ($inner->open($work.'/files.zip') !== true) {
                throw new RuntimeException("files.zip nicht lesbar: {$backup->backup_id}");
            }
            $inner->extractTo($html);
            $inner->close();
        } elseif ($isBase) {
            throw new RuntimeException('Voll-Backup enthält kein files.zip.');
        }

        $manifest = [];
        if (is_file($work.'/manifest.json')) {
            $manifest = json_decode((string) file_get_contents($work.'/manifest.json'), true) ?: [];
        }

        $this->removeDirectory($work);

        return $manifest;
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
