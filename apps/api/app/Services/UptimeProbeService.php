<?php

namespace App\Services;

use App\Models\Site;
use Carbon\Carbon;

class UptimeProbeService
{
    /** @var array<string, string> PHP-Version => EOL-Datum */
    public const PHP_EOL = [
        '8.0' => '2023-11-26',
        '8.1' => '2025-12-31',
        '8.2' => '2026-12-31',
        '8.3' => '2027-12-31',
        '8.4' => '2028-12-31',
    ];

    public function probe(Site $site): array
    {
        $url = rtrim((string) $site->url, '/').'/';
        $started = microtime(true);
        $httpStatus = null;
        $error = null;
        $sslExpires = null;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_NOBODY => false,
            CURLOPT_USERAGENT => 'WWC-Uptime/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CERTINFO => true,
        ]);
        curl_exec($ch);
        $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: null;
        $curlErr = curl_error($ch);
        if ($curlErr !== '') {
            $error = $curlErr;
        }
        $cert = curl_getinfo($ch, CURLINFO_CERTINFO);
        curl_close($ch);

        if (is_array($cert) && isset($cert[0]['Expire date'])) {
            try {
                $sslExpires = Carbon::parse($cert[0]['Expire date'])->toIso8601String();
            } catch (\Throwable) {
                $sslExpires = null;
            }
        }

        if (! $sslExpires && str_starts_with($url, 'https://')) {
            $sslExpires = $this->sslExpiryFromHost(parse_url($url, PHP_URL_HOST) ?: '');
        }

        $ms = (int) round((microtime(true) - $started) * 1000);
        $ok = $httpStatus !== null && $httpStatus >= 200 && $httpStatus < 500 && $httpStatus !== 0;

        $php = $this->phpStatus($site->php_version);
        $wp = $this->wpStatus($site->wp_version);

        $monitor = [
            'http_ok' => $ok,
            'http_status' => $httpStatus,
            'response_ms' => $ms,
            'error' => $error,
            'ssl_expires_at' => $sslExpires,
            'ssl_days' => $sslExpires ? (int) now()->diffInDays(Carbon::parse($sslExpires), false) : null,
            'php' => $php,
            'wp' => $wp,
            'checked_at' => now()->toIso8601String(),
        ];

        $site->update(['monitor' => $monitor]);

        return $monitor;
    }

    public function phpStatus(?string $version): array
    {
        $minor = $this->minor($version);
        $eol = $minor ? (self::PHP_EOL[$minor] ?? null) : null;
        $days = $eol ? (int) now()->diffInDays(Carbon::parse($eol), false) : null;

        return [
            'version' => $version,
            'eol_at' => $eol,
            'days' => $days,
            'status' => $days === null ? 'unknown' : ($days < 0 ? 'eol' : ($days < 90 ? 'soon' : 'ok')),
        ];
    }

    public function wpStatus(?string $version): array
    {
        // Ab 2026 gelten Versionen unter 6.6 als veraltet, unter 6.4 als kritisch.
        $ok = $version && version_compare($version, '6.6', '>=');
        $warn = $version && version_compare($version, '6.4', '>=') && ! $ok;

        return [
            'version' => $version,
            'status' => ! $version ? 'unknown' : ($ok ? 'ok' : ($warn ? 'soon' : 'eol')),
        ];
    }

    private function minor(?string $version): ?string
    {
        if (! $version || ! preg_match('/^(\d+\.\d+)/', $version, $m)) {
            return null;
        }

        return $m[1];
    }

    private function sslExpiryFromHost(string $host): ?string
    {
        if ($host === '') {
            return null;
        }
        $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false]]);
        $fp = @stream_socket_client('ssl://'.$host.':443', $errno, $errstr, 6, STREAM_CLIENT_CONNECT, $ctx);
        if (! $fp) {
            return null;
        }
        $params = stream_context_get_params($fp);
        fclose($fp);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if (! $cert) {
            return null;
        }
        $info = openssl_x509_parse($cert);
        if (! is_array($info) || empty($info['validTo_time_t'])) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $info['validTo_time_t'])->toIso8601String();
    }
}
