<?php

declare(strict_types=1);

final class WWC_Agent_Config
{
    private const OPTION = 'wwc_agent_settings';

    public static function all(): array
    {
        $defaults = [
            'site_id' => '',
            'api_url' => '',
            'hmac_secret' => '',
            'hmac_secret_previous' => '',
            'key_id' => '',
            'paired_at' => '',
            'last_sync_at' => '',
            'last_error' => '',
        ];

        return array_merge($defaults, get_option(self::OPTION, []));
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();

        return $all[$key] ?? $default;
    }

    public static function update(array $values): void
    {
        $current = self::all();
        update_option(self::OPTION, array_merge($current, $values), false);
    }

    public static function clear(): void
    {
        delete_option(self::OPTION);
    }

    public static function is_paired(): bool
    {
        $cfg = self::all();

        return $cfg['site_id'] !== '' && $cfg['hmac_secret'] !== '' && $cfg['api_url'] !== '';
    }
}
