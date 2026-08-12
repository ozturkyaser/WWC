<?php

declare(strict_types=1);

final class WWC_Agent_Heartbeat
{
    public static function register(): void
    {
        add_action('wwc_agent_heartbeat', [self::class, 'cron']);
    }

    public static function cron(): void
    {
        self::send(true);
    }

    public static function send(bool $includeInventory = true): array|WP_Error
    {
        if (! WWC_Agent_Config::is_paired()) {
            return new WP_Error('wwc_unpaired', 'Agent is not paired');
        }

        $events = WWC_Agent_Event_Queue::drain();
        $payload = [
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'agent_version' => WWC_AGENT_VERSION,
            'health' => array_merge(WWC_Agent_Collector::health(), [
                'backups' => WWC_Agent_Backup::list()['backups'] ?? [],
                'staging' => WWC_Agent_Staging::status(),
            ]),
            'events' => $events,
        ];
        if ($includeInventory) {
            $payload['inventory'] = WWC_Agent_Collector::inventory();
        }

        $result = WWC_Agent_Api_Client::request('POST', '/heartbeat', $payload);
        if (is_wp_error($result)) {
            WWC_Agent_Config::update(['last_error' => $result->get_error_message()]);

            return $result;
        }

        WWC_Agent_Config::update([
            'last_error' => '',
            'last_sync_at' => gmdate('c'),
        ]);

        if (is_array($result)) {
            WWC_Agent_Self_Updater::maybe_auto_update_from_heartbeat($result);
        }

        return $result;
    }
}
