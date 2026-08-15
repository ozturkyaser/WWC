<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgentJob;
use App\Models\AuditLog;
use App\Models\MaintenanceRun;
use App\Models\Site;
use App\Models\SiteBackup;
use App\Models\SiteEvent;
use App\Models\TimeEntry;
use App\Services\AgentDispatcher;
use App\Services\AuditLogger;
use App\Services\HardeningPolicy;
use App\Services\MaintenanceAgentService;
use App\Services\OpsBoardService;
use App\Services\UptimeProbeService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class OpsController extends Controller
{
    public function dashboard(Request $request, OpsBoardService $board)
    {
        return response()->json($board->build($request->attributes->get('organization_id')));
    }

    public function reviews(Request $request)
    {
        $orgId = $request->attributes->get('organization_id');

        return response()->json([
            'data' => MaintenanceRun::where('organization_id', $orgId)
                ->whereIn('status', ['needs_review', 'planned', 'failed'])
                ->with('site:id,name,url')
                ->latest()
                ->limit(50)
                ->get(),
        ]);
    }

    public function approveReview(Request $request, string $id, MaintenanceAgentService $agent)
    {
        $orgId = $request->attributes->get('organization_id');
        $run = MaintenanceRun::where('organization_id', $orgId)->findOrFail($id);
        $site = $run->site;
        if (! $site) {
            return response()->json(['message' => 'Site fehlt.'], 422);
        }
        $updated = $agent->startExecution($site, $run);

        return response()->json(['data' => $updated], 202);
    }

    public function dismissReview(Request $request, string $id)
    {
        $orgId = $request->attributes->get('organization_id');
        $run = MaintenanceRun::where('organization_id', $orgId)->findOrFail($id);
        $run->update(['status' => 'dismissed', 'finished_at' => now()]);

        return response()->json(['data' => $run]);
    }

    public function freeze(Request $request, string $id)
    {
        $orgId = $request->attributes->get('organization_id');
        $site = Site::where('organization_id', $orgId)->findOrFail($id);
        $data = $request->validate([
            'until' => 'nullable|date',
            'reason' => 'nullable|string|max:200',
            'clear' => 'sometimes|boolean',
        ]);
        if (! empty($data['clear'])) {
            $site->update(['freeze_until' => null, 'freeze_reason' => null]);
        } else {
            $site->update([
                'freeze_until' => $data['until'] ?? now()->addDays(7),
                'freeze_reason' => $data['reason'] ?? 'Wartungsfenster',
            ]);
        }

        return response()->json(['data' => $site->fresh()]);
    }

    public function rollback(Request $request, string $id, AgentDispatcher $dispatcher)
    {
        $orgId = $request->attributes->get('organization_id');
        $site = Site::where('organization_id', $orgId)->findOrFail($id);
        $backup = SiteBackup::where('site_id', $site->id)
            ->where('status', 'complete')
            ->where('type', 'full')
            ->orderByDesc('backup_created_at')
            ->first();
        if (! $backup) {
            return response()->json(['message' => 'Kein vollständiges Backup zum Zurücksetzen.'], 422);
        }
        $job = $dispatcher->dispatch($site, 'restore_backup', [
            'backup_id' => $backup->backup_id,
        ], $request->user());
        AuditLogger::log('site.rollback', $orgId, $request->user(), $site->id, [
            'backup_id' => $backup->backup_id,
        ], $request);

        return response()->json(['data' => $job, 'backup' => $backup], 202);
    }

    public function bulk(Request $request, AgentDispatcher $dispatcher)
    {
        $orgId = $request->attributes->get('organization_id');
        $data = $request->validate([
            'site_ids' => 'required|array|min:1|max:50',
            'site_ids.*' => 'uuid',
            'command' => 'required|string',
            'payload' => 'nullable|array',
        ]);

        $sites = Site::where('organization_id', $orgId)->whereIn('id', $data['site_ids'])->get();
        $jobs = [];
        foreach ($sites as $site) {
            if ($site->isFrozen() && str_contains($data['command'], 'update')) {
                continue;
            }
            $jobs[] = $dispatcher->dispatch($site, $data['command'], $data['payload'] ?? [], $request->user());
        }

        return response()->json(['data' => $jobs, 'count' => count($jobs)], 202);
    }

    public function applyHardeningPolicy(Request $request, string $id, HardeningPolicy $policy, AgentDispatcher $dispatcher)
    {
        $orgId = $request->attributes->get('organization_id');
        $site = Site::where('organization_id', $orgId)->findOrFail($id);
        $settings = $policy->expectedForSite($site);
        $hardening = $site->hardening ?? [];
        $hardening['settings'] = $settings;
        $site->update(['hardening' => $hardening]);
        $job = $dispatcher->dispatch($site, 'security_harden', $settings, $request->user());

        return response()->json(['data' => $site->fresh(), 'job' => $job], 202);
    }

    public function saveHardeningTemplate(Request $request, HardeningPolicy $policy)
    {
        $orgId = $request->attributes->get('organization_id');
        $org = \App\Models\Organization::findOrFail($orgId);
        $data = $request->validate([
            'tier' => 'required|in:1,2,3,custom',
            'settings' => 'required|array',
        ]);
        $saved = $policy->applyTemplate($org, $data['tier'], $data['settings']);

        return response()->json(['data' => $saved]);
    }

    public function probe(Request $request, string $id, UptimeProbeService $probe)
    {
        $orgId = $request->attributes->get('organization_id');
        $site = Site::where('organization_id', $orgId)->findOrFail($id);

        return response()->json(['data' => $probe->probe($site)]);
    }

    public function auditLogs(Request $request)
    {
        $orgId = $request->attributes->get('organization_id');

        return response()->json([
            'data' => AuditLog::where('organization_id', $orgId)->latest()->limit(100)->get(),
        ]);
    }

    public function monthlyReport(Request $request, string $projectId)
    {
        $orgId = $request->attributes->get('organization_id');
        $project = \App\Models\Project::where('organization_id', $orgId)->with(['client', 'sites'])->findOrFail($projectId);
        $month = $request->query('month')
            ? \Carbon\Carbon::parse($request->query('month'))->startOfMonth()
            : now()->startOfMonth();
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();
        $siteIds = $project->sites->pluck('id');

        $events = SiteEvent::whereIn('site_id', $siteIds)->whereBetween('occurred_at', [$from, $to])->get();
        $runs = MaintenanceRun::whereIn('site_id', $siteIds)->whereBetween('started_at', [$from, $to])->get();
        $backups = SiteBackup::whereIn('site_id', $siteIds)->whereBetween('backup_created_at', [$from, $to])->get();
        $minutes = (int) TimeEntry::where('project_id', $project->id)->whereBetween('occurred_at', [$from, $to])->sum('minutes');
        $included = (float) ($project->scope['hours_included'] ?? 0);

        $html = view('reports.monthly', [
            'project' => $project,
            'month' => $month,
            'events' => $events,
            'runs' => $runs,
            'backups' => $backups,
            'hours_used' => round($minutes / 60, 1),
            'hours_included' => $included,
            'updates' => $events->whereIn('type', ['plugin_updated', 'theme_updated', 'core_updated', 'update_batch'])->count(),
        ])->render();

        $pdf = Pdf::loadHTML($html);
        $filename = 'bericht-'.$project->id.'-'.$month->format('Y-m').'.pdf';

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
