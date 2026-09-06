<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\DevCloneService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PromoteDevCloneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public string $siteId) {}

    public function handle(DevCloneService $clones): void
    {
        $site = Site::find($this->siteId);
        if (! $site) {
            return;
        }

        try {
            $clones->dispatchPromoteApply($site);
        } catch (\Throwable $e) {
            Log::error('Clone promote package failed', ['site' => $site->id, 'error' => $e->getMessage()]);
            $clones->finishPromote($site, false, $e->getMessage());
        }
    }
}
