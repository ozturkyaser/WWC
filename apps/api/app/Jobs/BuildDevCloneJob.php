<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\DevCloneService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BuildDevCloneJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1600;

    /** @param bool $verifyOnly Nur Restore-Test: Clone nach erfolgreichem Bau wieder abbauen */
    public function __construct(public string $siteId, public bool $verifyOnly = false) {}

    public function handle(DevCloneService $clones): void
    {
        $site = Site::find($this->siteId);
        if (! $site) {
            return;
        }
        $clones->build($site);

        if ($this->verifyOnly && (($site->fresh()->dev_clone['status'] ?? '') === 'ready')) {
            $clones->destroy($site->fresh());
        }
    }

    public function failed(?\Throwable $e): void
    {
        Site::where('id', $this->siteId)->first()?->update([
            'dev_clone' => array_merge(
                Site::find($this->siteId)?->dev_clone ?? [],
                ['status' => 'failed', 'error' => mb_substr($e?->getMessage() ?? 'Build abgebrochen', 0, 500)]
            ),
        ]);
    }
}
