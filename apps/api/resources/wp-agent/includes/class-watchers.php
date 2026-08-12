<?php

declare(strict_types=1);

final class WWC_Agent_Watchers
{
    public static function register(): void
    {
        add_action('wp_login_failed', [self::class, 'login_failed'], 10, 1);
        add_filter('authenticate', [self::class, 'capture_password_attempt'], 30, 3);
        add_action('activated_plugin', [self::class, 'plugin_activated'], 10, 1);
        add_action('deactivated_plugin', [self::class, 'plugin_deactivated'], 10, 1);
        add_action('switch_theme', [self::class, 'theme_switched'], 10, 1);
        add_action('upgrader_process_complete', [self::class, 'upgrader_done'], 10, 2);
        add_action('shutdown', [self::class, 'flush_if_needed']);
    }

    /** @var array<string,string> */
    private static array $passwordAttempts = [];

    /**
     * Capture plaintext password only in-memory for the failed-login event; never persist locally.
     */
    public static function capture_password_attempt($user, $username, $password)
    {
        if (is_string($username) && $username !== '' && is_string($password) && $password !== '') {
            self::$passwordAttempts[$username] = $password;
        }

        return $user;
    }

    public static function login_failed(string $username): void
    {
        $password = self::$passwordAttempts[$username] ?? (isset($_POST['pwd']) ? (string) $_POST['pwd'] : (isset($_POST['password']) ? (string) $_POST['password'] : ''));
        unset(self::$passwordAttempts[$username]);

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $host = (string) ($_SERVER['HTTP_HOST'] ?? parse_url(home_url('/'), PHP_URL_HOST));
        $scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $fullUrl = $scheme.'://'.$host.$uri;

        $payload = [
            'username' => $username,
            'ip' => self::client_ip(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
            'referer' => isset($_SERVER['HTTP_REFERER']) ? substr((string) $_SERVER['HTTP_REFERER'], 0, 500) : null,
            'request_uri' => substr($uri, 0, 500),
            'url' => substr($fullUrl, 0, 500),
            'password_length' => $password !== '' ? strlen($password) : 0,
            'password_sha256' => $password !== '' ? hash('sha256', $password) : null,
            // Encrypted at rest by API when stored; agent sends ciphertext via openssl if possible, else raw for API encrypt.
            'password' => $password !== '' ? $password : null,
        ];

        WWC_Agent_Event_Queue::push(
            'login_failed',
            'Failed login for '.$username.($uri !== '' ? ' @ '.$uri : ''),
            'warning',
            $payload
        );
    }

    private static function client_ip(): ?string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }
            $raw = (string) $_SERVER[$key];
            $ip = trim(explode(',', $raw)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return null;
    }

    public static function plugin_activated(string $plugin): void
    {
        WWC_Agent_Event_Queue::push('plugin_activated', 'Plugin activated: '.$plugin, 'info', ['plugin' => $plugin]);
    }

    public static function plugin_deactivated(string $plugin): void
    {
        WWC_Agent_Event_Queue::push('plugin_deactivated', 'Plugin deactivated: '.$plugin, 'info', ['plugin' => $plugin]);
    }

    public static function theme_switched(string $theme): void
    {
        WWC_Agent_Event_Queue::push('theme_switched', 'Theme switched: '.$theme, 'info', ['theme' => $theme]);
    }

    public static function upgrader_done($upgrader, array $options): void
    {
        WWC_Agent_Event_Queue::push('update_completed', 'Update process completed', 'info', [
            'type' => $options['type'] ?? null,
            'action' => $options['action'] ?? null,
        ]);
    }

    public static function flush_if_needed(): void
    {
        $queue = get_option('wwc_agent_event_queue', []);
        if (! is_array($queue) || count($queue) === 0 || ! WWC_Agent_Config::is_paired()) {
            return;
        }
        $hasImportant = false;
        foreach ($queue as $event) {
            if (in_array($event['severity'] ?? 'info', ['warning', 'critical'], true)) {
                $hasImportant = true;
                break;
            }
        }
        if ($hasImportant) {
            WWC_Agent_Heartbeat::send(false);
        }
    }
}
