<?php

declare(strict_types=1);

final class WWC_Agent_Scanner
{
    public static function run(): array
    {
        $issues = [];
        if (version_compare(PHP_VERSION, '8.1.0', '<')) {
            $issues[] = [
                'type' => 'php_outdated',
                'severity' => 'high',
                'title' => 'PHP '.PHP_VERSION.' is outdated',
            ];
        }
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY) {
            $issues[] = [
                'type' => 'debug_display',
                'severity' => 'medium',
                'title' => 'WP_DEBUG_DISPLAY is enabled on a live site',
            ];
        }
        if (is_writable(ABSPATH.'wp-config.php')) {
            $issues[] = [
                'type' => 'wp_config_writable',
                'severity' => 'medium',
                'title' => 'wp-config.php is writable by the web user',
            ];
        }

        $users = get_users(['role' => 'administrator', 'number' => 50]);
        foreach ($users as $user) {
            if (strtolower($user->user_login) === 'admin') {
                $issues[] = [
                    'type' => 'default_admin_user',
                    'severity' => 'medium',
                    'title' => 'Administrator account named "admin" found',
                ];
            }
        }

        return [
            'ok' => true,
            'issues' => $issues,
            'inventory' => WWC_Agent_Collector::inventory(),
            'health' => WWC_Agent_Collector::health(),
        ];
    }
}
