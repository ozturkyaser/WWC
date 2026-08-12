<?php

declare(strict_types=1);

final class WWC_Agent_Self_Updater
{
    public static function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'check']);
        add_filter('plugins_api', [self::class, 'plugins_api'], 10, 3);
        add_action('upgrader_process_complete', [self::class, 'after_upgrade'], 10, 2);
        add_action('admin_post_wwc_agent_self_update', [self::class, 'handle_admin_update']);
    }

    public static function check($transient)
    {
        if (! is_object($transient)) {
            return $transient;
        }

        $data = self::fetch_latest();
        if (! $data) {
            return $transient;
        }

        if (version_compare(WWC_AGENT_VERSION, (string) $data['version'], '>=')) {
            return $transient;
        }

        if (! self::verify_package_optional($data)) {
            return $transient;
        }

        $plugin_file = plugin_basename(WWC_AGENT_FILE);
        $transient->response[$plugin_file] = (object) [
            'slug' => 'wwc-agent',
            'plugin' => $plugin_file,
            'new_version' => (string) $data['version'],
            'package' => (string) $data['package'],
            'url' => (string) ($data['url'] ?? ''),
            'icons' => [],
            'banners' => [],
            'tested' => get_bloginfo('version'),
            'requires_php' => '8.1',
        ];

        return $transient;
    }

    public static function plugins_api($result, $action, $args)
    {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'wwc-agent') {
            return $result;
        }
        $data = self::fetch_latest();
        if (! $data) {
            return $result;
        }

        return (object) [
            'name' => 'WWC Agent',
            'slug' => 'wwc-agent',
            'version' => (string) $data['version'],
            'author' => '<a href="https://wwc.local">WWC</a>',
            'homepage' => (string) ($data['url'] ?? ''),
            'requires' => '6.0',
            'tested' => get_bloginfo('version'),
            'requires_php' => '8.1',
            'sections' => [
                'description' => 'WWC Wartungs-Agent für Remote-Management, Backups und Development.',
                'changelog' => 'Version '.(string) $data['version'].' – Update über das WWC-Portal / API.',
            ],
            'download_link' => (string) $data['package'],
        ];
    }

    public static function after_upgrade($upgrader, $options): void
    {
        if (($options['type'] ?? '') !== 'plugin') {
            return;
        }
        $plugin = plugin_basename(WWC_AGENT_FILE);
        $updated = false;
        if (! empty($options['plugins']) && is_array($options['plugins'])) {
            $updated = in_array($plugin, $options['plugins'], true);
        }
        if (! empty($options['plugin'])) {
            $updated = $updated || $options['plugin'] === $plugin;
        }
        if ($updated && ! is_plugin_active($plugin)) {
            activate_plugin($plugin, '', false, true);
        }
    }

    public static function handle_admin_update(): void
    {
        if (! current_user_can('update_plugins')) {
            wp_die('Forbidden');
        }
        check_admin_referer('wwc_agent_self_update');

        $result = self::apply([]);
        $args = ['page' => 'wwc-agent'];
        if ($result['ok'] ?? false) {
            $args['notice'] = 'Agent auf '.(string) ($result['version'] ?? 'neu').' aktualisiert.';
        } else {
            $args['error'] = (string) ($result['error'] ?? 'Update fehlgeschlagen');
        }
        wp_safe_redirect(add_query_arg($args, admin_url('options-general.php')));
        exit;
    }

    public static function maybe_auto_update_from_heartbeat(array $response): void
    {
        $update = $response['agent_update'] ?? null;
        if (! is_array($update) || empty($update['available']) || empty($update['package'])) {
            return;
        }
        $version = (string) ($update['version'] ?? '');
        if ($version === '' || version_compare(WWC_AGENT_VERSION, $version, '>=')) {
            return;
        }

        // Avoid update loops (once per version per 6h)
        $lock = 'wwc_agent_auto_update_'.$version;
        if (get_transient($lock)) {
            return;
        }
        set_transient($lock, 1, 6 * HOUR_IN_SECONDS);

        // Run soon via single event so heartbeat request can finish
        if (! wp_next_scheduled('wwc_agent_run_self_update')) {
            wp_schedule_single_event(time() + 5, 'wwc_agent_run_self_update', [[
                'package' => (string) $update['package'],
                'version' => $version,
            ]]);
        }
        wwc_agent_spawn_cron_hint();
    }

    public static function cron_apply($payload = []): void
    {
        if (! is_array($payload)) {
            $payload = [];
        }
        self::apply($payload);
    }

    /** Public wrapper for admin UI (version badge). */
    public static function fetch_latest_public(): ?array
    {
        return self::fetch_latest();
    }

    public static function apply(array $payload): array
    {
        if (empty($payload['package'])) {
            $latest = self::fetch_latest();
            if (! $latest || empty($latest['package'])) {
                return ['ok' => false, 'error' => 'Kein Update-Paket verfügbar'];
            }
            $payload['package'] = $latest['package'];
            $payload['version'] = $latest['version'] ?? null;
        }

        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/misc.php';
        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH.'wp-admin/includes/plugin.php';
        foreach ([
            'class-wp-upgrader-skin.php',
            'class-automatic-upgrader-skin.php',
            'class-plugin-upgrader.php',
        ] as $file) {
            $path = ABSPATH.'wp-admin/includes/'.$file;
            if (file_exists($path)) {
                require_once $path;
            }
        }

        add_filter('filesystem_method', static fn () => 'direct', 100);
        add_filter('http_request_args', static function ($args, $url) {
            // Package host is our own API
            $args['timeout'] = max(30, (int) ($args['timeout'] ?? 5));

            return $args;
        }, 10, 2);

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        $plugin = plugin_basename(WWC_AGENT_FILE);
        $wasActive = is_plugin_active($plugin);

        $result = $upgrader->run([
            'package' => (string) $payload['package'],
            'destination' => WP_PLUGIN_DIR,
            'clear_destination' => true,
            'clear_working' => true,
            'hook_extra' => [
                'type' => 'plugin',
                'action' => 'update',
                'plugin' => $plugin,
            ],
        ]);

        if ($wasActive) {
            activate_plugin($plugin, '', false, true);
        }

        $ok = $result !== false && ! is_wp_error($result);
        if ($ok) {
            delete_site_transient('wwc_agent_latest_release');
        }

        return [
            'ok' => $ok,
            'version' => $payload['version'] ?? null,
            'result' => is_wp_error($result) ? $result->get_error_message() : (bool) $result,
            'error' => $ok ? null : (is_wp_error($result) ? $result->get_error_message() : 'Update fehlgeschlagen'),
        ];
    }

    private static function fetch_latest(): ?array
    {
        // Cache to avoid hitting the API on every update-check / admin page load.
        $cached = get_site_transient('wwc_agent_latest_release');
        if (is_array($cached)) {
            return $cached === [] ? null : $cached;
        }

        $data = self::fetch_latest_uncached();
        set_site_transient('wwc_agent_latest_release', $data ?? [], 15 * MINUTE_IN_SECONDS);

        return $data;
    }

    private static function fetch_latest_uncached(): ?array
    {
        $api = rtrim((string) WWC_Agent_Config::get('api_url'), '/');
        if ($api === '') {
            return null;
        }
        // Stored as https://host/api/agent → releases at https://host/api/agent-releases/latest
        $apiRoot = preg_replace('#/agent$#', '', $api) ?: $api;
        $releasesUrl = rtrim($apiRoot, '/').'/agent-releases/latest';

        $response = wp_remote_get($releasesUrl, [
            'timeout' => 12,
            'headers' => ['Accept' => 'application/json'],
        ]);
        if (is_wp_error($response)) {
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code >= 400 || ! is_array($data) || empty($data['version']) || empty($data['package'])) {
            return null;
        }

        return $data;
    }

    private static function verify_package_optional(array $data): bool
    {
        $publicKey = defined('WWC_AGENT_UPDATE_PUBLIC_KEY') ? (string) WWC_AGENT_UPDATE_PUBLIC_KEY : '';
        if ($publicKey === '' || empty($data['signature'])) {
            return true; // signature optional unless key configured
        }
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }
        $pkg = wp_remote_get((string) $data['package'], ['timeout' => 60]);
        if (is_wp_error($pkg)) {
            return false;
        }
        $bin = (string) wp_remote_retrieve_body($pkg);
        $sig = base64_decode((string) $data['signature'], true);
        $pk = base64_decode($publicKey, true);

        return $sig !== false && $pk !== false && sodium_crypto_sign_verify_detached($sig, $bin, $pk);
    }
}

/**
 * Kick wp-cron without blocking.
 */
function wwc_agent_spawn_cron_hint(): void
{
    $cronUrl = site_url('wp-cron.php?doing_wp_cron='.sprintf('%.22F', microtime(true)));
    wp_remote_post($cronUrl, [
        'timeout' => 0.01,
        'blocking' => false,
        'sslverify' => apply_filters('https_local_ssl_verify', false),
    ]);
}
