<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\DevCloneService;
use App\Services\MaintenanceAgentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CloneDryRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1600;

    /** @param list<array{type:string,slug:string}> $items */
    public function __construct(public string $siteId, public array $items, public ?string $maintenanceRunId = null) {}

    public function handle(DevCloneService $clones): void
    {
        $site = Site::find($this->siteId);
        if (! $site) {
            return;
        }

        $report = null;
        try {
            $report = $clones->dryRun($site, $this->items);
        } catch (\Throwable $e) {
            Log::warning('Clone dry-run failed', ['site' => $site->id, 'error' => $e->getMessage()]);
            $report = [
                'at' => now()->toIso8601String(),
                'items' => [],
                'ok' => false,
                'site_ok' => false,
                'health_error' => mb_substr($e->getMessage(), 0, 300),
            ];
            $site->update(['dev_clone' => array_merge($site->fresh()->dev_clone ?? [], [
                'last_dry_run' => $report,
            ])]);
        }

        if ($this->maintenanceRunId) {
            app(MaintenanceAgentService::class)->afterCloneDryRun($this->maintenanceRunId, $report ?? []);
        }
    }
}
