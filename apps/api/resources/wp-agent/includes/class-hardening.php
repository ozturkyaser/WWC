<?php

declare(strict_types=1);

/**
 * Site-Haertung: setzt pro Site konfigurierbare Sicherheitsmassnahmen um
 * (Login verstecken, XML-RPC aus, Login-Limit, Security-Header usw.).
 * Die Einstellungen kommen vom WWC-Portal (Befehl security_harden) und
 * werden in der Option wwc_agent_hardening gespeichert.
 */
final class WWC_Agent_Hardening
{
    private const OPTION = 'wwc_agent_hardening';

    private const HTACCESS_MARKER = 'WWC Hardening';

    public const DEFAULTS = [
        'hide_login' => false,
        'login_slug' => '',
        'limit_login_attempts' => false,
        'disable_xmlrpc' => false,
        'disable_file_edit' => false,
        'disable_user_enumeration' => false,
        'hide_wp_version' => false,
        'security_headers' => false,
        'disable_pingbacks' => false,
        'disable_app_passwords' => false,
        'block_php_uploads' => false,
        'disable_directory_listing' => false,
    ];

    public static function settings(): array
    {
        $stored = get_option(self::OPTION, []);

        return array_merge(self::DEFAULTS, is_array($stored) ? $stored : []);
    }

    public static function register(): void
    {
        $s = self::settings();

        if ($s['hide_login'] && $s['login_slug'] !== '') {
            add_action('init', [self::class, 'handle_login_slug'], 1);
            add_action('login_init', [self::class, 'guard_login'], 1);
            add_filter('site_url', [self::class, 'filter_login_url'], 10, 3);
        }

        if ($s['limit_login_attempts']) {
            add_action('wp_login_failed', [self::class, 'record_failed_login']);
            add_action('wp_login', [self::class, 'clear_failed_logins']);
            add_filter('authenticate', [self::class, 'check_login_lockout'], 5, 1);
        }

        if ($s['disable_xmlrpc']) {
            add_filter('xmlrpc_enabled', '__return_false');
            add_action('init', [self::class, 'block_xmlrpc_request'], 1);
        }

        if ($s['disable_file_edit']) {
            add_filter('map_meta_cap', [self::class, 'block_file_edit_caps'], 10, 2);
        }

        if ($s['disable_user_enumeration']) {
            add_action('init', [self::class, 'block_author_enumeration'], 1);
            add_filter('rest_endpoints', [self::class, 'restrict_rest_users']);
        }

        if ($s['hide_wp_version']) {
            remove_action('wp_head', 'wp_generator');
            add_filter('the_generator', '__return_empty_string');
        }

        if ($s['security_headers']) {
            add_action('send_headers', [self::class, 'send_security_headers']);
        }

        if ($s['disable_pingbacks'] || $s['disable_xmlrpc']) {
            add_filter('wp_headers', [self::class, 'remove_pingback_header']);
            add_filter('xmlrpc_methods', [self::class, 'remove_pingback_methods']);
            add_filter('pings_open', '__return_false');
        }

        if ($s['disable_app_passwords']) {
            add_filter('wp_is_application_passwords_available', '__return_false');
        }
    }

    /**
     * Einstellungen speichern, Dateisystem-Massnahmen anwenden und Status melden.
     */
    public static function apply(array $payload): array
    {
        $settings = self::settings();
        foreach (self::DEFAULTS as $key => $default) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            if ($key === 'login_slug') {
                $slug = sanitize_title((string) $payload[$key]);
                $settings[$key] = $slug;
            } else {
                $settings[$key] = (bool) $payload[$key];
            }
        }

        // Login verstecken ohne Slug waere ein Aussperr-Risiko.
        if ($settings['hide_login'] && $settings['login_slug'] === '') {
            $settings['login_slug'] = 'zugang-'.substr(bin2hex(random_bytes(4)), 0, 6);
        }

        update_option(self::OPTION, $settings, true);

        $notes = [];
        $notes = array_merge($notes, self::apply_uploads_htaccess($settings['block_php_uploads']));
        $notes = array_merge($notes, self::apply_root_htaccess($settings['disable_directory_listing']));

        $status = self::status();
        $status['notes'] = $notes;

        return ['ok' => true, 'status' => $status];
    }

    /**
     * Aktuelle Einstellungen plus Report-Only-Checks.
     */
    public static function status(): array
    {
        $settings = self::settings();

        $adminExists = false;
        $admin = get_user_by('login', 'admin');
        if ($admin instanceof WP_User) {
            $adminExists = true;
        }

        global $wpdb;

        $checks = [
            'wp_debug' => defined('WP_DEBUG') && WP_DEBUG,
            'admin_user_exists' => $adminExists,
            'default_table_prefix' => isset($wpdb) && $wpdb->prefix === 'wp_',
            'ssl_active' => is_ssl() || str_starts_with((string) get_option('siteurl'), 'https://'),
            'file_edit_constant' => defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT,
            'uploads_htaccess' => file_exists(self::uploads_htaccess_path()),
        ];

        return [
            'settings' => $settings,
            'checks' => $checks,
            'login_url' => $settings['hide_login'] && $settings['login_slug'] !== ''
                ? home_url('/'.$settings['login_slug'])
                : wp_login_url(),
            'applied_at' => gmdate('c'),
        ];
    }

    // ---- Login verstecken -------------------------------------------------

    private const LOGIN_COOKIE = 'wwc_login_gate';

    public static function handle_login_slug(): void
    {
        $slug = self::settings()['login_slug'];
        $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
        $home = trim((string) parse_url(home_url(), PHP_URL_PATH), '/');
        if ($home !== '' && str_starts_with($path, $home)) {
            $path = trim(substr($path, strlen($home)), '/');
        }

        if ($path === $slug) {
            setcookie(self::LOGIN_COOKIE, self::gate_token(), [
                'expires' => time() + HOUR_IN_SECONDS,
                'path' => '/',
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            wp_safe_redirect(wp_login_url());
            exit;
        }
    }

    public static function guard_login(): void
    {
        if (is_user_logged_in()) {
            return;
        }

        // Aktionen mit eigenem Token (Passwort-Reset, Logout, Double-Opt-in)
        // duerfen weiterhin direkt auf wp-login.php zugreifen.
        $action = (string) ($_REQUEST['action'] ?? 'login');
        if (in_array($action, ['logout', 'postpass', 'rp', 'resetpass', 'lostpassword', 'confirmaction'], true)) {
            return;
        }

        $cookie = (string) ($_COOKIE[self::LOGIN_COOKIE] ?? '');
        if ($cookie !== '' && hash_equals(self::gate_token(), $cookie)) {
            return;
        }

        status_header(404);
        nocache_headers();
        die('404 – Seite nicht gefunden.');
    }

    public static function filter_login_url(string $url, string $path, $scheme): string
    {
        // Login-Links im Frontend auf die geheime URL umbiegen.
        if (str_contains($path, 'wp-login.php') && ! str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), 'wp-login.php')) {
            $slug = self::settings()['login_slug'];
            if ($slug !== '' && ! is_user_logged_in() && empty($_COOKIE[self::LOGIN_COOKIE])) {
                return home_url('/'.$slug);
            }
        }

        return $url;
    }

    private static function gate_token(): string
    {
        return hash_hmac('sha256', 'login-gate', wp_salt('auth'));
    }

    // ---- Login-Limit ------------------------------------------------------

    private const MAX_ATTEMPTS = 5;

    private const LOCKOUT_SECONDS = 900;

    public static function record_failed_login(): void
    {
        $key = self::lockout_key();
        $attempts = (int) get_transient($key);
        set_transient($key, $attempts + 1, self::LOCKOUT_SECONDS);
    }

    public static function clear_failed_logins(): void
    {
        delete_transient(self::lockout_key());
    }

    public static function check_login_lockout($user)
    {
        if ((int) get_transient(self::lockout_key()) >= self::MAX_ATTEMPTS) {
            return new WP_Error(
                'wwc_locked_out',
                __('Zu viele fehlgeschlagene Anmeldeversuche. Bitte in 15 Minuten erneut versuchen.')
            );
        }

        return $user;
    }

    private static function lockout_key(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

        return 'wwc_lockout_'.md5($ip);
    }

    // ---- Weitere Massnahmen -----------------------------------------------

    public static function block_xmlrpc_request(): void
    {
        if (str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), 'xmlrpc.php')) {
            status_header(403);
            die('XML-RPC ist deaktiviert.');
        }
    }

    public static function block_file_edit_caps(array $caps, string $cap): array
    {
        if (in_array($cap, ['edit_files', 'edit_plugins', 'edit_themes'], true)) {
            $caps[] = 'do_not_allow';
        }

        return $caps;
    }

    public static function block_author_enumeration(): void
    {
        if (! is_admin() && isset($_GET['author']) && preg_match('/^\d+$/', (string) $_GET['author'])) {
            status_header(403);
            die('Autoren-Abfrage ist deaktiviert.');
        }
    }

    public static function restrict_rest_users(array $endpoints): array
    {
        if (is_user_logged_in()) {
            return $endpoints;
        }

        unset($endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)']);

        return $endpoints;
    }

    public static function send_security_headers(): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    }

    public static function remove_pingback_header(array $headers): array
    {
        unset($headers['X-Pingback']);

        return $headers;
    }

    public static function remove_pingback_methods(array $methods): array
    {
        unset($methods['pingback.ping'], $methods['pingback.extensions.getPingbacks']);

        return $methods;
    }

    // ---- .htaccess-Massnahmen ---------------------------------------------

    private static function uploads_htaccess_path(): string
    {
        $uploads = wp_get_upload_dir();

        return trailingslashit((string) $uploads['basedir']).'.htaccess';
    }

    /** @return string[] Hinweise fuer den Statusbericht */
    private static function apply_uploads_htaccess(bool $enable): array
    {
        $path = self::uploads_htaccess_path();

        if (! $enable) {
            if (file_exists($path) && str_contains((string) file_get_contents($path), self::HTACCESS_MARKER)) {
                @unlink($path);
            }

            return [];
        }

        $rules = "# BEGIN ".self::HTACCESS_MARKER."\n"
            ."<FilesMatch \"\\.(php|phtml|php3|php4|php5|php7|phps)$\">\n"
            ."  Require all denied\n"
            ."</FilesMatch>\n"
            ."# END ".self::HTACCESS_MARKER."\n";

        if (@file_put_contents($path, $rules) === false) {
            return ['Uploads-.htaccess konnte nicht geschrieben werden (Dateirechte pruefen).'];
        }

        return [];
    }

    /** @return string[] Hinweise fuer den Statusbericht */
    private static function apply_root_htaccess(bool $enable): array
    {
        $path = ABSPATH.'.htaccess';
        if (! file_exists($path)) {
            return $enable ? ['Keine .htaccess gefunden (nginx?): Verzeichnislisten bitte serverseitig deaktivieren.'] : [];
        }

        $content = (string) file_get_contents($path);
        $begin = '# BEGIN '.self::HTACCESS_MARKER;
        $end = '# END '.self::HTACCESS_MARKER;
        $pattern = '/'.preg_quote($begin, '/').'.*?'.preg_quote($end, '/')."\n?/s";
        $content = (string) preg_replace($pattern, '', $content);

        if ($enable) {
            $content = $begin."\nOptions -Indexes\n".$end."\n".ltrim($content);
        }

        if (@file_put_contents($path, $content) === false) {
            return ['.htaccess konnte nicht geschrieben werden (Dateirechte pruefen).'];
        }

        return [];
    }
}
