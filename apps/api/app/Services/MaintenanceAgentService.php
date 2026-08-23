<?php

namespace App\Services;

use App\Jobs\MaintenanceContinueJob;
use App\Models\AgentJob;
use App\Models\MaintenanceRun;
use App\Models\Site;
use App\Models\SiteEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MaintenanceAgentService
{
    public function __construct(
        private VulnerabilityScanner $scanner,
        private AgentDispatcher $dispatcher,
        private StagingPortalService $staging,
    ) {}

    /**
     * Full technician cycle: audit → plan → optional dry-run → optional live.
     *
     * @param  array{execute?:bool,trigger?:string}  $options
     */
    public function run(Site $site, array $options = []): MaintenanceRun
    {
        $trigger = (string) ($options['trigger'] ?? 'manual');
        $execute = array_key_exists('execute', $options)
            ? (bool) $options['execute']
            : (bool) $site->maintenance_auto_apply;

        $run = MaintenanceRun::create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'trigger' => $trigger,
            'status' => 'auditing',
            'started_at' => now(),
        ]);

        try {
            if ($site->getHmacSecret()) {
                try {
                    $this->dispatcher->dispatch($site, 'inventory', [], null);
                } catch (\Throwable $e) {
                    Log::info('maintenance inventory dispatch skipped', ['error' => $e->getMessage()]);
                }
            }

            $this->scanner->scanSite($site->fresh());
            $site->refresh();

            $audit = $this->buildAudit($site);
            $plan = $this->buildPlan($site, $audit);
            $summary = $this->summarizeWithAi($site, $audit, $plan) ?? $this->summarizeHeuristic($site, $audit, $plan);

            $run->update([
                'audit' => $audit,
                'plan' => $plan,
                'ai_summary' => $summary,
                'status' => 'planned',
                'technician_notes' => $this->notesFromAudit($audit, $plan),
            ]);

            SiteEvent::create([
                'organization_id' => $site->organization_id,
                'site_id' => $site->id,
                'type' => 'maintenance_audit',
                'severity' => ($audit['scores']['risk'] ?? 0) >= 70 ? 'warning' : 'info',
                'title' => 'Wartungs-Agent: Audit abgeschlossen',
                'payload' => [
                    'run_id' => $run->id,
                    'updates' => count($plan['updates'] ?? []),
                    'unused_plugins' => count($audit['unused_plugins'] ?? []),
                    'risk' => $audit['scores']['risk'] ?? 0,
                ],
                'occurred_at' => now(),
            ]);

            $this->touchSchedule($site);

            if (! $execute || empty($plan['updates'])) {
                $run->update([
                    'status' => empty($plan['updates']) ? 'completed' : 'planned',
                    'finished_at' => empty($plan['updates']) || ! $execute ? now() : null,
                ]);

                return $run->fresh();
            }

            return $this->startExecution($site, $run->fresh());
        } catch (\Throwable $e) {
            Log::error('maintenance agent failed', ['site' => $site->id, 'error' => $e->getMessage()]);
            $run->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            return $run->fresh();
        }
    }

    public function startExecution(Site $site, MaintenanceRun $run): MaintenanceRun
    {
        $updates = $run->plan['updates'] ?? [];
        if ($updates === []) {
            $run->update(['status' => 'completed', 'finished_at' => now()]);

            return $run->fresh();
        }

        if (! app(DevCloneService::class)->isReady($site)) {
            $run->update([
                'status' => 'needs_review',
                'error' => 'Keine isolierte Umgebung auf dem WWC-Server. Bitte im Tab Development erstellen, dann den Plan ausführen.',
                'finished_at' => now(),
            ]);

            return $run->fresh();
        }

        return $this->dispatchDryRun($site, $run, $updates);
    }

    public function continueAfterJob(AgentJob $job): void
    {
        $run = MaintenanceRun::query()
            ->where(function ($q) use ($job) {
                $q->where('dry_run_job_id', $job->id)
                    ->orWhere('staging_job_id', $job->id)
                    ->orWhere('live_job_id', $job->id);
            })
            ->whereIn('status', ['dry_running', 'applying'])
            ->latest()
            ->first();

        if (! $run) {
            return;
        }

        $site = $run->site;
        if (! $site) {
            return;
        }

        if ($run->staging_job_id === $job->id) {
            if ($job->status === 'failed') {
                $run->update([
                    'status' => 'needs_review',
                    'error' => 'Staging für Dry-Run fehlgeschlagen: '.($job->error ?: 'unbekannt'),
                    'finished_at' => now(),
                ]);

                return;
            }
            if ($job->status === 'completed') {
                $updates = $run->plan['updates'] ?? [];
                $this->dispatchDryRun($site->fresh(), $run, $updates);
            }

            return;
        }

        if ($run->dry_run_job_id === $job->id) {
            $this->afterDryRun($site, $run, $job);

            return;
        }

        if ($run->live_job_id === $job->id) {
            if (in_array($job->status, ['completed', 'failed', 'cancelled'], true)) {
                $ok = $job->status === 'completed';
                $run->update([
                    'status' => $ok ? 'completed' : 'needs_review',
                    'error' => $ok ? null : ($job->error ?: 'Live-Update fehlgeschlagen'),
                    'finished_at' => now(),
                    'technician_notes' => trim(($run->technician_notes ?? '')."\nLive-Updates: ".($ok ? 'OK' : 'Fehler')),
                ]);
            }
        }
    }

    public function afterDryRun(Site $site, MaintenanceRun $run, AgentJob $job): void
    {
        if ($job->status === 'failed' || $job->status === 'cancelled') {
            $run->update([
                'status' => 'needs_review',
                'error' => 'Dry-Run fehlgeschlagen: '.($job->error ?: $job->status),
                'finished_at' => now(),
            ]);

            return;
        }

        if ($job->status !== 'completed') {
            return;
        }

        $result = is_array($job->result) ? $job->result : [];
        $failed = (int) ($result['failed'] ?? 0);
        $itemFails = collect($result['results'] ?? [])->filter(fn ($r) => ! ($r['ok'] ?? false))->values()->all();

        if ($failed > 0 || $itemFails !== []) {
            $run->update([
                'status' => 'needs_review',
                'error' => 'Dry-Run nicht vollständig OK – Live-Updates gestoppt',
                'plan' => array_merge($run->plan ?? [], ['dry_run_failures' => $itemFails]),
                'finished_at' => now(),
                'technician_notes' => trim(($run->technician_notes ?? '')."\nDry-Run: Fehler – bitte manuell prüfen."),
            ]);

            return;
        }

        $updates = $run->plan['updates'] ?? [];
        $liveItems = array_map(static fn ($u) => [
            'type' => $u['type'],
            'slug' => $u['slug'] ?? '',
        ], $updates);

        $this->startLiveUpdates($site, $run, $liveItems);
    }

    /**
     * @param  list<array<string,mixed>>  $updates
     */
    private function dispatchDryRun(Site $site, MaintenanceRun $run, array $updates): MaintenanceRun
    {
        $items = array_values(array_filter(array_map(static function ($u) {
            $type = (string) ($u['type'] ?? '');
            if (! in_array($type, ['plugin', 'theme', 'core'], true)) {
                return null;
            }

            return [
                'type' => $type,
                'slug' => $u['slug'] ?? '',
            ];
        }, $updates)));

        if ($items === []) {
            $run->update(['status' => 'completed', 'finished_at' => now()]);

            return $run->fresh();
        }

        \App\Jobs\CloneDryRunJob::dispatch($site->id, $items, $run->id);
        $run->update([
            'status' => 'dry_running',
            'plan' => array_merge($run->plan ?? [], ['dry_run_target' => 'clone']),
            'technician_notes' => trim(($run->technician_notes ?? '')."\nDry-Run in der isolierten Umgebung (".count($items).' Updates).'),
        ]);

        return $run->fresh();
    }

    /**
     * @param  array{ok?:bool,items?:list<array>,health_error?:string|null,ai_review?:array}  $report
     */
    public function afterCloneDryRun(string $runId, array $report): void
    {
        $run = MaintenanceRun::find($runId);
        if (! $run || $run->status !== 'dry_running') {
            return;
        }
        $site = $run->site;
        if (! $site) {
            return;
        }

        $ok = (bool) ($report['ok'] ?? false);
        $itemFails = collect($report['items'] ?? [])->filter(fn ($r) => ! ($r['ok'] ?? false))->values()->all();
        $review = $report['ai_review'] ?? null;

        if (! $ok || $itemFails !== []) {
            $hint = $report['health_error']
                ?? ($review['summary'] ?? null)
                ?? 'Dry-Run in der isolierten Umgebung nicht OK – Live gestoppt';
            $run->update([
                'status' => 'needs_review',
                'error' => $hint,
                'plan' => array_merge($run->plan ?? [], [
                    'dry_run_failures' => $itemFails,
                    'clone_review' => $review,
                ]),
                'finished_at' => now(),
                'technician_notes' => trim(($run->technician_notes ?? '')."\nDry-Run (isolierte Umgebung): Fehler."),
            ]);

            return;
        }

        $run->update([
            'technician_notes' => trim(($run->technician_notes ?? '')."\nDry-Run in der isolierten Umgebung OK"
                .(is_array($review) && ! empty($review['summary']) ? ': '.$review['summary'] : '.')),
            'plan' => array_merge($run->plan ?? [], ['clone_review' => $review]),
        ]);

        if (! $site->getHmacSecret()) {
            $run->update([
                'status' => 'needs_review',
                'error' => 'Dry-Run OK, aber Live-Site ist nicht verbunden.',
                'finished_at' => now(),
            ]);

            return;
        }

        $updates = $run->fresh()->plan['updates'] ?? [];
        $liveItems = array_map(static fn ($u) => [
            'type' => $u['type'],
            'slug' => $u['slug'] ?? '',
        ], $updates);
        $this->startLiveUpdates($site, $run->fresh(), $liveItems);
    }

    /**
     * @param  list<array{type:mixed,slug?:mixed}>  $liveItems
     */
    private function startLiveUpdates(Site $site, MaintenanceRun $run, array $liveItems): void
    {
        try {
            $liveJob = $this->dispatcher->dispatch($site, 'update_batch', [
                'mode' => 'live',
                'items' => $liveItems,
                'reason' => 'maintenance_agent',
                'maintenance_run_id' => $run->id,
            ], null);

            $run->update([
                'status' => 'applying',
                'live_job_id' => $liveJob->id,
                'technician_notes' => trim(($run->technician_notes ?? '')."\nDry-Run OK → Live-Updates gestartet."),
            ]);
            MaintenanceContinueJob::dispatch($run->id)->delay(now()->addMinutes(2));
        } catch (\Throwable $e) {
            $run->update([
                'status' => 'needs_review',
                'error' => 'Live-Dispatch fehlgeschlagen: '.$e->getMessage(),
                'finished_at' => now(),
            ]);
        }
    }

    public function buildAudit(Site $site): array
    {
        $inventory = $site->inventory ?? [];
        $plugins = collect($inventory['plugins'] ?? []);
        $themes = collect($inventory['themes'] ?? []);
        $activeTheme = (string) ($inventory['theme'] ?? $inventory['stylesheet'] ?? '');
        if ($activeTheme === '') {
            $active = $themes->firstWhere('active', true) ?: $themes->firstWhere('is_active', true);
            $activeTheme = (string) ($active['slug'] ?? $active['stylesheet'] ?? '');
        }

        $unusedPlugins = $plugins
            ->filter(fn ($p) => empty($p['active']) && empty($p['is_active']))
            ->map(fn ($p) => [
                'slug' => $p['slug'] ?? '',
                'name' => $p['name'] ?? ($p['slug'] ?? ''),
                'version' => $p['version'] ?? null,
                'reason' => 'inaktiv – vermutlich ungenutzt',
            ])
            ->values()
            ->all();

        $unusedThemes = $themes
            ->filter(function ($t) use ($activeTheme) {
                $slug = (string) ($t['slug'] ?? $t['stylesheet'] ?? '');
                if ($slug === '' || $slug === $activeTheme) {
                    return false;
                }
                // keep parent themes of active if marked
                if (! empty($t['active']) || ! empty($t['is_active'])) {
                    return false;
                }

                return true;
            })
            ->map(fn ($t) => [
                'slug' => $t['slug'] ?? $t['stylesheet'] ?? '',
                'name' => $t['name'] ?? '',
                'version' => $t['version'] ?? null,
                'reason' => 'nicht aktiv',
            ])
            ->values()
            ->all();

        $updates = $this->scanner->prioritizedUpdates($site);
        $openFindings = $site->findings()->where('status', 'open')->with('vulnerability')->get()->map(function ($f) {
            return [
                'id' => $f->id,
                'title' => $f->vulnerability?->title ?? 'Finding',
                'severity' => $f->vulnerability?->severity ?? 'unknown',
                'slug' => $f->vulnerability?->slug,
                'type' => $f->vulnerability?->component_type,
                'priority_score' => $f->priority_score ?: ($f->vulnerability?->priority_score ?? 0),
                'fixed_in' => $f->vulnerability?->fixed_in,
            ];
        })->values()->all();

        $failedLogins = 0;
        if (class_exists(\App\Models\SiteEvent::class)) {
            $failedLogins = SiteEvent::where('site_id', $site->id)
                ->where('type', 'login_failed')
                ->where('occurred_at', '>=', now()->subDay())
                ->count();
        }

        $risk = 0;
        $risk += min(40, count($openFindings) * 8);
        $risk += min(30, collect($updates)->sum(fn ($u) => (($u['priority_score'] ?? 0) >= 400 ? 10 : 4)));
        $risk += min(15, count($unusedPlugins) * 2);
        $risk += min(10, $failedLogins);
        if (version_compare((string) ($site->php_version ?: '8.0'), '8.1', '<')) {
            $risk += 15;
        }
        $risk = min(100, $risk);

        return [
            'checked_at' => now()->toIso8601String(),
            'site' => [
                'name' => $site->name,
                'url' => $site->url,
                'wp_version' => $site->wp_version,
                'php_version' => $site->php_version,
                'agent_version' => $site->agent_version,
                'status' => $site->status,
            ],
            'plugins' => [
                'total' => $plugins->count(),
                'active' => $plugins->filter(fn ($p) => ! empty($p['active']) || ! empty($p['is_active']))->count(),
                'inactive' => count($unusedPlugins),
            ],
            'themes' => [
                'total' => $themes->count(),
                'active' => $activeTheme ?: null,
                'inactive' => count($unusedThemes),
            ],
            'unused_plugins' => $unusedPlugins,
            'unused_themes' => $unusedThemes,
            'updates' => $updates,
            'security_findings' => $openFindings,
            'failed_logins_24h' => $failedLogins,
            'staging' => [
                'exists' => (bool) ($site->health['staging']['exists'] ?? false) || (bool) $site->staging_ready_at,
                'url' => $site->staging_url,
            ],
            'scores' => [
                'risk' => $risk,
                'health' => max(0, 100 - $risk),
            ],
        ];
    }

    public function buildPlan(Site $site, array $audit): array
    {
        $project = $site->project;
        $allowPlugins = ! $project || $project->allows('plugin_updates');
        $allowThemes = ! $project || $project->allows('theme_updates');
        $allowCore = ! $project || $project->allows('core_updates');

        $updates = [];
        foreach ($audit['updates'] ?? [] as $u) {
            $type = $u['type'] ?? 'plugin';
            if ($type === 'plugin' && ! $allowPlugins) {
                continue;
            }
            if ($type === 'theme' && ! $allowThemes) {
                continue;
            }
            if ($type === 'core' && ! $allowCore) {
                continue;
            }
            // Prefer security-relevant or any available update for technician maintenance
            $score = (int) ($u['priority_score'] ?? 0);
            $updates[] = [
                'type' => $type,
                'slug' => $u['slug'] ?? '',
                'name' => $u['name'] ?? $u['slug'] ?? '',
                'from' => $u['current'] ?? null,
                'to' => $u['update_to'] ?? null,
                'priority_score' => $score,
                'security' => $u['security'] ?? null,
                'action' => 'update',
                'dry_run_first' => $type !== 'core',
            ];
        }

        usort($updates, fn ($a, $b) => ($b['priority_score'] <=> $a['priority_score']));

        $recommendations = [];
        foreach ($audit['unused_plugins'] ?? [] as $p) {
            $recommendations[] = [
                'type' => 'cleanup',
                'target' => 'plugin',
                'slug' => $p['slug'],
                'name' => $p['name'],
                'action' => 'review_deactivate_or_remove',
                'reason' => $p['reason'],
            ];
        }
        foreach ($audit['unused_themes'] ?? [] as $t) {
            $recommendations[] = [
                'type' => 'cleanup',
                'target' => 'theme',
                'slug' => $t['slug'],
                'name' => $t['name'],
                'action' => 'review_remove',
                'reason' => $t['reason'],
            ];
        }

        return [
            'updates' => $updates,
            'recommendations' => $recommendations,
            'auto_apply_eligible' => $site->maintenance_auto_apply && $updates !== [],
            'requires_dry_run' => collect($updates)->contains(fn ($u) => ($u['type'] ?? '') !== 'core'),
        ];
    }

    private function notesFromAudit(array $audit, array $plan): string
    {
        $lines = [
            sprintf('Risiko-Score: %d/100 (Health %d)', $audit['scores']['risk'] ?? 0, $audit['scores']['health'] ?? 0),
            sprintf('Plugins aktiv/inaktiv: %d/%d', $audit['plugins']['active'] ?? 0, $audit['plugins']['inactive'] ?? 0),
            sprintf('Offene Security-Findings: %d', count($audit['security_findings'] ?? [])),
            sprintf('Geplante Updates: %d', count($plan['updates'] ?? [])),
            sprintf('Aufräum-Empfehlungen: %d', count($plan['recommendations'] ?? [])),
        ];

        return implode("\n", $lines);
    }

    private function summarizeHeuristic(Site $site, array $audit, array $plan): string
    {
        $risk = $audit['scores']['risk'] ?? 0;
        $upd = count($plan['updates'] ?? []);
        $unused = count($audit['unused_plugins'] ?? []);
        $findings = count($audit['security_findings'] ?? []);

        return sprintf(
            "Techniker-Audit für %s: Risiko %d/100. %d Update(s) empfohlen, %d Security-Finding(s), %d inaktive Plugin(s). ".
            ($upd > 0
                ? 'Vorgehen: Dry-Run auf Staging, bei OK Live-Updates.'
                : 'Keine Updates nötig – Monitoring fortsetzen.'),
            $site->name,
            $risk,
            $upd,
            $findings,
            $unused
        );
    }

    private function summarizeWithAi(Site $site, array $audit, array $plan): ?string
    {
        $key = config('wwc.ai_api_key');
        if (! $key) {
            return null;
        }

        try {
            $payload = [
                'site' => $audit['site'] ?? [],
                'scores' => $audit['scores'] ?? [],
                'plugins' => $audit['plugins'] ?? [],
                'unused_plugins' => array_slice($audit['unused_plugins'] ?? [], 0, 15),
                'unused_themes' => array_slice($audit['unused_themes'] ?? [], 0, 10),
                'updates' => array_slice($plan['updates'] ?? [], 0, 20),
                'security_findings' => array_slice($audit['security_findings'] ?? [], 0, 15),
                'failed_logins_24h' => $audit['failed_logins_24h'] ?? 0,
            ];

            $res = Http::timeout(25)
                ->withToken($key)
                ->post(rtrim((string) config('wwc.ai_api_base'), '/').'/chat/completions', [
                    'model' => config('wwc.ai_model'),
                    'temperature' => 0.2,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Du bist ein erfahrener WordPress-Wartungstechniker für eine Agentur. '
                                .'Schreibe auf Deutsch einen kurzen Audit-Bericht (max. 180 Wörter): Zustand, Risiken, '
                                .'ungenutzte Plugins/Themes, empfohlene Updates, und ob Dry-Run→Live sinnvoll ist. Kein Markdown.',
                        ],
                        [
                            'role' => 'user',
                            'content' => "Audit-Daten als JSON:\n".Str::limit(json_encode($payload, JSON_UNESCAPED_UNICODE), 10000),
                        ],
                    ],
                ]);

            if (! $res->successful()) {
                return null;
            }
            $content = data_get($res->json(), 'choices.0.message.content');

            return is_string($content) && trim($content) !== '' ? trim($content) : null;
        } catch (\Throwable $e) {
            Log::warning('maintenance AI summary failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function touchSchedule(Site $site): void
    {
        $cadence = $site->maintenance_cadence ?: 'weekly';
        $next = match ($cadence) {
            'daily' => now()->addDay(),
            'monthly' => now()->addMonth(),
            'off' => null,
            default => now()->addWeek(),
        };

        $site->forceFill([
            'maintenance_last_run_at' => now(),
            'maintenance_next_run_at' => $next,
        ])->save();
    }

    public function sitesDue(): \Illuminate\Support\Collection
    {
        return Site::query()
            ->where('maintenance_agent_enabled', true)
            ->where('maintenance_cadence', '!=', 'off')
            ->whereNotNull('hmac_secret_encrypted')
            ->where(function ($q) {
                $q->whereNull('maintenance_next_run_at')
                    ->orWhere('maintenance_next_run_at', '<=', now());
            })
            ->get();
    }

    public function payloadForSite(Site $site): array
    {
        $latest = $site->maintenanceRuns()->latest()->first();

        return [
            'enabled' => (bool) $site->maintenance_agent_enabled,
            'cadence' => $site->maintenance_cadence ?: 'weekly',
            'auto_apply' => (bool) $site->maintenance_auto_apply,
            'last_run_at' => $site->maintenance_last_run_at,
            'next_run_at' => $site->maintenance_next_run_at,
            'latest_run' => $latest,
        ];
    }
}
