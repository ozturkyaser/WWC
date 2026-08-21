<?php

declare(strict_types=1);

final class WWC_Agent_Rest
{
    public static function register(): void
    {
        add_filter('rest_pre_serve_request', [self::class, 'discard_stray_output'], 0, 4);
        add_action('rest_api_init', static function (): void {
            register_rest_route('wwc/v1', '/ping', [
                'methods' => 'GET',
                'callback' => [self::class, 'ping'],
                'permission_callback' => [self::class, 'authorize'],
            ]);
            register_rest_route('wwc/v1', '/inventory', [
                'methods' => 'GET',
                'callback' => [self::class, 'inventory'],
                'permission_callback' => [self::class, 'authorize'],
            ]);
            register_rest_route('wwc/v1', '/execute', [
                'methods' => 'POST',
                'callback' => [self::class, 'execute'],
                'permission_callback' => [self::class, 'authorize'],
            ]);
            register_rest_route('wwc/v1', '/cancel', [
                'methods' => 'POST',
                'callback' => [self::class, 'cancel'],
                'permission_callback' => [self::class, 'authorize'],
            ]);
            register_rest_route('wwc/v1', '/backups/(?P<id>[a-zA-Z0-9\-_]+)/download', [
                'methods' => 'GET',
                'callback' => [self::class, 'download_backup'],
                'permission_callback' => [self::class, 'authorize'],
            ]);
        });
    }

    public static function discard_stray_output($served, $result, $request, $server)
    {
        $route = is_object($request) && method_exists($request, 'get_route') ? (string) $request->get_route() : '';
        if (str_starts_with($route, '/wwc/')) {
            @ini_set('display_errors', '0');
            if (! headers_sent()) {
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('Pragma: no-cache');
            }
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        }

        return $served;
    }

    public static function download_backup(WP_REST_Request $request)
    {
        $id = (string) $request->get_param('id');
        if ($id === 'latest' || $id === 'latest-full') {
            $latest = WWC_Agent_Backup::latest_full();
            if (! $latest) {
                return new WP_Error('wwc_backup', 'No full backup available', ['status' => 404]);
            }
            $id = (string) $latest['id'];
        }

        $export = WWC_Agent_Backup::ensure_export($id);
        if (! ($export['ok'] ?? false)) {
            return new WP_Error('wwc_backup', (string) ($export['error'] ?? 'Export failed'), ['status' => 404]);
        }

        $path = (string) $export['path'];
        $filename = (string) ($export['filename'] ?? basename($path));
        if (! is_readable($path)) {
            return new WP_Error('wwc_backup', 'Export file missing', ['status' => 404]);
        }

        // Stream binary response (bypass WP JSON envelope)
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Content-Length: '.(string) filesize($path));
        header('X-WWC-Backup-Id: '.$id);
        readfile($path);
        exit;
    }

    public static function authorize(WP_REST_Request $request)
    {
        if (defined('WWC_AGENT_DISABLED') && WWC_AGENT_DISABLED) {
            return new WP_Error('wwc_disabled', 'Agent disabled', ['status' => 503]);
        }
        if (! WWC_Agent_Config::is_paired()) {
            return new WP_Error('wwc_unpaired', 'Not paired', ['status' => 401]);
        }

        $timestamp = (string) $request->get_header('x-wwc-timestamp');
        $nonce = (string) $request->get_header('x-wwc-nonce');
        $signature = (string) $request->get_header('x-wwc-signature');
        if ($timestamp === '' || $nonce === '' || $signature === '') {
            return new WP_Error('wwc_auth', 'Missing HMAC headers', ['status' => 401]);
        }
        if (! WWC_Agent_Hmac::fresh($timestamp)) {
            return new WP_Error('wwc_auth', 'Stale timestamp', ['status' => 401]);
        }

        $used = get_transient('wwc_nonce_'.$nonce);
        if ($used) {
            return new WP_Error('wwc_auth', 'Nonce replay', ['status' => 401]);
        }
        set_transient('wwc_nonce_'.$nonce, 1, 300);

        $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/wp-json/wwc/v1/'.trim((string) $request->get_route(), '/');
        $body = $request->get_body();
        $cfg = WWC_Agent_Config::all();
        $secrets = array_filter([$cfg['hmac_secret'], $cfg['hmac_secret_previous']]);
        foreach ($secrets as $secret) {
            if (WWC_Agent_Hmac::verify($request->get_method(), $uriPath, $timestamp, $nonce, $body, $signature, $secret)) {
                return true;
            }
        }

        return new WP_Error('wwc_auth', 'Invalid signature', ['status' => 401]);
    }

    public static function ping(): WP_REST_Response
    {
        return new WP_REST_Response([
            'ok' => true,
            'agent_version' => WWC_AGENT_VERSION,
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'time' => gmdate('c'),
        ]);
    }

    public static function inventory(): WP_REST_Response
    {
        return new WP_REST_Response(WWC_Agent_Collector::inventory(false));
    }

    public static function cancel(WP_REST_Request $request): WP_REST_Response
    {
        $jobId = (string) $request->get_param('job_id');
        if ($jobId === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'job_id required'], 400);
        }

        return new WP_REST_Response(WWC_Agent_Background::cancel($jobId));
    }

    public static function execute(WP_REST_Request $request): WP_REST_Response
    {
        $command = (string) $request->get_param('command');
        $payload = $request->get_param('payload');
        $jobId = (string) $request->get_param('job_id');
        if (! is_array($payload)) {
            $payload = [];
        }

        if ($jobId !== '' && WWC_Agent_Background::is_cancelled($jobId)) {
            return new WP_REST_Response([
                'ok' => true,
                'cancelled' => true,
                'job_id' => $jobId,
                'message' => 'Job already cancelled',
            ]);
        }

        $allowed = [
            'ping', 'inventory', 'update_plugin', 'update_theme', 'update_core', 'run_scan', 'self_update',
            'backup_full', 'backup_incremental', 'backup_scan', 'restore_backup', 'list_backups',
            'delete_backup', 'purge_wwc',
            'staging_create', 'staging_destroy', 'staging_status', 'staging_grant_admin',
            'staging_update_plugin', 'staging_update_theme', 'update_batch', 'staging_promote',
            'security_harden', 'security_status',
        ];
        if (! in_array($command, $allowed, true)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Command not allowed'], 400);
        }

        // Heavy work runs in background so the site/API request is not blocked
        if (WWC_Agent_Background::is_heavy($command)) {
            WWC_Agent_Background::enqueue($jobId, $command, $payload);

            return new WP_REST_Response([
                'ok' => true,
                'accepted' => true,
                'queued' => true,
                'job_id' => $jobId,
                'command' => $command,
                'message' => 'Command queued for background execution',
            ], 202);
        }

        try {
            $result = match ($command) {
                'ping' => ['ok' => true],
                'security_harden' => WWC_Agent_Hardening::apply($payload),
                'security_status' => ['ok' => true, 'status' => WWC_Agent_Hardening::status()],
                'purge_wwc' => WWC_Agent_Backup::purge_managed(),
                'delete_backup' => WWC_Agent_Backup::delete((string) ($payload['backup_id'] ?? '')),
                'list_backups' => WWC_Agent_Backup::list(),
                default => ['ok' => true],
            };

            if ($jobId !== '') {
                WWC_Agent_Api_Client::request('POST', '/jobs/'.rawurlencode($jobId).'/result', [
                    'status' => ! isset($result['ok']) || $result['ok'] !== false ? 'completed' : 'failed',
                    'result' => $result,
                    'error' => ($result['ok'] ?? true) === false ? (string) ($result['error'] ?? 'failed') : null,
                ]);
            }

            return new WP_REST_Response([
                'ok' => true,
                'job_id' => $jobId,
                'result' => $result,
            ]);
        } catch (Throwable $e) {
            if ($jobId !== '') {
                WWC_Agent_Api_Client::request('POST', '/jobs/'.rawurlencode($jobId).'/result', [
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ]);
            }

            return new WP_REST_Response(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
