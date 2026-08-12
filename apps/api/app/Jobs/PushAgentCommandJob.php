<?php

namespace App\Jobs;

use App\Models\AgentJob;
use App\Services\AgentClient;
use App\Services\AuditLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PushAgentCommandJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $jobId) {}

    public function handle(AgentClient $client): void
    {
        $job = AgentJob::with('site')->find($this->jobId);
        if (! $job || ! $job->site) {
            return;
        }

        if (in_array($job->status, ['cancelled', 'completed', 'failed'], true)) {
            return;
        }

        // Atomic claim — do not resurrect a cancelled job
        $claimed = AgentJob::where('id', $job->id)
            ->whereIn('status', ['pending', 'running'])
            ->update([
                'status' => 'running',
                'started_at' => $job->started_at ?? now(),
                'attempts' => $job->attempts + 1,
                'progress' => $job->progress ?? 3,
                'progress_label' => $job->progress_label ?? 'An Agent senden…',
            ]);

        if ($claimed === 0) {
            return;
        }

        $job->refresh();
        if ($job->status === 'cancelled' || ! $job->site) {
            return;
        }

        try {
            $result = $client->pushCommand($job->site, $job);

            $job->refresh();
            if ($job->status === 'cancelled' || ! empty($result['cancelled'])) {
                return;
            }

            // Agent may accept heavy work asynchronously and report later via /jobs/{id}/result
            if (! empty($result['queued']) || ! empty($result['accepted'])) {
                AgentJob::where('id', $job->id)
                    ->whereIn('status', ['pending', 'running'])
                    ->update([
                        'status' => 'running',
                        'result' => $result,
                        'progress' => max((int) ($job->progress ?? 0), 8),
                        'progress_label' => 'In Agent-Warteschlange…',
                    ]);
                AuditLogger::log('job.queued_on_agent', $job->organization_id, null, $job->site_id, [
                    'job_id' => $job->id,
                    'command' => $job->command,
                ]);

                return;
            }

            $ok = ($result['ok'] ?? true) !== false;
            AgentJob::where('id', $job->id)
                ->whereIn('status', ['pending', 'running'])
                ->update([
                    'status' => $ok ? 'completed' : 'failed',
                    'result' => $result,
                    'error' => $ok ? null : (string) ($result['error'] ?? 'Agent reported failure'),
                    'finished_at' => now(),
                ]);

            AuditLogger::log($ok ? 'job.completed' : 'job.failed', $job->organization_id, null, $job->site_id, [
                'job_id' => $job->id,
                'command' => $job->command,
            ]);
        } catch (\Throwable $e) {
            $job->refresh();
            if ($job->status === 'cancelled') {
                return;
            }
            Log::error('Agent job failed', ['job_id' => $job->id, 'error' => $e->getMessage()]);
            AgentJob::where('id', $job->id)
                ->whereIn('status', ['pending', 'running'])
                ->update([
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'finished_at' => now(),
                ]);
            AuditLogger::log('job.failed', $job->organization_id, null, $job->site_id, [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
