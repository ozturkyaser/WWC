<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Site;
use App\Services\VulnerabilityScanner;
use Illuminate\Console\Command;

class WwcSyncPatchstackCommand extends Command
{
    protected $signature = 'wwc:sync-patchstack
        {--pages=100 : Max public database pages to attempt (1-100)}
        {--full : Full ingest (ignore incremental day cutoff)}
        {--scan : Rescan all paired sites after sync}';

    protected $description = 'Sync Patchstack vulnerability database and optionally rescan sites';

    public function handle(VulnerabilityScanner $scanner): int
    {
        $pages = (int) $this->option('pages');
        $incremental = ! $this->option('full');
        $this->info('Syncing Patchstack (pages≤'.$pages.', '.($incremental ? 'incremental' : 'full').')…');

        $org = Organization::query()->whereNotNull('patchstack_api_key')->first();
        $result = $scanner->syncPatchstack($incremental, $pages, $org);
        $this->info('Upserted '.$result['upserted'].' advisories (unique fetched ≈ '.$result['unique'].')');
        $this->line(json_encode($result['sources'] ?? [], JSON_UNESCAPED_SLASHES));

        // Keep curated samples available as fallback.
        $scanner->syncWordPressOrgAdvisories();

        if ($this->option('scan')) {
            Site::whereNotNull('paired_at')->each(function (Site $site) use ($scanner) {
                $findings = $scanner->scanSite($site);
                $this->info($site->name.': '.count($findings).' findings');
            });
        }

        return self::SUCCESS;
    }
}
