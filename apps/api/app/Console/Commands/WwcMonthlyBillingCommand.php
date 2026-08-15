<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\InvoiceService;
use App\Services\VulnerabilityScanner;
use Illuminate\Console\Command;

class WwcMonthlyBillingCommand extends Command
{
    protected $signature = 'wwc:bill-monthly';

    protected $description = 'Generate monthly invoices for active projects and email clients';

    public function handle(InvoiceService $service): int
    {
        $count = 0;
        $mailed = 0;
        Project::where('active', true)->with(['organization', 'client'])->each(function (Project $project) use ($service, &$count, &$mailed) {
            if ((int) $project->monthly_budget_cents <= 0) {
                $this->warn("Skip {$project->name}: kein Monatspreis");

                return;
            }
            $invoice = $service->generateMonthly($project, null, false);
            if ((bool) (($project->organization->billing_profile['auto_send_invoices'] ?? false))) {
                $service->send($invoice);
            }
            $count++;
            if ($project->client?->email) {
                $mailed++;
            }
            $this->info("Invoice {$invoice->number} for {$project->name}");
        });
        $this->info("Done: {$count} invoices, {$mailed} with client email");

        return self::SUCCESS;
    }
}
