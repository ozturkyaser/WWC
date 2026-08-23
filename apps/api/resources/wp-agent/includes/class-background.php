<?php

declare(strict_types=1);

final class WWC_Agent_Background
{
    private const QUEUE_OPTION = 'wwc_agent_bg_queue';
    private const CANCEL_OPTION = 'wwc_agent_cancelled_jobs';

    public static function register(): void
    {
        add_action('wwc_agent_process_queue', [self::class, 'process_queue']);
        add_action('init', [self::class, 'maybe_spawn'], 99);
    }

    public static function is_heavy(string $command): bool
    {
        return in_array($command, [
            'update_plugin',
            'update_theme',
            'update_core',
            'run_scan',
            'self_update',
            'inventory',
            'backup_full',
            'backup_incremental',
            'backup_scan',
            'restore_backup',
            'staging_create',
            'staging_destroy',
            'staging_update_plugin',
            'staging_update_theme',
            'update_batch',
            'staging_promote',
            'staging_grant_admin',
            'list_backups',
            'staging_status',
            'delete_backup',
        ], true);
    }

    public static function mark_cancelled(string $jobId): void
    {
        if ($jobId === '') {
            return;
        }
        $list = get_option(self::CANCEL_OPTION, []);
        if (! is_array($list)) {
            $list = [];
        }
        $list[$jobId] = time();
        // Keep last ~100 cancel markers for an hour
        $cutoff = time() - 3600;
        $list = array_filter($list, static fn ($ts) => (int) $ts >= $cutoff);
        if (count($list) > 100) {
            asort($list);
            $list = array_slice($list, -100, null, true);
        }
        update_option(self::CANCEL_OPTION, $list, false);
    }

    public static function is_cancelled(string $jobId): bool
    {
        if ($jobId === '') {
            return false;
        }
        $list = get_option(self::CANCEL_OPTION, []);

        return is_array($list) && isset($list[$jobId]);
    }

    public static function cancel(string $jobId): array
    {
        self::mark_cancelled($jobId);

        $queue = get_option(self::QUEUE_OPTION, []);
        $removed = 0;
        if (is_array($queue)) {
            $filtered = [];
            foreach ($queue as $item) {
                if (! is_array($item)) {
                    continue;
                }
                if ((string) ($item['job_id'] ?? '') === $jobId) {
                    $removed++;
                    continue;
                }
                $filtered[] = $item;
            }
            update_option(self::QUEUE_OPTION, $filtered, false);
        }

        return [
            'ok' => true,
            'job_id' => $jobId,
            'removed_from_queue' => $removed,
            'running' => WWC_Agent_Job_Progress::currentJobId() === $jobId,
        ];
    }

    public static function enqueue(string $jobId, string $command, array $payload = []): void
    {
        if ($jobId !== '' && self::is_cancelled($jobId)) {
            return;
        }

        $queue = get_option(self::QUEUE_OPTION, []);
        if (! is_array($queue)) {
            $queue = [];
        }

        // Auto incremental backup before live mutations
        if (in_array($command, ['update_plugin', 'update_theme', 'update_core', 'staging_promote'], true)) {
            $queue[] = [
                'job_id' => '',
                'command' => 'backup_incremental',
                'payload' => ['label' => 'auto-before-'.$command],
                'queued_at' => time(),
            ];
        }

        $queue[] = [
            'job_id' => $jobId,
            'command' => $command,
            'payload' => $payload,
            'queued_at' => time(),
        ];
        update_option(self::QUEUE_OPTION, $queue, false);

        if (! wp_next_scheduled('wwc_agent_process_queue')) {
            wp_schedule_single_event(time() + 1, 'wwc_agent_process_queue');
        }
        self::spawn_cron();
    }

    public static function spawn_cron(): void
    {
        $cronUrl = site_url('wp-cron.php?doing_wp_cron='.sprintf('%.22F', microtime(true)));
        wp_remote_post($cronUrl, [
            'timeout' => 0.01,
            'blocking' => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);
    }

    public static function maybe_spawn(): void
    {
        $queue = get_option(self::QUEUE_OPTION, []);
        if (is_array($queue) && count($queue) > 0 && ! wp_next_scheduled('wwc_agent_process_queue')) {
            wp_schedule_single_event(time(), 'wwc_agent_process_queue');
            self::spawn_cron();
        }
    }

    public static function process_queue(): void
    {
        $queue = get_option(self::QUEUE_OPTION, []);
        if (! is_array($queue) || $queue === []) {
            return;
        }

        // Drop cancelled items at the head
        while ($queue !== []) {
            $item = array_shift($queue);
            update_option(self::QUEUE_OPTION, $queue, false);
            if (! is_array($item)) {
                continue;
            }
            $jobId = (string) ($item['job_id'] ?? '');
            if ($jobId !== '' && self::is_cancelled($jobId)) {
                continue;
            }
            self::run_item($item);
            break;
        }

        $queue = get_option(self::QUEUE_OPTION, []);
        if (is_array($queue) && $queue !== []) {
            wp_schedule_single_event(time() + 2, 'wwc_agent_process_queue');
            self::spawn_cron();
        }
    }

    /** Only pass whitelisted backup tuning keys from the job payload. */
    private static function backup_options(array $payload): array
    {
        $options = [];
        if (isset($payload['max_file_mb'])) {
            $options['max_file_mb'] = max(0, (int) $payload['max_file_mb']);
        }
        if (is_array($payload['excludes'] ?? null)) {
            $options['excludes'] = array_values(array_filter(array_map('strval', $payload['excludes'])));
        }
        if (! empty($payload['fresh'])) {
            $options['fresh'] = true;
        }

        // Persist as site default so automatic backups (pre-staging etc.) use it too
        if (! empty($payload['save_settings']) && $options !== []) {
            $saved = get_option('wwc_agent_backup_settings');
            update_option('wwc_agent_backup_settings', array_merge(is_array($saved) ? $saved : [], $options), false);
        }

        return $options;
    }

    private static function run_item(array $item): void
    {
        $command = (string) ($item['command'] ?? '');
        $payload = is_array($item['payload'] ?? null) ? $item['payload'] : [];
        $jobId = (string) ($item['job_id'] ?? '');

        if ($jobId !== '' && self::is_cancelled($jobId)) {
            return;
        }

        @set_time_limit(900);
        @ini_set('memory_limit', '512M');

        WWC_Agent_Job_Progress::begin($jobId);
        register_shutdown_function(static function () use ($jobId, $command, $payload): void {
            $err = error_get_last();
            if (! is_array($err) || ! in_array((int) $err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                return;
            }
            if ($jobId === '') {
                return;
            }
            $message = 'PHP-Abbruch: '.mb_substr((string) ($err['message'] ?? 'fatal'), 0, 220);
            $resumable = in_array($command, ['backup_full', 'backup_incremental'], true) && WWC_Agent_Backup::has_work($jobId);
            $resumable = $resumable || ($command === 'staging_create' && WWC_Agent_Staging::has_work());
            if ($resumable) {
                self::enqueue($jobId, $command, $payload);

                return;
            }
            WWC_Agent_Api_Client::request('POST', '/jobs/'.rawurlencode($jobId).'/result', [
                'status' => 'failed',
                'error' => $message,
            ]);
        });
        try {
            WWC_Agent_Job_Progress::report(3, 'Gestartet: '.$command, true);

            $result = match ($command) {
                'ping' => ['ok' => true],
                'inventory' => WWC_Agent_Collector::inventory(true),
                'update_plugin' => WWC_Agent_Updater::update_plugin((string) ($payload['slug'] ?? $payload['file'] ?? '')),
                'update_theme' => WWC_Agent_Updater::update_theme((string) ($payload['slug'] ?? $payload['stylesheet'] ?? '')),
                'update_core' => WWC_Agent_Updater::update_core(),
                'run_scan' => WWC_Agent_Scanner::run(),
                'self_update' => WWC_Agent_Self_Updater::apply($payload),
                'backup_full' => WWC_Agent_Backup::create_full((string) ($payload['label'] ?? 'manual'), self::backup_options($payload)),
                'backup_incremental' => WWC_Agent_Backup::create_incremental((string) ($payload['label'] ?? 'auto'), self::backup_options($payload)),
                'backup_scan' => WWC_Agent_Backup::scan(self::backup_options($payload)),
                'restore_backup' => WWC_Agent_Backup::restore((string) ($payload['backup_id'] ?? '')),
                'list_backups' => WWC_Agent_Backup::list(),
                'staging_create' => WWC_Agent_Staging::create(($payload['with_backup'] ?? true) !== false),
                'staging_destroy' => WWC_Agent_Staging::destroy(),
                'staging_status' => WWC_Agent_Staging::status(),
                'staging_update_plugin' => WWC_Agent_Staging::run_update('update_plugin', $payload),
                'staging_update_theme' => WWC_Agent_Staging::run_update('update_theme', $payload),
                'update_batch' => WWC_Agent_Updater::update_batch($payload),
                'staging_promote' => WWC_Agent_Staging::promote_to_live(($payload['backup_first'] ?? true) !== false),
                'staging_grant_admin' => WWC_Agent_Staging::grant_admin_access(),
                'delete_backup' => WWC_Agent_Backup::delete((string) ($payload['backup_id'] ?? '')),
                'purge_wwc' => WWC_Agent_Backup::purge_managed(),
                default => ['ok' => false, 'error' => 'Unknown command'],
            };

            if ($jobId !== '' && self::is_cancelled($jobId)) {
                throw new WWC_Agent_Cancelled_Exception('Job cancelled');
            }

            if (! empty($result['continue']) && $jobId !== '') {
                self::enqueue($jobId, $command, $payload);

                return;
            }

            $ok = ! isset($result['ok']) || $result['ok'] !== false;
            $inventory = in_array($command, ['inventory', 'update_plugin', 'update_theme', 'update_core', 'update_batch', 'run_scan', 'staging_promote'], true)
                ? WWC_Agent_Collector::inventory()
                : null;

            if ($jobId !== '') {
                if ($ok) {
                    WWC_Agent_Job_Progress::report(99, 'Abschließen…');
                }
                WWC_Agent_Api_Client::request('POST', '/jobs/'.rawurlencode($jobId).'/result', [
                    'status' => $ok ? 'completed' : 'failed',
                    'result' => $result,
                    'error' => $ok ? null : (string) ($result['error'] ?? 'Command failed'),
                    'inventory' => $inventory,
                    'progress' => $ok ? 100 : null,
                ]);
            }
        } catch (WWC_Agent_Cancelled_Exception $e) {
            if ($jobId !== '') {
                WWC_Agent_Api_Client::request('POST', '/jobs/'.rawurlencode($jobId).'/result', [
                    'status' => 'cancelled',
                    'error' => 'Abgebrochen',
                ]);
            }
        } catch (Throwable $e) {
            if ($jobId !== '') {
                WWC_Agent_Api_Client::request('POST', '/jobs/'.rawurlencode($jobId).'/result', [
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ]);
            }
        } finally {
            WWC_Agent_Job_Progress::end();
        }
    }
}
