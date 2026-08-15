<?php

namespace App\Console\Commands;

use App\Models\AgentJob;
use App\Models\Site;
use App\Services\AlertService;
use Illuminate\Console\Command;

/**
 * Markiert Sites als offline, deren Agent sich laenger nicht gemeldet hat,
 * und verschickt eine Benachrichtigung an die Organisation.
 */
class WwcCheckHeartbeatsCommand extends Command
{
    protected $signature = 'wwc:check-heartbeats';

    protected $description = 'Erkennt Sites ohne aktuellen Heartbeat und markiert sie als offline';

    public function handle(AlertService $alerts): int
    {
        $threshold = now()->subMinutes((int) config('wwc.offline_after_minutes', 15));

        $stale = Site::query()
            ->where('status', 'online')
            ->whereNotNull('paired_at')
            ->where(function ($q) use ($threshold) {
                $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $threshold);
            })
            ->get();

        foreach ($stale as $site) {
            $site->update(['status' => 'offline']);
            $alerts->siteOffline($site);
            $this->warn("offline: {$site->name} ({$site->url})");
        }

        $this->info(sprintf('%d Site(s) als offline markiert.', $stale->count()));

        $this->failStuckJobs();

        return self::SUCCESS;
    }

    /**
     * Watchdog: Jobs, die sich seit ueber 90 Minuten nicht gemeldet haben,
     * werden als fehlgeschlagen markiert, damit Wartungslaeufe und UI nicht
     * ewig auf ein Ergebnis warten.
     */
    private function failStuckJobs(): void
    {
        $stuck = AgentJob::query()
            ->whereIn('status', ['pending', 'running'])
            ->where('updated_at', '<', now()->subMinutes(90))
            ->get();

        foreach ($stuck as $job) {
            $job->update([
                'status' => 'failed',
                'error' => 'Zeitüberschreitung: keine Rückmeldung vom Agent (Watchdog)',
                'finished_at' => now(),
            ]);
            $this->warn("Job {$job->id} ({$job->command}) als fehlgeschlagen markiert (Watchdog).");
        }
    }
}
