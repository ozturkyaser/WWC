<?php

declare(strict_types=1);

final class WWC_Agent_Collector
{
    public static function inventory(bool $refreshUpdates = false): array
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }
        require_once ABSPATH.'wp-admin/includes/update.php';
        if ($refreshUpdates) {
            wp_update_plugins();
            wp_update_themes();
        }

        $plugin_updates = get_site_transient('update_plugins');
        $theme_updates = get_site_transient('update_themes');
        $core_updates = get_site_transient('update_core');

        $plugins = [];
        foreach (get_plugins() as $file => $data) {
            $slug = dirname($file);
            if ($slug === '.') {
                $slug = basename($file, '.php');
            }
            $update = $plugin_updates->response[$file] ?? null;
            $plugins[] = [
                'file' => $file,
                'slug' => $slug,
                'name' => $data['Name'] ?? $file,
                'version' => $data['Version'] ?? '',
                'active' => is_plugin_active($file),
                'update_available' => $update->new_version ?? null,
            ];
        }

        $themes = [];
        foreach (wp_get_themes() as $stylesheet => $theme) {
            $update = $theme_updates->response[$stylesheet] ?? null;
            $themes[] = [
                'stylesheet' => $stylesheet,
                'slug' => $stylesheet,
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'active' => get_stylesheet() === $stylesheet,
                'update_available' => is_array($update) ? ($update['new_version'] ?? null) : null,
            ];
        }

        $core_latest = null;
        if (is_object($core_updates) && ! empty($core_updates->updates[0]->version)) {
            $core_latest = $core_updates->updates[0]->version;
        }

        return [
            'core' => [
                'version' => get_bloginfo('version'),
                'update_available' => $core_latest && version_compare(get_bloginfo('version'), $core_latest, '<') ? $core_latest : null,
            ],
            'php' => [
                'version' => PHP_VERSION,
            ],
            'plugins' => $plugins,
            'themes' => $themes,
            'site_url' => home_url('/'),
            'agent_version' => WWC_AGENT_VERSION,
        ];
    }

    public static function health(): array
    {
        $disk_free = @disk_free_space(ABSPATH);
        $disk_total = @disk_total_space(ABSPATH);

        return [
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'debug' => defined('WP_DEBUG') && WP_DEBUG,
            'cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'disk_free_bytes' => $disk_free !== false ? (int) $disk_free : null,
            'disk_total_bytes' => $disk_total !== false ? (int) $disk_total : null,
            'ssl' => is_ssl(),
            'abspath_writable' => is_writable(ABSPATH),
        ];
    }
}
