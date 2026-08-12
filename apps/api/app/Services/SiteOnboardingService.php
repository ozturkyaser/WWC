<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\Log;


class SiteOnboardingService
{
    public function __construct(private AgentDispatcher $dispatcher) {}

    public function markAwaitingPair(Site $site): void
    {
        $site->forceFill([
            'onboarding_status' => 'awaiting_pair',
            'onboarding_meta' => [
                'started_at' => now()->toIso8601String(),
                'steps' => [
                    'pair' => 'pending',
                    'backup' => 'pending',
                    'staging' => 'pending',
                ],
            ],
        ])->save();
    }

    public function startAfterPairing(Site $site): void
    {
        $site->refresh();
        if (! $site->getHmacSecret()) {
            return;
        }

        $meta = $site->onboarding_meta ?? [];
        $meta['steps']['pair'] = 'done';
        $meta['paired_at'] = now()->toIso8601String();

        $site->forceFill([
            'onboarding_status' => 'awaiting_backup',
            'onboarding_meta' => $meta,
        ])->save();

        try {
            $job = $this->dispatcher->dispatch($site, 'backup_full', [
                'label' => 'onboarding-full',
            ]);
            $meta['backup_job_id'] = $job->id;
            $site->forceFill(['onboarding_meta' => $meta])->save();
        } catch (\Throwable $e) {
            Log::warning('Onboarding backup dispatch failed', ['site' => $site->id, 'error' => $e->getMessage()]);
            $this->fail($site, 'Backup konnte nicht gestartet werden: '.$e->getMessage());
        }
    }

    public function handleJobResult(Site $site, string $command, string $status, ?array $result = null): void
    {
        $onboarding = $site->onboarding_status;
        if (! in_array($onboarding, ['awaiting_backup', 'awaiting_staging'], true)) {
            return;
        }

        $meta = $site->onboarding_meta ?? [];

        if ($command === 'backup_full' && $onboarding === 'awaiting_backup') {
            if ($status === 'cancelled') {
                $this->fail($site, 'Onboarding abgebrochen (Backup)');

                return;
            }
            if ($status !== 'completed' || (($result['ok'] ?? true) === false)) {
                $this->fail($site, $result['error'] ?? 'Full-Backup fehlgeschlagen');

                return;
            }
            $meta['steps']['backup'] = 'done';
            $meta['backup_id'] = $result['backup']['id'] ?? ($result['backup_id'] ?? null);
            $site->forceFill([
                'onboarding_status' => 'awaiting_staging',
                'onboarding_meta' => $meta,
            ])->save();

            try {
                $job = $this->dispatcher->dispatch($site->fresh(), 'staging_create', [
                    'with_backup' => false,
                ]);
                $meta['staging_job_id'] = $job->id;
                $site->forceFill(['onboarding_meta' => $meta])->save();
            } catch (\Throwable $e) {
                $this->fail($site, 'Staging konnte nicht gestartet werden: '.$e->getMessage());
            }

            return;
        }

        if ($command === 'staging_create' && $onboarding === 'awaiting_staging') {
            if ($status === 'cancelled') {
                $this->fail($site, 'Onboarding abgebrochen (Staging)');

                return;
            }
            if ($status !== 'completed' || (($result['ok'] ?? true) === false)) {
                $this->fail($site, $result['error'] ?? 'Development-Umgebung fehlgeschlagen');

                return;
            }
            $meta['steps']['staging'] = 'done';
            $meta['finished_at'] = now()->toIso8601String();
            $site->forceFill([
                'onboarding_status' => 'done',
                'onboarding_meta' => $meta,
            ])->save();
        }
    }

    public function fail(Site $site, string $message): void
    {
        $meta = $site->onboarding_meta ?? [];
        $meta['error'] = $message;
        $meta['failed_at'] = now()->toIso8601String();
        $site->forceFill([
            'onboarding_status' => 'failed',
            'onboarding_meta' => $meta,
        ])->save();
    }

    public function statusPayload(Site $site, ?StagingPortalService $staging = null): array
    {
        $staging ??= app(StagingPortalService::class);
        $activeJob = $site->jobs()
            ->whereIn('status', ['pending', 'running'])
            ->latest()
            ->first();
        $jobProgress = $activeJob ? JobProgress::forJob($activeJob) : null;

        $base = match ($site->onboarding_status) {
            'awaiting_pair' => ['percent' => 12, 'label' => 'Warte auf Plugin-Verbindung…'],
            'awaiting_backup' => ['percent' => 40, 'label' => 'Full-Backup läuft…'],
            'awaiting_staging' => ['percent' => 72, 'label' => 'Development-Umgebung wird erzeugt…'],
            'done' => ['percent' => 100, 'label' => 'Onboarding abgeschlossen'],
            'failed' => ['percent' => (int) ($jobProgress['percent'] ?? 0), 'label' => $site->onboarding_meta['error'] ?? 'Fehlgeschlagen'],
            default => ['percent' => 5, 'label' => 'Vorbereitung…'],
        };

        // Blend live agent progress into the current onboarding phase window.
        if ($jobProgress && in_array($site->onboarding_status, ['awaiting_backup', 'awaiting_staging'], true)) {
            $window = $site->onboarding_status === 'awaiting_backup'
                ? [28, 58]
                : [60, 92];
            $span = $window[1] - $window[0];
            $base['percent'] = (int) round($window[0] + ($jobProgress['percent'] / 100) * $span);
            $base['label'] = $jobProgress['label'] ?: $base['label'];
        }

        return [
            'status' => $site->onboarding_status,
            'meta' => $site->onboarding_meta,
            'staging_portal' => $staging->toPortalPayload($site),
            'progress' => [
                'percent' => min(100, max(0, $base['percent'])),
                'label' => $base['label'],
                'job' => $jobProgress,
            ],
        ];
    }
}
