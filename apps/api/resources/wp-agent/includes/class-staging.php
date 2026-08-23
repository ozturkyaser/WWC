<?php

declare(strict_types=1);

final class WWC_Agent_Staging
{
    public static function path(): string
    {
        return trailingslashit(WP_CONTENT_DIR).'wwc-staging';
    }

    public static function url(): string
    {
        return trailingslashit(content_url('wwc-staging'));
    }

    public static function status(): array
    {
        $path = self::path();
        $exists = is_dir($path)
            && file_exists($path.'/wp-config.php')
            && file_exists($path.'/wwc-staging-db.json');
        $metaFile = $path.'/wwc-staging.json';
        $meta = file_exists($metaFile) ? json_decode((string) file_get_contents($metaFile), true) : null;

        // Access credentials live in a WP option (never web-accessible), not in the JSON file.
        $access = get_option('wwc_agent_staging_access');
        $access = is_array($access) ? $access : null;
        if ($access === null && is_array($meta) && isset($meta['access'])) {
            // Legacy staging: migrate secrets out of the public JSON file.
            $access = is_array($meta['access']) ? $meta['access'] : null;
            if ($access !== null) {
                update_option('wwc_agent_staging_access', $access, false);
            }
            unset($meta['access']);
            @file_put_contents($metaFile, wp_json_encode($meta));
        }

        return [
            'ok' => true,
            'exists' => $exists,
            'path' => $path,
            'url' => $exists ? self::url() : null,
            'admin_url' => $exists ? trailingslashit(self::url()).'wp-admin/' : null,
            'admin_login_url' => ($exists && is_array($access) && ! empty($access['admin_login_url']))
                ? (string) $access['admin_login_url']
                : (($exists && is_array($access) && ! empty($access['login_token']))
                    ? add_query_arg('wwc_login_token', (string) $access['login_token'], trailingslashit(self::url()).'wp-admin/')
                    : null),
            'access' => $access,
            'meta' => is_array($meta) ? $meta : null,
        ];
    }

    public static function create(bool $withBackup = true): array
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        $jobId = WWC_Agent_Job_Progress::currentJobId() ?: ('local-'.wp_generate_password(8, false, false));
        $work = self::load_work();
        if ($work === null) {
            $work = self::start_work($jobId, $withBackup);
            WWC_Agent_Job_Progress::report(2, 'Development-Umgebung starten…', true);
            WWC_Agent_Job_Progress::log('Zielpfad: '.self::path());
        } else {
            $work['job_id'] = $jobId;
            WWC_Agent_Job_Progress::report(
                (int) ($work['percent'] ?? 8),
                'Development fortsetzen ('.$work['phase'].')…',
                true
            );
        }

        $started = microtime(true);
        $budget = 18;

        if (($work['phase'] ?? '') === 'backup') {
            $latest = WWC_Agent_Backup::latest_full();
            if (is_array($latest) && empty($latest['incomplete']) && ! empty($latest['id'])) {
                $work['backup_id'] = (string) $latest['id'];
                $work['phase'] = 'copy';
                $work['percent'] = 42;
                self::save_work($work);
                WWC_Agent_Job_Progress::log('Vorhandenes Full-Backup genutzt: '.$work['backup_id'], 42, true);
            } elseif (! empty($work['with_backup'])) {
                WWC_Agent_Job_Progress::report(4, 'Sicherheits-Backup vor Staging…', true);
                WWC_Agent_Job_Progress::pushScope(5, 40);
                try {
                    $backup = WWC_Agent_Backup::create_full('pre-staging');
                } finally {
                    WWC_Agent_Job_Progress::popScope();
                }
                if (! ($backup['ok'] ?? false)) {
                    return ['ok' => false, 'error' => 'Backup before staging failed', 'details' => $backup];
                }
                if (! empty($backup['continue'])) {
                    self::save_work($work);

                    return ['ok' => true, 'continue' => true, 'phase' => 'backup'];
                }
                $work['backup_id'] = (string) ($backup['backup']['id'] ?? '');
                $work['phase'] = 'copy';
                $work['percent'] = 42;
                self::save_work($work);
                WWC_Agent_Job_Progress::log('Backup fertig: '.($work['backup_id'] !== '' ? $work['backup_id'] : '?'), 42, true);
            } else {
                $work['phase'] = 'copy';
                $work['percent'] = 42;
                self::save_work($work);
            }
            if (self::slice_exhausted($started, $budget)) {
                return ['ok' => true, 'continue' => true, 'phase' => 'copy'];
            }
        }

        if (($work['phase'] ?? '') === 'copy') {
            $step = self::copy_tree_slice($work, $started, $budget);
            self::save_work($work);
            if (! ($step['ok'] ?? false)) {
                return $step;
            }
            if (($work['phase'] ?? '') === 'copy') {
                return ['ok' => true, 'continue' => true, 'phase' => 'copy'];
            }
        }

        if (($work['phase'] ?? '') === 'configure') {
            $configured = self::configure_staging();
            if (! ($configured['ok'] ?? false)) {
                return $configured;
            }
            $work['phase'] = 'db';
            $work['percent'] = 78;
            $work['db_table_i'] = 0;
            self::save_work($work);
            if (self::slice_exhausted($started, $budget)) {
                return ['ok' => true, 'continue' => true, 'phase' => 'db'];
            }
        }

        if (($work['phase'] ?? '') === 'db') {
            $isolated = self::isolate_database_slice($work, $started, $budget);
            self::save_work($work);
            if (! ($isolated['ok'] ?? false)) {
                return ['ok' => false, 'error' => 'Staging files copied but DB isolation failed', 'details' => $isolated, 'url' => self::url()];
            }
            if (($work['phase'] ?? '') === 'db') {
                return ['ok' => true, 'continue' => true, 'phase' => 'db'];
            }
        }

        WWC_Agent_Job_Progress::report(92, 'Admin-Zugang einrichten…', true);
        $access = self::grant_admin_access();
        WWC_Agent_Job_Progress::log('Staging-User: '.(($access['username'] ?? 'wwc-dev')));
        WWC_Agent_Event_Queue::push('staging_created', 'Development/Staging environment created', 'info', [
            'url' => self::url(),
            'admin_user' => $access['username'] ?? null,
        ]);
        self::clear_work();
        WWC_Agent_Job_Progress::report(98, 'Development bereit', true);

        return [
            'ok' => true,
            'staging' => self::status(),
            'access' => $access,
            'backup_id' => $work['backup_id'] ?? null,
            'message' => 'Staging ready – portal subdomain + admin access available after sync',
        ];
    }

    public static function has_work(): bool
    {
        $work = self::load_work();

        return is_array($work) && in_array((string) ($work['phase'] ?? ''), ['backup', 'copy', 'configure', 'db', 'finish'], true);
    }

    public static function grant_admin_access(): array
    {
        global $wpdb;
        if (! (self::status()['exists'] ?? false)) {
            return ['ok' => false, 'error' => 'No staging environment'];
        }
        $metaFile = self::path().'/wwc-staging-db.json';
        if (! file_exists($metaFile)) {
            return ['ok' => false, 'error' => 'Staging DB metadata missing'];
        }
        $mu = self::path().'/wp-content/mu-plugins';
        wp_mkdir_p($mu);
        file_put_contents($mu.'/wwc-staging-guard.php', self::staging_guard_plugin_source());

        $meta = json_decode((string) file_get_contents($metaFile), true) ?: [];
        $prefix = (string) ($meta['staging_prefix'] ?? 'wwcstg_');
        $username = 'wwc-dev';
        $password = wp_generate_password(20, true, true);
        $email = 'wwc-dev@'.wp_parse_url(home_url(), PHP_URL_HOST);
        $token = bin2hex(random_bytes(24));
        $now = current_time('mysql');
        $hash = wp_hash_password($password);

        $usersTable = $prefix.'users';
        $usermetaTable = $prefix.'usermeta';
        $optionsTable = $prefix.'options';

        $existing = $wpdb->get_var($wpdb->prepare("SELECT ID FROM `{$usersTable}` WHERE user_login = %s", $username));
        if ($existing) {
            $wpdb->update($usersTable, [
                'user_pass' => $hash,
                'user_email' => $email,
            ], ['ID' => (int) $existing]);
            $userId = (int) $existing;
        } else {
            $wpdb->insert($usersTable, [
                'user_login' => $username,
                'user_pass' => $hash,
                'user_nicename' => $username,
                'user_email' => $email,
                'user_url' => '',
                'user_registered' => $now,
                'user_activation_key' => '',
                'user_status' => 0,
                'display_name' => 'WWC Dev',
            ]);
            $userId = (int) $wpdb->insert_id;
        }

        if ($userId > 0) {
            // WordPress looks up "{table_prefix}capabilities" for the running site.
            $capsKey = $prefix.'capabilities';
            $levelKey = $prefix.'user_level';
            $wpdb->query($wpdb->prepare(
                "DELETE FROM `{$usermetaTable}` WHERE user_id = %d AND meta_key IN (%s, %s, 'wp_capabilities', 'wp_user_level')",
                $userId,
                $capsKey,
                $levelKey
            ));
            $wpdb->insert($usermetaTable, [
                'user_id' => $userId,
                'meta_key' => $capsKey,
                'meta_value' => serialize(['administrator' => true]),
            ]);
            $wpdb->insert($usermetaTable, [
                'user_id' => $userId,
                'meta_key' => $levelKey,
                'meta_value' => '10',
            ]);
            // Extra keys some plugins still read
            $wpdb->insert($usermetaTable, [
                'user_id' => $userId,
                'meta_key' => 'nickname',
                'meta_value' => 'WWC Dev Admin',
            ]);
        }

        // Store magic login token in staging options
        $optName = 'wwc_staging_login_token';
        $existsOpt = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM `{$optionsTable}` WHERE option_name = %s", $optName));
        $optValue = wp_json_encode([
            'token' => $token,
            'user_id' => $userId,
            'expires_at' => gmdate('c', time() + 7 * DAY_IN_SECONDS),
        ]);
        if ($existsOpt) {
            $wpdb->update($optionsTable, ['option_value' => $optValue], ['option_name' => $optName]);
        } else {
            $wpdb->insert($optionsTable, [
                'option_name' => $optName,
                'option_value' => $optValue,
                'autoload' => 'no',
            ]);
        }

        // Login URL hits wp-admin directly so session + redirect land in the dashboard
        $adminBase = trailingslashit(self::url()).'wp-admin/';
        $loginUrl = add_query_arg('wwc_login_token', $token, $adminBase);

        $access = [
            'ok' => true,
            'username' => $username,
            'password' => $password,
            'login_token' => $token,
            'admin_url' => $adminBase,
            'admin_login_url' => $loginUrl,
            'expires_at' => gmdate('c', time() + 7 * DAY_IN_SECONDS),
        ];

        // Store credentials in a WP option only – never in web-accessible files.
        update_option('wwc_agent_staging_access', [
            'username' => $username,
            'password' => $password,
            'login_token' => $token,
            'admin_url' => $access['admin_url'],
            'admin_login_url' => $access['admin_login_url'],
            'expires_at' => $access['expires_at'],
        ], false);

        // Scrub secrets that older agent versions wrote into the public JSON file.
        $jsonFile = self::path().'/wwc-staging.json';
        $json = file_exists($jsonFile) ? (json_decode((string) file_get_contents($jsonFile), true) ?: []) : [];
        if (isset($json['access'])) {
            unset($json['access']);
            file_put_contents($jsonFile, wp_json_encode($json));
        }

        // Refresh .htaccess so existing stagings get the deny rules too.
        file_put_contents(self::path().'/.htaccess', self::staging_htaccess());

        return $access;
    }

    private static function staging_htaccess(): string
    {
        $base = parse_url(self::url(), PHP_URL_PATH) ?: '/wp-content/wwc-staging/';
        $base = '/'.trim((string) $base, '/').'/';

        return "# WWC Staging\nOptions -Indexes\n"
            // Never serve staging metadata files (defense in depth; secrets live in WP options)
            ."<FilesMatch \"^wwc-staging.*\\.json$\">\n"
            ."<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
            ."<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n"
            ."</FilesMatch>\n"
            ."<IfModule mod_rewrite.c>\n"
            ."RewriteEngine On\n"
            ."RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n"
            .'RewriteBase '.$base."\n"
            ."RewriteRule ^index\\.php$ - [L]\n"
            ."RewriteCond %{REQUEST_FILENAME} !-f\n"
            ."RewriteCond %{REQUEST_FILENAME} !-d\n"
            .'RewriteRule . '.$base."index.php [L]\n"
            ."</IfModule>\n";
    }

    private static function staging_guard_plugin_source(): string
    {
        return <<<'PHP'
<?php
/**
 * Plugin Name: WWC Staging Guard
 * Description: Magic admin login + full WP-Admin for dry-run verification.
 */
if (! defined('ABSPATH')) { exit; }

add_action('plugins_loaded', static function () {
    if (! defined('WWC_STAGING_ENV')) {
        define('WWC_STAGING_ENV', true);
    }
}, 1);

add_filter('pre_option_blog_public', static fn () => '0');

// Allow portal iframe live-preview
remove_action('login_init', 'send_frame_options_header');
remove_action('admin_init', 'send_frame_options_header');
add_action('send_headers', static function () {
    header_remove('X-Frame-Options');
    header('Content-Security-Policy: frame-ancestors *');
}, 0);

/**
 * One-click administrator login for portal "WP-Admin" button.
 */
$wwc_staging_login = static function (): void {
    if (empty($_GET['wwc_login_token']) || (defined('WP_CLI') && WP_CLI)) {
        return;
    }
    $raw = get_option('wwc_staging_login_token');
    $data = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
    $token = sanitize_text_field(wp_unslash((string) $_GET['wwc_login_token']));
    if (! is_array($data) || empty($data['token']) || ! hash_equals((string) $data['token'], $token)) {
        wp_die('WWC Staging Login: ungültiger oder fehlender Token. Im Portal „Admin erneuern“ klicken.');
    }
    if (! empty($data['expires_at']) && strtotime((string) $data['expires_at']) < time()) {
        wp_die('WWC Staging Login: Token abgelaufen. Im Portal „Admin erneuern“ klicken.');
    }
    $userId = (int) ($data['user_id'] ?? 0);
    $user = $userId ? get_user_by('id', $userId) : get_user_by('login', 'wwc-dev');
    if (! $user instanceof WP_User) {
        wp_die('WWC Staging Login: Benutzer wwc-dev fehlt. Im Portal „Admin erneuern“ klicken.');
    }

    // Force full administrator capabilities (fixes missing admin menu after dry-run login)
    $user->set_role('administrator');
    clean_user_cache($user->ID);

    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, true, is_ssl());
    do_action('wp_login', $user->user_login, $user);

    nocache_headers();
    // Hard redirect into dashboard (avoid wp_safe_redirect host mismatches on subdirectory staging)
    $target = admin_url('index.php');
    if (! headers_sent()) {
        header('Location: '.$target, true, 302);
    }
    echo '<!DOCTYPE html><html><head><meta http-equiv="refresh" content="0;url='.esc_attr($target).'"><script>location.replace('.wp_json_encode($target).');</script></head><body><p><a href="'.esc_url($target).'">Zum WP-Admin</a></p></body></html>';
    exit;
};

add_action('init', $wwc_staging_login, 0);
add_action('admin_init', $wwc_staging_login, 0);
add_action('login_init', $wwc_staging_login, 0);

// Always show admin bar for staging admins
add_filter('show_admin_bar', static function ($show) {
    if (is_user_logged_in() && current_user_can('manage_options')) {
        return true;
    }
    return $show;
}, 100);

// Frontend banner with direct admin link after login
add_action('wp_footer', static function () {
    if (! is_user_logged_in() || ! current_user_can('manage_options')) {
        return;
    }
    $admin = admin_url();
    echo '<div style="position:fixed;z-index:999999;left:16px;right:16px;bottom:16px;padding:14px 18px;border-radius:12px;background:#0f172a;color:#fff;font:14px/1.4 system-ui,sans-serif;box-shadow:0 10px 40px rgba(0,0,0,.35);display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;">'
        .'<span><strong>WWC Staging / Dry-Run</strong> – du bist als Admin angemeldet. Prüfe Plugins, Themes und das Frontend vor Promote.</span>'
        .'<a href="'.esc_url($admin).'" style="background:#3d9a7a;color:#fff;text-decoration:none;padding:8px 14px;border-radius:8px;font-weight:600;">WP-Admin öffnen</a>'
        .'</div>';
});

add_action('admin_notices', static function () {
    echo '<div class="notice notice-warning"><p><strong>WWC Staging / Dry-Run</strong> – voller Admin-Zugang zur Prüfung. Live erst nach „Promote to Live“.</p></div>';
});
PHP;
    }


    public static function destroy(): array
    {
        $path = self::path();
        if (is_dir($path)) {
            self::drop_staging_tables($path);
            self::rrmdir($path);
        }
        delete_option('wwc_agent_staging_access');
        self::clear_work();
        WWC_Agent_Event_Queue::push('staging_removed', 'Staging environment removed', 'info');

        return ['ok' => true];
    }

    /**
     * Apply an update inside staging directory context (dry-run).
     */
    public static function run_update(string $command, array $payload = []): array
    {
        $status = self::status();
        if (! $status['exists']) {
            return ['ok' => false, 'error' => 'No staging environment. Create staging first.'];
        }

        $stagingLoad = self::path().'/wp-load.php';
        if (! file_exists($stagingLoad)) {
            return ['ok' => false, 'error' => 'Staging wp-load.php missing'];
        }

        $slug = (string) ($payload['slug'] ?? '');
        WWC_Agent_Job_Progress::report(8, 'Dry-Run vorbereiten…', true);
        WWC_Agent_Job_Progress::log('Staging: '.self::url());

        $result = match ($command) {
            'update_plugin' => self::update_plugin_in_staging($slug),
            'update_theme' => self::update_theme_in_staging($slug),
            'update_core' => ['ok' => false, 'error' => 'Core dry-run in staging: use full staging recreate after testing plugins/themes first'],
            default => ['ok' => false, 'error' => 'Unsupported staging command'],
        };

        $metaFile = self::path().'/wwc-staging.json';
        $meta = file_exists($metaFile) ? (json_decode((string) file_get_contents($metaFile), true) ?: []) : [];
        $meta['last_dry_run'] = [
            'command' => $command,
            'payload' => $payload,
            'result' => $result,
            'at' => gmdate('c'),
        ];
        file_put_contents($metaFile, wp_json_encode($meta));

        $ok = ($result['ok'] ?? false) === true;
        WWC_Agent_Job_Progress::report(
            $ok ? 95 : 90,
            $ok ? ('Dry-Run ok: '.$slug) : ('Dry-Run fehlgeschlagen: '.($result['error'] ?? $slug)),
            true
        );

        return $result + ['staging' => true, 'url' => self::url()];
    }

    public static function promote_to_live(bool $backupFirst = true): array
    {
        $status = self::status();
        if (! $status['exists']) {
            return ['ok' => false, 'error' => 'No staging environment'];
        }

        if ($backupFirst) {
            $backup = WWC_Agent_Backup::create_full('pre-promote');
            if (! ($backup['ok'] ?? false)) {
                return ['ok' => false, 'error' => 'Live backup before promote failed', 'details' => $backup];
            }
        }

        // Promote wp-content plugins/themes from staging -> live (not uploads by default)
        $paths = [
            'wp-content/plugins',
            'wp-content/themes',
            'wp-content/mu-plugins',
        ];
        foreach ($paths as $rel) {
            $from = self::path().'/'.$rel;
            $to = ABSPATH.$rel;
            if (! is_dir($from)) {
                continue;
            }
            $copy = self::copy_tree($from, $to, ['wwc-staging-guard.php']);
            if (! ($copy['ok'] ?? false)) {
                return $copy;
            }
        }

        // Promote staging DB tables back to live prefix
        $promoted = self::promote_database();
        if (! ($promoted['ok'] ?? false)) {
            return $promoted;
        }

        WWC_Agent_Event_Queue::push('staging_promoted', 'Staging promoted to live', 'warning', [
            'backup_id' => $backup['backup']['id'] ?? null,
        ]);

        return [
            'ok' => true,
            'message' => 'Staging changes promoted to live',
            'backup_id' => $backup['backup']['id'] ?? null,
        ];
    }

    private static function update_plugin_in_staging(string $slug): array
    {
        if ($slug === '') {
            return ['ok' => false, 'error' => 'Missing plugin slug'];
        }
        WWC_Agent_Job_Progress::report(15, 'Plugin prüfen: '.$slug, true);
        if (! function_exists('plugins_api')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
            require_once ABSPATH.'wp-admin/includes/file.php';
            require_once ABSPATH.'wp-admin/includes/misc.php';
            include_once ABSPATH.'wp-admin/includes/plugin-install.php';
        }
        wp_update_plugins();
        $file = null;
        foreach (get_plugins() as $pluginFile => $data) {
            if (dirname($pluginFile) === $slug || str_contains($pluginFile, $slug)) {
                $file = $pluginFile;
                break;
            }
        }
        if (! $file) {
            return ['ok' => false, 'error' => 'Plugin not found on live for staging update'];
        }
        $transient = get_site_transient('update_plugins');
        $update = $transient->response[$file] ?? null;
        if (! $update || empty($update->package)) {
            WWC_Agent_Job_Progress::log('Kein Update-Paket für '.$slug);
            return ['ok' => true, 'message' => 'No update package available', 'slug' => $slug];
        }
        WWC_Agent_Job_Progress::report(40, 'Paket laden: '.$slug, true);
        $tmp = download_url($update->package);
        if (is_wp_error($tmp)) {
            return ['ok' => false, 'error' => $tmp->get_error_message()];
        }
        WWC_Agent_Job_Progress::report(70, 'In Staging entpacken: '.$slug, true);
        $dest = self::path().'/wp-content/plugins';
        WP_Filesystem();
        $unzip = unzip_file($tmp, $dest);
        @unlink($tmp);
        if (is_wp_error($unzip)) {
            return ['ok' => false, 'error' => $unzip->get_error_message()];
        }
        WWC_Agent_Job_Progress::log('Plugin '.$slug.' in Staging aktualisiert');

        return ['ok' => true, 'message' => 'Plugin updated in staging (dry-run)', 'slug' => $slug];
    }

    private static function update_theme_in_staging(string $slug): array
    {
        if ($slug === '') {
            return ['ok' => false, 'error' => 'Missing theme slug'];
        }
        WWC_Agent_Job_Progress::report(15, 'Theme prüfen: '.$slug, true);
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/theme.php';
        wp_update_themes();
        $transient = get_site_transient('update_themes');
        $update = $transient->response[$slug] ?? null;
        if (! $update || empty($update['package'])) {
            WWC_Agent_Job_Progress::log('Kein Theme-Update-Paket für '.$slug);
            return ['ok' => true, 'message' => 'No theme update package available', 'slug' => $slug];
        }
        WWC_Agent_Job_Progress::report(40, 'Paket laden: '.$slug, true);
        $tmp = download_url($update['package']);
        if (is_wp_error($tmp)) {
            return ['ok' => false, 'error' => $tmp->get_error_message()];
        }
        WWC_Agent_Job_Progress::report(70, 'In Staging entpacken: '.$slug, true);
        $dest = self::path().'/wp-content/themes';
        WP_Filesystem();
        $unzip = unzip_file($tmp, $dest);
        @unlink($tmp);
        if (is_wp_error($unzip)) {
            return ['ok' => false, 'error' => $unzip->get_error_message()];
        }
        WWC_Agent_Job_Progress::log('Theme '.$slug.' in Staging aktualisiert');

        return ['ok' => true, 'message' => 'Theme updated in staging (dry-run)', 'slug' => $slug];
    }

    /**
     * @param  array<string, mixed>  $work
     * @return array{ok:bool,error?:string,continue?:bool}
     */
    private static function isolate_database_slice(array &$work, float $started, int $budget): array
    {
        global $wpdb;
        $stagingPath = self::path();
        $prefix = $wpdb->prefix;
        $stagePrefix = 'wwcstg_';
        $tables = $wpdb->get_col('SHOW TABLES');
        if (! is_array($tables)) {
            return ['ok' => false, 'error' => 'No tables'];
        }

        $candidates = [];
        foreach ($tables as $table) {
            $table = (string) $table;
            if (str_starts_with($table, $prefix) && ! str_starts_with($table, $stagePrefix)) {
                $candidates[] = $table;
            }
        }
        $total = max(1, count($candidates));
        $i = (int) ($work['db_table_i'] ?? 0);
        while ($i < count($candidates) && ! self::slice_exhausted($started, $budget)) {
            $table = $candidates[$i];
            $new = $stagePrefix.substr($table, strlen($prefix));
            $wpdb->query("DROP TABLE IF EXISTS `{$new}`");
            $wpdb->query("CREATE TABLE `{$new}` LIKE `{$table}`");
            if (WWC_Agent_Backup::skips_table_data($table)) {
                WWC_Agent_Job_Progress::report(
                    78 + (int) round((($i + 1) / $total) * 10),
                    'DB '.($i + 1).'/'.$total.': '.$table.' übersprungen (Cache)'
                );
            } else {
                $wpdb->query("INSERT INTO `{$new}` SELECT * FROM `{$table}`");
                WWC_Agent_Job_Progress::report(
                    78 + (int) round((($i + 1) / $total) * 10),
                    'DB '.($i + 1).'/'.$total.': '.$table.' → '.$new
                );
            }
            $i++;
            $work['db_table_i'] = $i;
            $work['percent'] = 78 + (int) round(($i / $total) * 10);
        }

        if ($i < count($candidates)) {
            return ['ok' => true, 'continue' => true];
        }

        $home = self::url();
        $wpdb->update($stagePrefix.'options', ['option_value' => $home], ['option_name' => 'siteurl']);
        $wpdb->update($stagePrefix.'options', ['option_value' => $home], ['option_name' => 'home']);
        $wpdb->update($stagePrefix.'options', ['option_value' => '0'], ['option_name' => 'blog_public']);
        WWC_Agent_Job_Progress::log('siteurl/home auf Staging-URL gesetzt');

        $config = $stagingPath.'/wp-config.php';
        if (file_exists($config)) {
            $contents = (string) file_get_contents($config);
            $contents = preg_replace(
                '/\$table_prefix\s*=\s*[\'"].*?[\'"]\s*;/',
                "\$table_prefix = '{$stagePrefix}';",
                $contents,
                1
            ) ?: $contents;
            file_put_contents($config, $contents);
        }

        file_put_contents($stagingPath.'/wwc-staging-db.json', wp_json_encode([
            'live_prefix' => $prefix,
            'staging_prefix' => $stagePrefix,
        ]));

        $work['phase'] = 'finish';
        $work['percent'] = 90;
        WWC_Agent_Job_Progress::log('DB-Prefix '.$stagePrefix.' · '.count($candidates).' Tabellen', 90, true);

        return ['ok' => true, 'staging_prefix' => $stagePrefix, 'tables' => count($candidates)];
    }

    private static function promote_database(): array
    {
        global $wpdb;
        $metaFile = self::path().'/wwc-staging-db.json';
        if (! file_exists($metaFile)) {
            return ['ok' => false, 'error' => 'Staging DB metadata missing'];
        }
        $meta = json_decode((string) file_get_contents($metaFile), true);
        $live = (string) ($meta['live_prefix'] ?? $wpdb->prefix);
        $stage = (string) ($meta['staging_prefix'] ?? 'wwcstg_');
        $tables = $wpdb->get_col('SHOW TABLES');
        if (! is_array($tables)) {
            return ['ok' => false, 'error' => 'No tables'];
        }
        foreach ($tables as $table) {
            if (! str_starts_with($table, $stage)) {
                continue;
            }
            $liveTable = $live.substr($table, strlen($stage));
            $wpdb->query("DROP TABLE IF EXISTS `{$liveTable}`");
            $wpdb->query("CREATE TABLE `{$liveTable}` LIKE `{$table}`");
            $wpdb->query("INSERT INTO `{$liveTable}` SELECT * FROM `{$table}`");
        }
        $home = home_url('/');
        $wpdb->update($live.'options', ['option_value' => $home], ['option_name' => 'siteurl']);
        $wpdb->update($live.'options', ['option_value' => $home], ['option_name' => 'home']);

        return ['ok' => true];
    }

    private static function drop_staging_tables(string $stagingPath): void
    {
        global $wpdb;
        $metaFile = $stagingPath.'/wwc-staging-db.json';
        $prefix = 'wwcstg_';
        if (file_exists($metaFile)) {
            $meta = json_decode((string) file_get_contents($metaFile), true);
            $prefix = (string) ($meta['staging_prefix'] ?? $prefix);
        }
        $tables = $wpdb->get_col('SHOW TABLES');
        if (! is_array($tables)) {
            return;
        }
        foreach ($tables as $table) {
            if (str_starts_with($table, $prefix)) {
                $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function start_work(string $jobId, bool $withBackup): array
    {
        $dest = self::path();
        if (is_dir($dest) && ! is_file($dest.'/wp-config.php')) {
            self::rrmdir($dest);
        }
        if (! is_dir($dest)) {
            wp_mkdir_p($dest);
        }
        $work = [
            'job_id' => $jobId,
            'phase' => 'backup',
            'with_backup' => $withBackup,
            'percent' => 4,
            'copy_last' => '',
            'copy_files' => 0,
            'copy_dirs' => 0,
            'db_table_i' => 0,
        ];
        self::save_work($work);

        return $work;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function load_work(): ?array
    {
        $file = self::work_file();
        if (! is_file($file)) {
            return null;
        }
        $json = json_decode((string) file_get_contents($file), true);

        return is_array($json) ? $json : null;
    }

    /**
     * @param  array<string, mixed>  $work
     */
    private static function save_work(array $work): void
    {
        wp_mkdir_p(dirname(self::work_file()));
        file_put_contents(self::work_file(), wp_json_encode($work));
    }

    private static function clear_work(): void
    {
        if (is_file(self::work_file())) {
            @unlink(self::work_file());
        }
    }

    private static function work_file(): string
    {
        return trailingslashit(WP_CONTENT_DIR).'wwc-backups/staging-work.json';
    }

    private static function slice_exhausted(float $started, int $budget): bool
    {
        $fromRequest = isset($_SERVER['REQUEST_TIME_FLOAT'])
            ? microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT']
            : (microtime(true) - $started);

        return $fromRequest >= $budget || (microtime(true) - $started) >= $budget;
    }

    /**
     * @return array{ok:bool,error?:string}
     */
    private static function configure_staging(): array
    {
        $dest = self::path();
        WWC_Agent_Job_Progress::report(72, 'Staging konfigurieren…', true);
        file_put_contents($dest.'/.htaccess', self::staging_htaccess());
        file_put_contents($dest.'/wwc-staging.json', wp_json_encode([
            'created_at' => gmdate('c'),
            'source_url' => home_url('/'),
            'staging_url' => self::url(),
            'wp_version' => get_bloginfo('version'),
            'mode' => 'dry-run',
        ]));
        WWC_Agent_Job_Progress::log('wwc-staging.json + .htaccess geschrieben');

        $mu = $dest.'/wp-content/mu-plugins';
        wp_mkdir_p($mu);
        file_put_contents($mu.'/wwc-staging-guard.php', self::staging_guard_plugin_source());
        WWC_Agent_Job_Progress::log('MU-Plugin Staging-Guard installiert');

        $configPath = $dest.'/wp-config.php';
        if (file_exists($configPath)) {
            $prepend = "<?php\n".
                "define('WWC_STAGING_ENV', true);\n".
                "define('WP_HOME', '".addcslashes(self::url(), "\\'")."');\n".
                "define('WP_SITEURL', '".addcslashes(self::url(), "\\'")."');\n".
                "if (! defined('DISALLOW_FILE_EDIT')) { define('DISALLOW_FILE_EDIT', false); }\n".
                "if (! defined('DISALLOW_FILE_MODS')) { define('DISALLOW_FILE_MODS', false); }\n".
                "if (! defined('AUTOMATIC_UPDATER_DISABLED')) { define('AUTOMATIC_UPDATER_DISABLED', true); }\n";
            $original = file_get_contents($configPath);
            if (is_string($original) && ! str_contains($original, 'WWC_STAGING_ENV')) {
                $original = preg_replace('/^<\?php\s*/', $prepend, $original, 1) ?: ($prepend.$original);
                file_put_contents($configPath, $original);
                WWC_Agent_Job_Progress::log('wp-config.php für Staging-URLs angepasst', 76);
            }
        }

        return ['ok' => true];
    }

    /**
     * @param  array<string, mixed>  $work
     * @return array{ok:bool,error?:string}
     */
    private static function copy_tree_slice(array &$work, float $started, int $budget): array
    {
        $src = rtrim(ABSPATH, '/\\');
        $dst = rtrim(self::path(), '/\\');
        if (! is_dir($src)) {
            return ['ok' => false, 'error' => 'Source missing'];
        }
        wp_mkdir_p($dst);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $files = (int) ($work['copy_files'] ?? 0);
        $dirs = (int) ($work['copy_dirs'] ?? 0);
        $resumeAfter = (string) ($work['copy_last'] ?? '');
        $skipping = $resumeAfter !== '';
        $lastReport = $files;

        foreach ($iterator as $item) {
            if (self::slice_exhausted($started, $budget)) {
                $work['copy_files'] = $files;
                $work['copy_dirs'] = $dirs;
                $work['percent'] = min(69, 45 + (int) floor($files / 800));
                WWC_Agent_Job_Progress::report((int) $work['percent'], 'Kopiere… '.$files.' Dateien', true);

                return ['ok' => true];
            }
            $absolute = $item->getPathname();
            $rel = ltrim(str_replace('\\', '/', substr($absolute, strlen($src))), '/');
            if (WWC_Agent_Backup::skips_rel($rel)) {
                continue;
            }
            if ($skipping) {
                if ($rel === $resumeAfter) {
                    $skipping = false;
                }
                continue;
            }
            $target = $dst.'/'.$rel;
            if ($item->isDir()) {
                wp_mkdir_p($target);
                $dirs++;
            } else {
                if (is_file($target) && (int) filesize($target) === (int) $item->getSize()) {
                    $files++;
                    $work['copy_last'] = $rel;
                    continue;
                }
                wp_mkdir_p(dirname($target));
                if (! @copy($absolute, $target)) {
                    return ['ok' => false, 'error' => 'Copy failed: '.$rel];
                }
                $files++;
            }
            $work['copy_last'] = $rel;
            $work['copy_files'] = $files;
            $work['copy_dirs'] = $dirs;
            if ($files - $lastReport >= 200) {
                $work['percent'] = min(69, 45 + (int) floor($files / 800));
                WWC_Agent_Job_Progress::report((int) $work['percent'], 'Kopiere… '.$files.' Dateien · '.$rel, true);
                $lastReport = $files;
            }
        }

        if ($skipping) {
            $work['copy_last'] = '';
            $work['copy_files'] = $files;
            $work['copy_dirs'] = $dirs;

            return ['ok' => true];
        }

        $work['copy_files'] = $files;
        $work['copy_dirs'] = $dirs;
        $work['phase'] = 'configure';
        $work['percent'] = 71;
        WWC_Agent_Job_Progress::log('Kopiert: '.$files.' Dateien / '.$dirs.' Ordner', 71, true);

        return ['ok' => true];
    }

    /**
     * @param  list<string>  $skipContains
     * @return array{ok:bool,error?:string,files?:int,dirs?:int}
     */
    private static function copy_tree(string $src, string $dst, array $skipContains = []): array
    {
        $src = rtrim($src, '/\\');
        $dst = rtrim($dst, '/\\');
        if (! is_dir($src)) {
            return ['ok' => false, 'error' => 'Source missing'];
        }
        wp_mkdir_p($dst);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $files = 0;
        $dirs = 0;
        foreach ($iterator as $item) {
            $absolute = $item->getPathname();
            $rel = ltrim(str_replace('\\', '/', substr($absolute, strlen($src))), '/');
            $skip = false;
            foreach ($skipContains as $needle) {
                if ($rel === $needle || str_contains($rel, $needle)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }
            $target = $dst.'/'.$rel;
            if ($item->isDir()) {
                wp_mkdir_p($target);
                $dirs++;
            } else {
                wp_mkdir_p(dirname($target));
                if (! @copy($absolute, $target)) {
                    return ['ok' => false, 'error' => 'Copy failed: '.$rel];
                }
                $files++;
            }
        }

        return ['ok' => true, 'files' => $files, 'dirs' => $dirs];
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
