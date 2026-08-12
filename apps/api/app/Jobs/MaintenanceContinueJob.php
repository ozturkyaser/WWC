<?php

namespace App\Jobs;

use App\Models\AgentJob;
use App\Models\MaintenanceRun;
use App\Services\MaintenanceAgentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class MaintenanceContinueJob implements ShouldQueue
{
    use Queueable;

    // Releases count as attempts: staging create + dry-run + live can take a while
    // on large sites (backup + full copy), so allow up to ~1.5h of polling.
    public int $tries = 60;

    public function __construct(public string $runId) {}

    public function handle(MaintenanceAgentService $agent): void
    {
        $run = MaintenanceRun::find($this->runId);
        if (! $run || ! in_array($run->status, ['dry_running', 'applying'], true)) {
            return;
        }

        $site = $run->site;
        if (! $site) {
            return;
        }

        if ($run->staging_job_id && ! $run->dry_run_job_id) {
            $job = AgentJob::find($run->staging_job_id);
            if (! $job) {
                return;
            }
            if (in_array($job->status, ['pending', 'running'], true)) {
                $this->release(120);

                return;
            }
            $agent->continueAfterJob($job);

            return;
        }

        if ($run->dry_run_job_id && $run->status === 'dry_running') {
            $job = AgentJob::find($run->dry_run_job_id);
            if (! $job) {
                return;
            }
            if (in_array($job->status, ['pending', 'running'], true)) {
                $this->release(90);

                return;
            }
            $agent->continueAfterJob($job);

            return;
        }

        if ($run->live_job_id && $run->status === 'applying') {
            $job = AgentJob::find($run->live_job_id);
            if (! $job) {
                return;
            }
            if (in_array($job->status, ['pending', 'running'], true)) {
                $this->release(90);

                return;
            }
            $agent->continueAfterJob($job);
        }
    }

    public function failed(?\Throwable $e): void
    {
        Log::warning('MaintenanceContinueJob failed', [
            'run' => $this->runId,
            'error' => $e?->getMessage(),
        ]);
        $run = MaintenanceRun::find($this->runId);
        if ($run && in_array($run->status, ['dry_running', 'applying'], true)) {
            $run->update([
                'status' => 'needs_review',
                'error' => 'Wartungs-Pipeline Timeout/Fehler – bitte manuell prüfen',
                'finished_at' => now(),
            ]);
        }
    }
}
