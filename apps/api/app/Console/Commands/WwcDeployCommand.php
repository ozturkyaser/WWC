<?php

namespace App\Console\Commands;

use App\Services\ReleaseService;
use Illuminate\Console\Command;

class WwcDeployCommand extends Command
{
    protected $signature = 'wwc:deploy {--force : origin/main hart übernehmen}';

    protected $description = 'Holt den aktuellen Stand von Git und spielt Migrationen ein';

    public function handle(ReleaseService $release): int
    {
        $result = $release->deploy((bool) $this->option('force'));
        foreach ($result['log'] as $line) {
            $this->line($line);
        }
        if ($result['ok']) {
            $this->info($result['message']);

            return self::SUCCESS;
        }
        $this->error($result['message']);

        return self::FAILURE;
    }
}
