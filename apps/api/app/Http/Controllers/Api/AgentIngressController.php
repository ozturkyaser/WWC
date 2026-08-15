<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Models\VulnerabilityFinding;
use App\Services\ActivityMonitorService;
use App\Services\AlertService;
use App\Services\AuditLogger;
use App\Services\MaintenanceAgentService;
use App\Services\PluginPackager;
use App\Services\SiteOnboardingService;
use App\Services\StagingPortalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AgentIngressController extends Controller
{
    public function pair(Request $request, \App\Services\PairingService $pairing)
    {
        $data = $request->validate([
            'code' => 'required|string|max:64',
            // Soft rule: some WP installs report uncommon local URLs
            'site_url' => 'required|string|max:500',
            'wp_version' => 'nullable|string',
            'php_version' => 'nullable|string',
            'agent_version' => 'nullable|string',
        ]);

        try {
            // Use the host the plugin actually called (LAN IP / docker gateway / etc.)
            $reachableBase = $request->getSchemeAndHttpHost();
            $result = $pairing->complete($data['code'], $data['site_url'], $data, $reachableBase);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    public function heartbeat(Request $request, StagingPortalService $stagingPortal)
    {
        /** @var Site $site */
        $site = $request->attributes->get('agent_site');
        $data = $request->validate([
            'wp_version' => 'nullable|string',
            'php_version' => 'nullable|string',
            'agent_version' => 'nullable|string',
            'health' => 'nullable|array',
            'inventory' => 'nullable|array',
            'events' => 'nullable|array',
        ]);

        $health = $data['health'] ?? null;
        if (is_array($health) && isset($health['staging'])) {
            $stagingPortal->syncFromStagingPayload($site, $health['staging']);
            $health = $stagingPortal->stripSecretsFromHealth($health);
        }

        $wasOffline = $site->status === 'offline';

        $site->fill([
            'status' => 'online',
            'last_seen_at' => now(),
            'wp_version' => $data['wp_version'] ?? $site->wp_version,
            'php_version' => $data['php_version'] ?? $site->php_version,
            'agent_version' => $data['agent_version'] ?? $site->agent_version,
            'health' => $health ?? $site->health,
            'inventory' => $data['inventory'] ?? $site->inventory,
        ])->save();

        if ($wasOffline) {
            app(AlertService::class)->siteBackOnline($site);
        }

        foreach ($data['events'] ?? [] as $event) {
            $this->storeAgentEvent($site, $event);
        }

        return response()->json([
            'ok' => true,
            'server_time' => now()->toIso8601String(),
            'agent_update' => $this->agentUpdatePayload($data['agent_version'] ?? null),
            'guard' => app(ActivityMonitorService::class)->guardPayload($site->fresh()),
        ]);
    }

    public function events(Request $request)
    {
        /** @var Site $site */
        $site = $request->attributes->get('agent_site');
        $data = $request->validate([
            'events' => 'required|array|min:1',
            'events.*.type' => 'required|string',
            'events.*.title' => 'required|string',
            'events.*.severity' => 'nullable|string',
            'events.*.payload' => 'nullable|array',
            'events.*.occurred_at' => 'nullable|date',
        ]);

        $created = [];
        foreach ($data['events'] as $event) {
            $created[] = $this->storeAgentEvent($site, $event);
        }

        $site->update(['last_seen_at' => now(), 'status' => 'online']);

        return response()->json(['data' => $created]);
    }

    public function jobProgress(Request $request, string $jobId)
    {
        /** @var Site $site */
        $site = $request->attributes->get('agent_site');
        $job = $site->jobs()->where('id', $jobId)->firstOrFail();

        $data = $request->validate([
            'progress' => 'required|integer|min:0|max:100',
            'label' => 'nullable|string|max:300',
            'log' => 'nullable|array|max:50',
            'log.*.at' => 'nullable|string|max:40',
            'log.*.message' => 'required_with:log|string|max:300',
            'log.*.percent' => 'nullable|integer|min:0|max:100',
        ]);

        if (in_array($job->status, ['completed', 'failed', 'cancelled'], true)) {
            return response()->json([
                'ok' => true,
                'ignored' => true,
                'cancelled' => $job->status === 'cancelled',
            ]);
        }

        $log = is_array($job->progress_log) ? $job->progress_log : [];
        foreach ($data['log'] ?? [] as $entry) {
            if (! is_array($entry) || empty($entry['message'])) {
                continue;
            }
            $log[] = [
                'at' => $entry['at'] ?? now()->toIso8601String(),
                'message' => (string) $entry['message'],
                'percent' => isset($entry['percent']) ? (int) $entry['percent'] : (int) $data['progress'],
            ];
        }
        if (count($log) > 200) {
            $log = array_slice($log, -200);
        }

        $job->update([
            'status' => 'running',
            'progress' => $data['progress'],
            'progress_label' => $data['label'] ?? $job->progress_label,
            'progress_log' => $log,
            'started_at' => $job->started_at ?? now(),
        ]);

        return response()->json(['ok' => true]);
    }

    public function jobResult(
        Request $request,
        string $jobId,
        StagingPortalService $stagingPortal,
        SiteOnboardingService $onboarding,
        MaintenanceAgentService $maintenanceAgent
    ) {
        /** @var Site $site */
        $site = $request->attributes->get('agent_site');
        $job = $site->jobs()->where('id', $jobId)->firstOrFail();

        $data = $request->validate([
            'status' => 'required|in:completed,failed,cancelled',
            'result' => 'nullable|array',
            'error' => 'nullable|string',
            'inventory' => 'nullable|array',
            'progress' => 'nullable|integer|min:0|max:100',
        ]);

        // Keep user cancel authoritative — don't overwrite with late agent result
        if ($job->status === 'cancelled' && $data['status'] !== 'cancelled') {
            return response()->json(['ok' => true, 'ignored' => true, 'cancelled' => true]);
        }

        $log = is_array($job->progress_log) ? $job->progress_log : [];
        $isDryRun = in_array($job->command, ['staging_update_plugin', 'staging_update_theme'], true)
            || ($job->command === 'update_batch' && is_array($job->payload) && (($job->payload['mode'] ?? '') === 'staging'));

        $completedLabel = $isDryRun ? 'OK' : 'Fertig';
        if ($data['status'] === 'completed' && $job->command === 'update_batch') {
            $failedCount = (int) (($data['result']['failed'] ?? 0));
            $total = (int) (($data['result']['total'] ?? 0));
            if ($failedCount === 0) {
                $completedLabel = $isDryRun ? 'OK' : 'Fertig';
            } else {
                $completedLabel = sprintf('%d/%d fehlgeschlagen', $failedCount, max(1, $total));
            }
        }

        $log[] = [
            'at' => now()->toIso8601String(),
            'message' => match ($data['status']) {
                'completed' => $completedLabel,
                'cancelled' => 'Abgebrochen',
                default => $data['error'] ?? 'Fehlgeschlagen',
            },
            'percent' => $data['status'] === 'completed' ? 100 : (int) ($data['progress'] ?? $job->progress ?? 0),
        ];
        if (count($log) > 200) {
            $log = array_slice($log, -200);
        }

        $job->update([
            'status' => $data['status'],
            'result' => $data['result'] ?? null,
            'error' => $data['error'] ?? null,
            'progress' => $data['status'] === 'completed' ? 100 : ($data['progress'] ?? $job->progress),
            'progress_label' => match ($data['status']) {
                'completed' => $completedLabel,
                'cancelled' => 'Abgebrochen',
                'failed' => $data['error'] ?? 'Fehlgeschlagen',
                default => $job->progress_label,
            },
            'progress_log' => $log,
            'finished_at' => now(),
        ]);

        if (! empty($data['inventory'])) {
            $site->inventory = $data['inventory'];
            $site->wp_version = $data['inventory']['core']['version'] ?? $site->wp_version;
            $site->save();
        }

        if ($data['status'] === 'completed') {
            $stagingPortal->syncFromJobResult($site->fresh(), $job->command, $data['result'] ?? null);
        }

        // Haertungs-Status vom Agent in der Site speichern, damit das Portal
        // den zuletzt angewendeten Zustand anzeigen kann.
        if ($data['status'] === 'completed'
            && in_array($job->command, ['security_harden', 'security_status'], true)
            && is_array($data['result']['status'] ?? null)
        ) {
            $freshSite = $site->fresh();
            $hardening = $freshSite->hardening ?? [];
            $hardening['status'] = $data['result']['status'];
            $hardening['settings'] = $data['result']['status']['settings'] ?? ($hardening['settings'] ?? []);
            $freshSite->update(['hardening' => $hardening]);
        }

        $onboarding->handleJobResult(
            $site->fresh(),
            $job->command,
            $data['status'],
            $data['result'] ?? null
        );

        if ($data['status'] === 'completed' && in_array($job->command, ['update_plugin', 'update_theme', 'update_core'], true)) {
            $findingId = $job->payload['finding_id'] ?? null;
            if ($findingId) {
                VulnerabilityFinding::where('id', $findingId)->where('site_id', $site->id)->update([
                    'status' => 'fixed',
                    'resolved_at' => now(),
                ]);
            }
        }

        AuditLogger::log('job.result', $site->organization_id, null, $site->id, [
            'job_id' => $job->id,
            'status' => $data['status'],
        ]);

        if ($data['status'] === 'failed' && ! $isDryRun) {
            $this->alertOnFailedJob($site, $job->fresh(), $data['error'] ?? null);
        }

        try {
            $maintenanceAgent->continueAfterJob($job->fresh());
        } catch (\Throwable $e) {
            Log::warning('maintenance continue after job failed', ['error' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }

    private function alertOnFailedJob(Site $site, \App\Models\AgentJob $job, ?string $error): void
    {
        $labels = [
            'backup_full' => 'Backup (voll)',
            'backup_incremental' => 'Backup (inkrementell)',
            'restore_backup' => 'Backup-Wiederherstellung',
            'update_plugin' => 'Plugin-Update',
            'update_theme' => 'Theme-Update',
            'update_core' => 'WordPress-Core-Update',
            'update_batch' => 'Sammel-Update',
            'self_update' => 'Agent-Update',
        ];
        if (! isset($labels[$job->command])) {
            return;
        }

        app(AlertService::class)->notify(
            $site->organization,
            'job_failed',
            "{$labels[$job->command]} fehlgeschlagen: {$site->name}",
            array_filter([
                "Auf der Site \"{$site->name}\" ({$site->url}) ist ein Job fehlgeschlagen.",
                "Vorgang: {$labels[$job->command]}",
                $error ? "Fehler: {$error}" : null,
            ]),
            "/sites/{$site->id}",
            'error',
            "job_failed:{$job->id}",
            $site
        );
    }

    /**
     * @return array{available:bool,version?:string,package?:string}|null
     */
    private function agentUpdatePayload(?string $currentVersion): ?array
    {
        try {
            $packager = app(PluginPackager::class);
            $latest = $packager->version();
            $current = $currentVersion ?: '0.0.0';
            if (version_compare($current, $latest, '>=')) {
                return ['available' => false, 'version' => $latest];
            }
            $meta = $packager->releaseMeta();

            return [
                'available' => true,
                'version' => $meta['version'],
                'package' => $meta['package'],
                'sha256' => $meta['sha256'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::warning('agent update payload failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function storeAgentEvent(Site $site, array $event): SiteEvent
    {
        $type = (string) ($event['type'] ?? 'info');
        $row = SiteEvent::create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'type' => $type,
            'severity' => $event['severity'] ?? 'info',
            'title' => $event['title'] ?? 'Event',
            'payload' => $this->sanitizeEventPayload($type, is_array($event['payload'] ?? null) ? $event['payload'] : []),
            'occurred_at' => $event['occurred_at'] ?? now(),
        ]);

        app(ActivityMonitorService::class)->ingest($site, $row);

        return $row->fresh();
    }

    /**
     * Never persist passwords or secrets from WordPress events.
     */
    private function sanitizeEventPayload(string $type, array $payload): array
    {
        foreach (['password', 'pwd', 'pass', 'passwd', 'user_pass', 'application_password', 'secret', 'token'] as $key) {
            unset($payload[$key]);
        }
        unset($payload['password_encrypted']);

        foreach (['username', 'url', 'request_uri', 'ip', 'user_agent', 'referer', 'user_login', 'user_email'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key])) {
                $payload[$key] = mb_substr($payload[$key], 0, 500);
            }
        }

        return $payload;
    }
}
