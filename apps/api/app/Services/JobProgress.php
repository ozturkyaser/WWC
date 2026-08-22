<?php

namespace App\Services;

use App\Models\AgentJob;

class JobProgress
{
    /** @var array<string, int> expected seconds for time-based estimate */
    private const EXPECTED_SECONDS = [
        'backup_full' => 240,
        'backup_incremental' => 90,
        'backup_scan' => 45,
        'restore_backup' => 300,
        'staging_create' => 360,
        'staging_destroy' => 60,
        'staging_promote' => 240,
        'staging_update_plugin' => 120,
        'staging_update_theme' => 120,
        'update_batch' => 300,
        'update_plugin' => 90,
        'update_theme' => 90,
        'update_core' => 180,
        'run_scan' => 60,
        'inventory' => 30,
        'purge_wwc' => 90,
        'delete_backup' => 30,
    ];

    public static function forJob(AgentJob $job): array
    {
        $labelMap = [
        'backup_full' => 'Full-Backup',
        'backup_incremental' => 'Inkrementelles Backup',
        'backup_scan' => 'Backup-Analyse',
            'staging_create' => 'Development-Umgebung',
            'staging_destroy' => 'Staging löschen',
            'staging_promote' => 'Promote to Live',
            'restore_backup' => 'Restore',
            'update_plugin' => 'Plugin-Update',
            'update_theme' => 'Theme-Update',
            'update_core' => 'Core-Update',
            'update_batch' => 'Multi-Update',
            'staging_update_plugin' => 'Dry-Run Plugin',
            'staging_update_theme' => 'Dry-Run Theme',
            'run_scan' => 'Security-Scan',
            'inventory' => 'Inventory',
            'purge_wwc' => 'Aufräumen',
            'delete_backup' => 'Backup löschen',
            'security_harden' => 'Sicherheits-Härtung',
            'security_status' => 'Härtungs-Status',
        ];

        $title = $labelMap[$job->command] ?? $job->command;
        if ($job->command === 'update_batch') {
            $mode = is_array($job->payload) ? ($job->payload['mode'] ?? 'live') : 'live';
            $count = is_array($job->payload['items'] ?? null) ? count($job->payload['items']) : 0;
            $title = ($mode === 'staging' ? 'Dry-Run' : 'Live').'-Multi-Update'.($count ? " ({$count})" : '');
        } elseif (in_array($job->command, ['staging_update_plugin', 'staging_update_theme', 'update_plugin', 'update_theme'], true)) {
            $slug = is_array($job->payload) ? (string) ($job->payload['slug'] ?? '') : '';
            if ($slug !== '') {
                $title .= ': '.$slug;
            }
        }

        $isDryRun = in_array($job->command, ['staging_update_plugin', 'staging_update_theme'], true)
            || ($job->command === 'update_batch' && is_array($job->payload) && ($job->payload['mode'] ?? '') === 'staging');

        $itemResults = [];
        if (is_array($job->result) && is_array($job->result['results'] ?? null)) {
            $itemResults = $job->result['results'];
        }

        if ($job->status === 'completed') {
            $label = $job->progress_label ?: ($isDryRun ? 'OK' : 'Fertig');
            if (in_array($label, ['Fertig', 'Abschließen…', ''], true) && $isDryRun) {
                $label = 'OK';
            }

            return [
                'percent' => 100,
                'label' => $label,
                'title' => $title,
                'status' => 'completed',
                'outcome' => 'ok',
                'source' => 'done',
                'error' => null,
                'items' => $itemResults,
                'log' => array_slice(is_array($job->progress_log) ? $job->progress_log : [], -40),
            ];
        }

        if ($job->status === 'failed') {
            $error = $job->error ?: (is_array($job->result) ? (string) ($job->result['error'] ?? '') : '') ?: 'Fehlgeschlagen';

            return [
                'percent' => (int) ($job->progress ?? 0),
                'label' => $error,
                'title' => $title,
                'status' => 'failed',
                'outcome' => 'error',
                'source' => 'failed',
                'error' => $error,
                'items' => $itemResults,
                'log' => array_slice(is_array($job->progress_log) ? $job->progress_log : [], -40),
            ];
        }

        if ($job->status === 'cancelled') {
            return [
                'percent' => (int) ($job->progress ?? 0),
                'label' => $job->progress_label ?: 'Abgebrochen',
                'title' => $title,
                'status' => 'cancelled',
                'source' => 'cancelled',
                'log' => array_slice(is_array($job->progress_log) ? $job->progress_log : [], -40),
            ];
        }

        if ($job->progress !== null) {
            return [
                'percent' => min(99, max(0, (int) $job->progress)),
                'label' => $job->progress_label ?: 'Läuft…',
                'title' => $title,
                'status' => $job->status,
                'source' => 'agent',
                'log' => array_slice(is_array($job->progress_log) ? $job->progress_log : [], -40),
            ];
        }

        $elapsed = $job->started_at ? max(0, $job->started_at->diffInSeconds(now())) : 0;
        $expected = self::EXPECTED_SECONDS[$job->command] ?? 75;
        $percent = (int) min(92, max($job->status === 'pending' ? 2 : 8, round(($elapsed / $expected) * 100)));

        return [
            'percent' => $percent,
            'label' => $job->status === 'pending' ? 'In Warteschlange…' : 'Läuft…',
            'title' => $title,
            'status' => $job->status,
            'source' => 'estimate',
            'log' => array_slice(is_array($job->progress_log) ? $job->progress_log : [], -40),
        ];
    }

    public static function enrich(AgentJob $job): array
    {
        $base = $job->toArray();
        $base['progress_ui'] = self::forJob($job);

        return $base;
    }
}
