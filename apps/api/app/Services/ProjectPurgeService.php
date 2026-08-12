<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Project;
use App\Models\Site;
use Illuminate\Support\Facades\Log;

class ProjectPurgeService
{
    public function __construct(
        private AgentClient $agent,
        private PairingService $pairing,
    ) {}

    /**
     * Purge remote WWC data (staging + backups), then delete all portal records for the project.
     */
    public function destroyCompletely(Project $project): array
    {
        $project->load(['sites', 'client']);
        $remote = [];

        foreach ($project->sites as $site) {
            $remote[$site->id] = $this->purgeRemoteSite($site);
            $this->pairing->disconnect($site);
            $site->delete();
        }

        $invoiceCount = Invoice::where('project_id', $project->id)->count();
        Invoice::where('project_id', $project->id)->each(function (Invoice $invoice) {
            if ($invoice->pdf_path) {
                \Illuminate\Support\Facades\Storage::disk('local')->delete($invoice->pdf_path);
            }
            $invoice->items()->delete();
            $invoice->delete();
        });

        $name = $project->name;
        $id = $project->id;
        $project->delete();

        return [
            'ok' => true,
            'project_id' => $id,
            'project_name' => $name,
            'invoices_deleted' => $invoiceCount,
            'remote' => $remote,
        ];
    }

    public function purgeRemoteSite(Site $site): array
    {
        if (! $site->getHmacSecret()) {
            return ['ok' => true, 'skipped' => true, 'reason' => 'not_paired'];
        }

        try {
            $result = $this->agent->executeSync($site, 'purge_wwc', []);

            return ['ok' => true, 'result' => $result];
        } catch (\Throwable $e) {
            Log::warning('Remote purge failed on project delete', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
