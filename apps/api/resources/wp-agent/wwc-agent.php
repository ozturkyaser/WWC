<?php
/**
 * Plugin Name: WWC Agent
 * Plugin URI: https://wwc.local
 * Description: Sicheres Remote-Management-Agent-Plugin für die WWC Wartungsplattform.
 * Version: 0.6.24
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: WWC
 * License: GPL-2.0-or-later
 * Text Domain: wwc-agent
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

if (defined('WWC_AGENT_DISABLED') && WWC_AGENT_DISABLED) {
    return;
}

define('WWC_AGENT_VERSION', '0.6.24');
define('WWC_AGENT_FILE', __FILE__);
define('WWC_AGENT_DIR', plugin_dir_path(__FILE__));
define('WWC_AGENT_URL', plugin_dir_url(__FILE__));
if (! defined('WWC_AGENT_DEFAULT_API_URL')) {
    define('WWC_AGENT_DEFAULT_API_URL', 'https://wwc.kiservicehub.de');
}

require_once WWC_AGENT_DIR.'includes/class-hmac.php';
require_once WWC_AGENT_DIR.'includes/class-config.php';
require_once WWC_AGENT_DIR.'includes/class-event-queue.php';
require_once WWC_AGENT_DIR.'includes/class-collector.php';
require_once WWC_AGENT_DIR.'includes/class-updater.php';
require_once WWC_AGENT_DIR.'includes/class-self-updater.php';
require_once WWC_AGENT_DIR.'includes/class-scanner.php';
require_once WWC_AGENT_DIR.'includes/class-backup.php';
require_once WWC_AGENT_DIR.'includes/class-backup-uploader.php';
require_once WWC_AGENT_DIR.'includes/class-staging.php';
require_once WWC_AGENT_DIR.'includes/class-hardening.php';
require_once WWC_AGENT_DIR.'includes/class-site-intel.php';
require_once WWC_AGENT_DIR.'includes/class-activity.php';
require_once WWC_AGENT_DIR.'includes/class-guard.php';
require_once WWC_AGENT_DIR.'includes/class-api-client.php';
require_once WWC_AGENT_DIR.'includes/class-job-progress.php';
require_once WWC_AGENT_DIR.'includes/class-background.php';
require_once WWC_AGENT_DIR.'includes/class-rest.php';
require_once WWC_AGENT_DIR.'includes/class-watchers.php';
require_once WWC_AGENT_DIR.'includes/class-heartbeat.php';
require_once WWC_AGENT_DIR.'admin/class-admin.php';

final class WWC_Agent
{
    public static function init(): void
    {
        self::ensure_quiet_mu_plugin();
        WWC_Agent_Rest::register();
        WWC_Agent_Hardening::register();
        WWC_Agent_Guard::register();
        WWC_Agent_Activity::register();
        WWC_Agent_Watchers::register();
        WWC_Agent_Heartbeat::register();
        WWC_Agent_Background::register();
        WWC_Agent_Self_Updater::register();
        if (is_admin()) {
            WWC_Agent_Admin::register();
        }
    }

    public static function ensure_quiet_mu_plugin(): void
    {
        $src = WWC_AGENT_DIR.'mu/wwc-agent-quiet.php';
        if (! is_readable($src)) {
            return;
        }
        $dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR.'/mu-plugins';
        if (! is_dir($dir) && ! wp_mkdir_p($dir)) {
            return;
        }
        $dest = $dir.'/wwc-agent-quiet.php';
        $need = ! is_file($dest) || (string) file_get_contents($dest) !== (string) file_get_contents($src);
        if ($need) {
            @copy($src, $dest);
        }
    }
}

add_action('plugins_loaded', ['WWC_Agent', 'init']);
add_action('wwc_agent_run_self_update', ['WWC_Agent_Self_Updater', 'cron_apply'], 10, 1);

register_activation_hook(__FILE__, static function (): void {
    WWC_Agent::ensure_quiet_mu_plugin();
    if (! wp_next_scheduled('wwc_agent_heartbeat')) {
        wp_schedule_event(time() + 60, 'wwc_every_minute', 'wwc_agent_heartbeat');
    }
});

register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook('wwc_agent_heartbeat');
});

add_filter('cron_schedules', static function (array $schedules): array {
    $schedules['wwc_every_minute'] = [
        'interval' => 60,
        'display' => 'Every Minute (WWC)',
    ];

    return $schedules;
});
