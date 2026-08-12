<?php

namespace App\Console\Commands;

use App\Services\MaintenanceAgentService;
use Illuminate\Console\Command;

class WwcRunMaintenanceCommand extends Command
{
    protected $signature = 'wwc:run-maintenance
        {--site= : Optional site UUID}
        {--force : Ignore schedule / run even if not due}
        {--execute : Force execute dry-run→live (overrides site auto_apply for this run)}
        {--audit-only : Only audit, never auto-apply}';

    protected $description = 'Run per-site maintenance AI agent (audit → dry-run → live if enabled)';

    public function handle(MaintenanceAgentService $agent): int
    {
        $siteId = $this->option('site');
        if ($siteId) {
            $site = \App\Models\Site::findOrFail($siteId);
            $sites = collect([$site]);
        } elseif ($this->option('force')) {
            $sites = \App\Models\Site::query()
                ->where('maintenance_agent_enabled', true)
                ->whereNotNull('hmac_secret_encrypted')
                ->get();
        } else {
            $sites = $agent->sitesDue();
        }

        if ($sites->isEmpty()) {
            $this->info('No sites due for maintenance.');

            return self::SUCCESS;
        }

        foreach ($sites as $site) {
            $execute = $this->option('audit-only')
                ? false
                : ($this->option('execute') ? true : null);

            $opts = ['trigger' => $siteId ? 'manual' : ($site->maintenance_cadence ?: 'schedule')];
            if ($execute !== null) {
                $opts['execute'] = $execute;
            }

            $run = $agent->run($site, $opts);
            $this->info(sprintf(
                '%s → %s (updates=%d risk=%s)',
                $site->name,
                $run->status,
                count($run->plan['updates'] ?? []),
                $run->audit['scores']['risk'] ?? '?'
            ));
        }

        return self::SUCCESS;
    }
}
