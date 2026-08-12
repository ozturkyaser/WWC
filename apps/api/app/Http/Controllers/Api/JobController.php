<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentJob;
use App\Services\AgentClient;
use App\Services\AuditLogger;
use App\Services\JobProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class JobController extends Controller
{
    public function cancel(Request $request, string $id, AgentClient $client)
    {
        $orgId = $request->attributes->get('organization_id');
        $job = AgentJob::with('site')
            ->where('organization_id', $orgId)
            ->findOrFail($id);

        if (in_array($job->status, ['completed', 'failed', 'cancelled'], true)) {
            return response()->json([
                'data' => JobProgress::enrich($job),
                'message' => 'Job ist bereits beendet',
            ]);
        }

        $job->update([
            'status' => 'cancelled',
            'error' => 'Vom Benutzer abgebrochen',
            'progress_label' => 'Abgebrochen',
            'finished_at' => now(),
        ]);

        $agentNotified = false;
        if ($job->site && $job->site->getHmacSecret()) {
            try {
                $client->cancelJob($job->site, $job->id);
                $agentNotified = true;
            } catch (\Throwable $e) {
                Log::warning('Agent cancel notify failed', [
                    'job_id' => $job->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        AuditLogger::log('job.cancelled', $orgId, $request->user(), $job->site_id, [
            'job_id' => $job->id,
            'command' => $job->command,
            'agent_notified' => $agentNotified,
        ]);

        return response()->json([
            'data' => JobProgress::enrich($job->fresh()),
            'agent_notified' => $agentNotified,
        ]);
    }
}
