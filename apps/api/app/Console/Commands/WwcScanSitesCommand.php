<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\VulnerabilityScanner;
use Illuminate\Console\Command;

class WwcScanSitesCommand extends Command
{
    protected $signature = 'wwc:scan-sites {--skip-patchstack : Do not refresh Patchstack first}';

    protected $description = 'Sync advisories (Patchstack) and scan all paired sites';

    public function handle(VulnerabilityScanner $scanner): int
    {
        if (! $this->option('skip-patchstack')) {
            $result = $scanner->syncPatchstack(true, 100);
            $this->info('Patchstack upserted '.$result['upserted']);
        }
        $scanner->syncWordPressOrgAdvisories();

        Site::whereNotNull('paired_at')->each(function (Site $site) use ($scanner) {
            $findings = $scanner->scanSite($site);
            $this->info("{$site->name}: ".count($findings).' findings');
        });

        return self::SUCCESS;
    }
}
