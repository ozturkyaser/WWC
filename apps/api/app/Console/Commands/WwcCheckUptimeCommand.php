<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\AlertService;
use App\Services\UptimeProbeService;
use Illuminate\Console\Command;

class WwcCheckUptimeCommand extends Command
{
    protected $signature = 'wwc:check-uptime';

    protected $description = 'Prüft öffentliche URL, SSL und PHP/WP-EOL aller Sites';

    public function handle(UptimeProbeService $probe, AlertService $alerts): int
    {
        $sites = Site::query()->whereNotNull('url')->with('project')->get();
        $down = 0;

        foreach ($sites as $site) {
            if ($site->project && ! $site->project->allows('uptime')) {
                continue;
            }

            $before = $site->monitor['http_ok'] ?? null;
            $monitor = $probe->probe($site);
            if (($monitor['http_ok'] ?? true) === false) {
                $down++;
                if ($before !== false) {
                    $alerts->notify(
                        $site->organization,
                        'http_down',
                        'Website nicht erreichbar: '.$site->name,
                        [
                            $site->url,
                            $monitor['error'] ?? ('HTTP '.($monitor['http_status'] ?? '?')),
                        ],
                        '/sites/'.$site->id,
                        'error',
                        'http_down:'.$site->id,
                        $site,
                        180
                    );
                }
            }
            $this->line(sprintf(
                '%s  HTTP %s  %sms  SSL %s',
                $site->name,
                $monitor['http_status'] ?? '-',
                $monitor['response_ms'] ?? '-',
                $monitor['ssl_days'] !== null ? $monitor['ssl_days'].'d' : '-'
            ));
        }

        $this->info($sites->count().' Sites geprüft, '.$down.' nicht erreichbar.');

        return self::SUCCESS;
    }
}
