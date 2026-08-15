<?php

namespace App\Services;

use App\Models\AgentJob;
use App\Models\MaintenanceRun;
use App\Models\Site;
use App\Models\SiteBackup;
use App\Models\SiteEvent;
use App\Models\TimeEntry;
use App\Models\VulnerabilityFinding;
use Carbon\Carbon;

class OpsBoardService
{
    public function __construct(private HardeningPolicy $hardening)
    {
    }

    public function build(string $orgId): array
    {
        $sites = Site::where('organization_id', $orgId)->with('project')->get();
        $queue = [];

        foreach ($sites as $site) {
            $queue = array_merge($queue, $this->itemsForSite($site));
        }

        $reviews = MaintenanceRun::where('organization_id', $orgId)
            ->where('status', 'needs_review')
            ->with('site:id,name,url')
            ->latest()
            ->limit(30)
            ->get();

        foreach ($reviews as $run) {
            $queue[] = [
                'severity' => 'warning',
                'kind' => 'review',
                'title' => 'KI-Wartung braucht Review',
                'detail' => $run->site?->name,
                'site_id' => $run->site_id,
                'href' => '/reviews',
            ];
        }

        usort($queue, fn ($a, $b) => $this->rank($a['severity']) <=> $this->rank($b['severity']));

        $hoursIncluded = $sites->pluck('project')->filter()->unique('id')->sum(
            fn ($p) => (float) (($p->scope['hours_included'] ?? 0))
        );
        $minutesUsed = (int) TimeEntry::where('organization_id', $orgId)
            ->where('occurred_at', '>=', now()->startOfMonth())
            ->sum('minutes');

        return [
            'sites_total' => $sites->count(),
            'sites_online' => $sites->where('status', 'online')->count(),
            'sites_offline' => $sites->whereIn('status', ['offline', 'error', 'pending'])->count(),
            'http_down' => $sites->filter(fn (Site $s) => ($s->monitor['http_ok'] ?? true) === false)->count(),
            'ssl_expiring' => $sites->filter(fn (Site $s) => ($s->monitor['ssl_days'] ?? 99) < 21)->count(),
            'eol' => $sites->filter(function (Site $s) {
                return ($s->monitor['php']['status'] ?? '') === 'eol'
                    || ($s->monitor['wp']['status'] ?? '') === 'eol';
            })->count(),
            'backup_unhealthy' => collect($queue)->where('kind', 'backup')->count(),
            'hardening_drift' => collect($queue)->where('kind', 'hardening')->count(),
            'open_vulnerabilities' => VulnerabilityFinding::where('organization_id', $orgId)->where('status', 'open')->count(),
            'failed_logins_24h' => SiteEvent::where('organization_id', $orgId)
                ->where('type', 'login_failed')
                ->where('occurred_at', '>=', now()->subDay())
                ->count(),
            'needs_review' => $reviews->count(),
            'hours_included_month' => $hoursIncluded,
            'hours_used_month' => round($minutesUsed / 60, 1),
            'queue' => array_values($queue),
            'recent_events' => SiteEvent::where('organization_id', $orgId)->latest('occurred_at')->limit(20)->get(),
            'active_jobs' => AgentJob::where('organization_id', $orgId)
                ->whereIn('status', ['pending', 'running'])
                ->with('site:id,name')
                ->latest()
                ->limit(12)
                ->get()
                ->map(function (AgentJob $job) {
                    $row = JobProgress::enrich($job);
                    $row['site_name'] = $job->site?->name;

                    return $row;
                }),
            'release' => app(ReleaseService::class)->status(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function itemsForSite(Site $site): array
    {
        $items = [];
        $href = '/sites/'.$site->id;

        if (in_array($site->status, ['offline', 'error'], true)) {
            $items[] = $this->item('error', 'offline', 'Site offline', $site->name, $site->id, $href);
        }

        $mon = $site->monitor ?? [];
        if (($mon['http_ok'] ?? true) === false) {
            $items[] = $this->item('error', 'uptime', 'HTTP nicht erreichbar', $site->name.' · '.($mon['error'] ?? $mon['http_status'] ?? ''), $site->id, $href);
        }
        if (isset($mon['ssl_days']) && $mon['ssl_days'] !== null && $mon['ssl_days'] < 21) {
            $items[] = $this->item(
                $mon['ssl_days'] < 7 ? 'error' : 'warning',
                'ssl',
                'SSL läuft ab',
                $site->name.' · noch '.$mon['ssl_days'].' Tage',
                $site->id,
                $href
            );
        }
        if (($mon['php']['status'] ?? '') === 'eol') {
            $items[] = $this->item('warning', 'eol', 'PHP am End-of-Life', $site->name.' · PHP '.($mon['php']['version'] ?? '?'), $site->id, $href);
        }
        if (($mon['wp']['status'] ?? '') === 'eol') {
            $items[] = $this->item('warning', 'eol', 'WordPress veraltet', $site->name.' · WP '.($mon['wp']['version'] ?? '?'), $site->id, $href);
        }

        if ($site->freeze_until && Carbon::parse($site->freeze_until)->isFuture()) {
            $items[] = $this->item('info', 'freeze', 'Update-Freeze aktiv', $site->name.' · '.$site->freeze_reason, $site->id, $href);
        }

        $latest = SiteBackup::where('site_id', $site->id)->where('status', 'complete')->orderByDesc('backup_created_at')->first();
        if ($site->paired_at && (! $latest || $latest->backup_created_at < now()->subDays(3))) {
            $items[] = $this->item('warning', 'backup', 'Kein aktuelles Backup', $site->name, $site->id, $href.'?tab=backups');
        } elseif ($latest && ! $latest->verified_at && $site->project?->allows('backup_verify')) {
            $items[] = $this->item('info', 'backup', 'Backup nicht verifiziert', $site->name, $site->id, $href.'?tab=backups');
        }

        $failedBackup = AgentJob::where('site_id', $site->id)
            ->whereIn('command', ['backup_full', 'backup_incremental'])
            ->where('status', 'failed')
            ->where('finished_at', '>=', now()->subDay())
            ->exists();
        if ($failedBackup) {
            $items[] = $this->item('error', 'backup', 'Backup fehlgeschlagen', $site->name, $site->id, $href.'?tab=backups');
        }

        $drift = $this->hardening->drift($site);
        if ($drift !== []) {
            $items[] = $this->item('warning', 'hardening', 'Härtung weicht vom Tarif ab', $site->name.' · '.count($drift).' Maßnahmen', $site->id, $href.'?tab=hardening');
        }

        $high = VulnerabilityFinding::where('site_id', $site->id)->where('status', 'open')->where('priority_score', '>=', 70)->count();
        if ($high > 0) {
            $items[] = $this->item('error', 'cve', $high.' kritische Schwachstellen', $site->name, $site->id, '/security');
        }

        return $items;
    }

    private function item(string $severity, string $kind, string $title, ?string $detail, ?string $siteId, string $href): array
    {
        return compact('severity', 'kind', 'title', 'detail', 'siteId', 'href') + ['site_id' => $siteId];
    }

    private function rank(string $severity): int
    {
        return match ($severity) {
            'error' => 0,
            'warning' => 1,
            default => 2,
        };
    }
}
