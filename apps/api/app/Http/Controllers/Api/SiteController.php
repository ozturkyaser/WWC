<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Models\AgentJob;
use App\Services\AgentDispatcher;
use App\Services\AuditLogger;
use App\Services\JobProgress;
use App\Services\PairingService;
use App\Services\PluginPackager;
use App\Services\ProjectPurgeService;
use App\Services\StagingPortalService;
use App\Services\VulnerabilityScanner;
use App\Services\MaintenanceAgentService;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->attributes->get('organization_id');
        $sites = Site::where('organization_id', $orgId)
            ->with(['client:id,name', 'project:id,name'])
            ->withCount([
                'findings as open_findings_count' => fn ($q) => $q->where('status', 'open'),
            ])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $sites]);
    }

    public function store(Request $request, PairingService $pairing)
    {
        $orgId = $request->attributes->get('organization_id');
        $data = $request->validate([
            'name' => 'required|string|max:160',
            'url' => 'required|url',
            'client_id' => 'nullable|uuid',
            'project_id' => 'nullable|uuid',
        ]);

        $site = Site::create([
            'organization_id' => $orgId,
            'name' => $data['name'],
            'url' => rtrim($data['url'], '/'),
            'client_id' => $data['client_id'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'status' => 'pending',
        ]);

        $code = $pairing->createCode($site);
        AuditLogger::log('site.created', $orgId, $request->user(), $site->id, [], $request);

        return response()->json([
            'data' => $site,
            'pairing_code' => $code->code,
            'expires_at' => $code->expires_at,
            'plugin_download_url' => url('/api/plugin/download'),
            'api_url' => rtrim((string) config('wwc.public_api_url', config('app.url')), '/'),
            'install' => [
                'site_id' => $site->id,
                'pairing_code' => $code->code,
                'expires_at' => $code->expires_at,
                'plugin_download_url' => url('/api/plugin/download'),
                'api_url' => rtrim((string) config('wwc.public_api_url', config('app.url')), '/'),
                'mode' => 'install',
                'steps' => [
                    'Nur beim ersten Mal: Plugin-ZIP herunterladen und in WordPress aktivieren',
                    'Einstellungen → WWC Agent: API-URL bleibt https://wwc.kiservicehub.de, Pairing-Code eintragen',
                ],
            ],
        ], 201);
    }

    public function show(Request $request, string $id, StagingPortalService $staging, VulnerabilityScanner $scanner, PluginPackager $packager, MaintenanceAgentService $maintenance)
    {
        $orgId = $request->attributes->get('organization_id');
        $site = Site::where('organization_id', $orgId)
            ->with(['client', 'project', 'findings.vulnerability'])
            ->findOrFail($id);

        $events = SiteEvent::where('site_id', $site->id)->latest('occurred_at')->limit(120)->get();
        $jobs = $site->jobs()->latest()->limit(30)->get()->map(fn (AgentJob $job) => JobProgress::enrich($job));
        $activeJobs = $site->jobs()
            ->whereIn('status', ['pending', 'running'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (AgentJob $job) => JobProgress::enrich($job));

        $latestAgent = $packager->version();
        $currentAgent = (string) ($site->agent_version ?: '0.0.0');

        if (! $site->staging_url) {
            $createJob = $site->jobs()
                ->where('command', 'staging_create')
                ->where('status', 'completed')
                ->latest()
                ->first();
            $payload = is_array($createJob?->result) ? $createJob->result : [];
            $fromJob = is_array($payload['staging'] ?? null) ? $payload['staging'] : null;
            if (is_array($fromJob) && ! empty($fromJob['exists'])) {
                if (! empty($payload['access']) && is_array($payload['access'])) {
                    $fromJob['access'] = $payload['access'];
                }
                $staging->syncFromStagingPayload($site, $fromJob);
                $site = $site->fresh();
            }
        }

        $serverBackups = $site->serverBackups()
            ->orderByDesc('backup_created_at')
            ->limit(30)
            ->get(['id', 'backup_id', 'type', 'label', 'status', 'size_bytes', 'wp_version', 'file_count', 'parent_backup_id', 'backup_created_at', 'uploaded_at', 'verified_at']);

        return response()->json([
            'data' => $site,
            'staging_portal' => $staging->toPortalPayload($site),
            'events' => $events,
            'jobs' => $jobs,
            'active_jobs' => $activeJobs,
            'prioritized_updates' => $scanner->prioritizedUpdates($site),
            'agent_release' => [
                'latest' => $latestAgent,
                'update_available' => version_compare($currentAgent, $latestAgent, '<'),
            ],
            'maintenance' => $maintenance->payloadForSite($site),
            'server_backups' => $serverBackups,
            'dev_clone' => app(\App\Services\DevCloneService::class)->payload($site),
            'agent_synced' => is_array($site->inventory) && $site->inventory !== [],
        ]);
    }

    public function updateBackupSchedule(Request $request, string $id)
    {
        $orgId = $request->attributes->get('organization_id');
        $site = Site::where('organization_id', $orgId)->findOrFail($id);

        $data = $request->validate([
            'enabled' => 'required|boolean',
            'time' => 'sometimes|string|regex:/^\d{2}:\d{2}$/',
            'weekly_full_day' => 'sometimes|integer|min:0|max:6',
            'incremental_daily' => 'sometimes|boolean',
        ]);

        $schedule = array_merge([
            'time' => '02:30',
            'weekly_full_day' => 0,
            'incremental_daily' => true,
        ], $site->backup_schedule ?? [], $data);

        $site->update(['backup_schedule' => $schedule]);
        AuditLogger::log('site.backup_schedule', $orgId, $request->user(), $site->id, $schedule, $request);

        return response()->json(['data' => $site->fresh()]);
    }

    /**
     * Speichert die gewuenschten Haertungs-Einstellungen und schickt sie als
     * security_harden-Befehl an den Agent, der sie auf der Site umsetzt.
     */
    public function updateHardening(Request $request, string $id, AgentDispatcher $dispatcher)
    {
        $orgId = $request->attributes->get('organization_id');
        $site = Site::where('organization_id', $orgId)->findOrFail($id);

        $data = $request->validate([
            'hide_login' => 'sometimes|boolean',
            'login_slug' => 'sometimes|nullable|string|max:60|regex:/^[a-z0-9\-]*$/',
            'limit_login_attempts' => 'sometimes|boolean',
            'disable_xmlrpc' => 'sometimes|boolean',
            'disable_file_edit' => 'sometimes|boolean',
            'disable_user_enumeration' => 'sometimes|boolean',
            'hide_wp_version' => 'sometimes|boolean',
            'security_headers' => 'sometimes|boolean',
            'disable_pingbacks' => 'sometimes|boolean',
            'disable_app_passwords' => 'sometimes|boolean',
            'block_php_uploads' => 'sometimes|boolean',
            'disable_directory_listing' => 'sometimes|boolean',
        ]);

        $hardening = $site->hardening ?? [];
        $settings = array_merge($hardening['settings'] ?? [], $data);
        $hardening['settings'] = $settings;
        $site->update(['hardening' => $hardening]);

        $job = $dispatcher->dispatch($site, 'security_harden', $settings, $request->user());
        AuditLogger::log('site.hardening', $orgId, $request->user(), $site->id, $settings, $request);

        return response()->json(['data' => $site->fresh(), 'job' => $job], 202);
    }

    public function pairingCode(Request $request, string $id, PairingService $pairing)
    {
        $orgId = $request->attributes->get('organization_id');
        $site = Site::where('organization_id', $orgId)->findOrFail($id);
        $code = $pairing->createCode($site);

        return response()->json([
            'pairing_code' => $code->code,
            'expires_at' => $code->expires_at,
            'plugin_download_url' => url('/api/plugin/download'),
            'api_url' => rtrim((string) config('wwc.public_api_url', config('app.url')), '/'),
        ]);
    }

    public function reconnect(Request $request, string $id, PairingService $pairing)
    {
        $orgId = $request->attributes->get('organization_id');
        $site = Site::where('organization_id', $orgId)->findOrFail($id);
        $result = $pairing->reconnect($site);
        AuditLogger::log('site.reconnected', $orgId, $request->user(), $site->id, [], $request);

        return response()->json([
            'data' => $site->fresh(),
            'install' => [
                'site_id' => $site->id,
                'site_name' => $site->name,
                'site_url' => $site->url,
                'pairing_code' => $result['pairing_code'],
                'expires_at' => $result['expires_at'],
                'plugin_download_url' => $result['plugin_download_url'],
                'api_url' => $result['api_url'],
                'mode' => 'reconnect',
                'steps' => [
                    'In WordPress: Einstellungen → WWC Agent (Plugin bleibt installiert)',
                    'Falls verbunden: Verbindung trennen',
                    'API-URL prüfen, neuen Pairing-Code eintragen → Verbinden',
                ],
            ],
        ]);
    }

    public function destroy(Request $request, string $id, PairingService $pairing, ProjectPurgeService $purge)
    {
        $orgId = $request->attributes->get('organization_id');
        $site = Site::where('organization_id', $orgId)->findOrFail($id);
        $remote = $purge->purgeRemoteSite($site);
        $pairing->disconnect($site);
        app(\App\Services\BackupStorageService::class)->deleteAllForSite($site);
        try {
            app(\App\Services\DevCloneService::class)->destroy($site);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Dev clone cleanup on site delete failed', ['site' => $site->id, 'error' => $e->getMessage()]);
        }
        AuditLogger::log('site.deleted', $orgId, $request->user(), $site->id, [
            'remote' => $remote,
        ], $request);
        $site->delete();

        return response()->json(['ok' => true, 'remote' => $remote]);
    }

    public function dispatchCommand(Request $request, string $id, AgentDispatcher $dispatcher, StagingPortalService $staging)
    {
        $orgId = $request->attributes->get('organization_id');
        $site = Site::where('organization_id', $orgId)->findOrFail($id);
        $data = $request->validate([
            'command' => 'required|string',
            'payload' => 'nullable|array',
        ]);

        if ($data['command'] === 'staging_create' && ! $site->staging_slug) {
            $site->staging_slug = $staging->uniqueSlug($site);
            $site->save();
        }

        try {
            app(\App\Services\AgentClient::class)->ping($site);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'wwc_unpaired') || str_contains($msg, 'HTTP 401')) {
                $apiUrl = rtrim((string) config('wwc.public_api_url', config('app.url')), '/');

                return response()->json([
                    'message' => 'Der Agent auf der Website ist nicht verbunden. „Neu verbinden“ klicken, dann in WordPress unter Einstellungen → WWC Agent den neuen Code eintragen. API-URL: '.$apiUrl,
                ], 409);
            }

            return response()->json([
                'message' => 'Agent nicht erreichbar: '.$msg,
            ], 502);
        }

        try {
            $job = $dispatcher->dispatch($site, $data['command'], $data['payload'] ?? [], $request->user());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $job,
            'staging_portal' => $staging->toPortalPayload($site->fresh()),
        ], 202);
    }

    public function rotateSecret(Request $request, string $id, PairingService $pairing)
    {
        $orgId = $request->attributes->get('organization_id');
        $site = Site::where('organization_id', $orgId)->findOrFail($id);
        $result = $pairing->rotateSecret($site);

        return response()->json(['data' => $result]);
    }

    public function dashboard(Request $request)
    {
        $orgId = $request->attributes->get('organization_id');
        $sites = Site::where('organization_id', $orgId)->get();
        $failedLogins = SiteEvent::where('organization_id', $orgId)
            ->where('type', 'login_failed')
            ->where('occurred_at', '>=', now()->subDay())
            ->count();
        $openVulns = \App\Models\VulnerabilityFinding::where('organization_id', $orgId)
            ->where('status', 'open')
            ->count();

        $activeJobs = AgentJob::where('organization_id', $orgId)
            ->whereIn('status', ['pending', 'running'])
            ->with('site:id,name')
            ->latest()
            ->limit(12)
            ->get()
            ->map(function (AgentJob $job) {
                $row = JobProgress::enrich($job);
                $row['site_name'] = $job->site?->name;

                return $row;
            });

        return response()->json([
            'sites_total' => $sites->count(),
            'sites_online' => $sites->where('status', 'online')->count(),
            'sites_offline' => $sites->whereIn('status', ['offline', 'error', 'pending'])->count(),
            'failed_logins_24h' => $failedLogins,
            'open_vulnerabilities' => $openVulns,
            'recent_events' => SiteEvent::where('organization_id', $orgId)
                ->latest('occurred_at')
                ->limit(20)
                ->get(),
            'active_jobs' => $activeJobs,
        ]);
    }
}
