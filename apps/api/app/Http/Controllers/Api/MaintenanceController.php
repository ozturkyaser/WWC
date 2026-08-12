<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MaintenanceRun;
use App\Models\Site;
use App\Services\MaintenanceAgentService;
use Illuminate\Http\Request;

class MaintenanceController extends Controller
{
    public function show(Request $request, string $id, MaintenanceAgentService $agent)
    {
        $orgId = $request->attributes->get('organization_id');
        $site = Site::where('organization_id', $orgId)->findOrFail($id);
        $runs = $site->maintenanceRuns()->latest()->limit(15)->get();

        return response()->json([
            'agent' => $agent->payloadForSite($site),
            'runs' => $runs,
        ]);
    }

    public function updateSettings(Request $request, string $id, MaintenanceAgentService $agent)
    {
        $orgId = $request->attributes->get('organization_id');
        $site = Site::where('organization_id', $orgId)->findOrFail($id);
        $data = $request->validate([
            'enabled' => 'sometimes|boolean',
            'cadence' => 'sometimes|string|in:daily,weekly,monthly,off',
            'auto_apply' => 'sometimes|boolean',
        ]);

        if (array_key_exists('enabled', $data)) {
            $site->maintenance_agent_enabled = $data['enabled'];
        }
        if (array_key_exists('cadence', $data)) {
            $site->maintenance_cadence = $data['cadence'];
            $site->maintenance_next_run_at = match ($data['cadence']) {
                'daily' => now()->addDay(),
                'monthly' => now()->addMonth(),
                'off' => null,
                default => now()->addWeek(),
            };
        }
        if (array_key_exists('auto_apply', $data)) {
            $site->maintenance_auto_apply = $data['auto_apply'];
        }
        $site->save();

        return response()->json(['agent' => $agent->payloadForSite($site->fresh())]);
    }

    public function run(Request $request, string $id, MaintenanceAgentService $agent)
    {
        $orgId = $request->attributes->get('organization_id');
        $site = Site::where('organization_id', $orgId)->findOrFail($id);
        $data = $request->validate([
            'execute' => 'sometimes|boolean',
        ]);

        $run = $agent->run($site, [
            'trigger' => 'manual',
            'execute' => (bool) ($data['execute'] ?? false),
        ]);

        return response()->json([
            'data' => $run,
            'agent' => $agent->payloadForSite($site->fresh()),
        ], 202);
    }

    public function executePlan(Request $request, string $id, string $runId, MaintenanceAgentService $agent)
    {
        $orgId = $request->attributes->get('organization_id');
        $site = Site::where('organization_id', $orgId)->findOrFail($id);
        $run = MaintenanceRun::where('site_id', $site->id)->findOrFail($runId);

        if (! in_array($run->status, ['planned', 'needs_review', 'completed'], true)) {
            return response()->json(['message' => 'Run ist nicht bereit für Ausführung (Status: '.$run->status.')'], 422);
        }

        $run = $agent->startExecution($site, $run);

        return response()->json(['data' => $run], 202);
    }
}
