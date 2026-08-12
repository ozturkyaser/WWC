<?php

declare(strict_types=1);

final class WWC_Agent_Api_Client
{
    public static function normalize_api_base(string $apiBase): string
    {
        $apiBase = trim($apiBase);
        $apiBase = rtrim($apiBase, '/');
        // Allow pasting full agent endpoint by mistake
        $apiBase = preg_replace('#/api/agent$#', '', $apiBase) ?: $apiBase;
        $apiBase = preg_replace('#/api$#', '', $apiBase) ?: $apiBase;

        return rtrim($apiBase, '/');
    }

    public static function request(string $method, string $path, array $body = [], int $timeout = 45): array|WP_Error
    {
        if (! WWC_Agent_Config::is_paired()) {
            return new WP_Error('wwc_unpaired', 'Agent is not paired');
        }

        $cfg = WWC_Agent_Config::all();
        $url = rtrim($cfg['api_url'], '/').$path;
        $json = $body === [] ? '' : (string) wp_json_encode($body);
        $timestamp = (string) time();
        $nonce = wp_generate_password(32, false, false);
        $signPath = '/api/agent'.$path;
        $signature = WWC_Agent_Hmac::sign($method, $signPath, $timestamp, $nonce, $json, $cfg['hmac_secret']);

        $args = [
            'method' => strtoupper($method),
            'timeout' => $timeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-WWC-Site-Id' => $cfg['site_id'],
                'X-WWC-Timestamp' => $timestamp,
                'X-WWC-Nonce' => $nonce,
                'X-WWC-Key-Id' => $cfg['key_id'] ?: 'primary',
                'X-WWC-Signature' => $signature,
            ],
            'body' => $json,
        ];

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);
        if ($code >= 400) {
            $msg = is_array($decoded) && isset($decoded['message'])
                ? (string) $decoded['message']
                : 'HTTP '.$code;

            return new WP_Error('wwc_http', $msg, ['status' => $code, 'body' => $decoded ?: $raw]);
        }

        return is_array($decoded) ? $decoded : [];
    }

    public static function pair(string $apiBase, string $code): array|WP_Error
    {
        $apiBase = self::normalize_api_base($apiBase);
        if ($apiBase === '' || ! preg_match('#^https?://#i', $apiBase)) {
            return new WP_Error('wwc_pair_failed', 'API-URL ungültig. Beispiel: http://host.docker.internal:8081');
        }

        $url = $apiBase.'/api/agent/pair';
        $code = strtoupper(trim(str_replace(' ', '', $code)));
        $body = [
            'code' => $code,
            'site_url' => home_url('/'),
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'agent_version' => WWC_AGENT_VERSION,
        ];

        $response = wp_remote_post($url, [
            'timeout' => 45,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => (string) wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return new WP_Error(
                'wwc_pair_failed',
                'API nicht erreichbar: '.$response->get_error_message().' — Prüfe API-URL (Docker: host.docker.internal statt localhost).'
            );
        }

        $codeHttp = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        if ($codeHttp >= 400 || ! is_array($data) || empty($data['hmac_secret']) || empty($data['site_id']) || empty($data['api_url'])) {
            $msg = is_array($data) && ! empty($data['message'])
                ? (string) $data['message']
                : 'Pairing fehlgeschlagen (HTTP '.$codeHttp.')';

            return new WP_Error('wwc_pair_failed', $msg, $data);
        }

        WWC_Agent_Config::update([
            'site_id' => (string) $data['site_id'],
            'api_url' => rtrim((string) $data['api_url'], '/'),
            'hmac_secret' => (string) $data['hmac_secret'],
            'key_id' => (string) ($data['key_id'] ?? 'primary'),
            'paired_at' => gmdate('c'),
            'last_error' => '',
        ]);

        return $data;
    }
}
