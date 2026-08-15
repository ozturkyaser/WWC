<?php

declare(strict_types=1);

final class WWC_Agent_Watchers
{
    public static function register(): void
    {
        add_action('wp_login_failed', [self::class, 'login_failed'], 10, 1);
        add_action('shutdown', [self::class, 'flush_if_needed']);
    }

    public static function login_failed(string $username): void
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        WWC_Agent_Activity::log(
            'login_failed',
            'Fehlgeschlagene Anmeldung: '.$username,
            'warning',
            [
                'username' => $username,
                'request_uri' => substr($uri, 0, 500),
            ]
        );
    }

    public static function flush_if_needed(): void
    {
        $queue = get_option('wwc_agent_event_queue', []);
        if (! is_array($queue) || $queue === [] || ! WWC_Agent_Config::is_paired()) {
            return;
        }
        foreach ($queue as $event) {
            if (in_array($event['severity'] ?? 'info', ['warning', 'critical'], true)) {
                WWC_Agent_Heartbeat::send(false);

                return;
            }
        }
    }
}
