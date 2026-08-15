<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\AgentDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Fuehrt geplante Backups aus. Standard-Strategie pro Site:
 * woechentlich ein Voll-Backup, an den uebrigen Tagen inkrementell.
 * Die Startzeit wird pro Site leicht versetzt (Stagger), damit nicht
 * alle Kundenserver gleichzeitig belastet werden.
 */
class WwcRunBackupsCommand extends Command
{
    protected $signature = 'wwc:run-backups';

    protected $description = 'Startet faellige geplante Backups (nachts, versetzt pro Site)';

    public function handle(AgentDispatcher $dispatcher): int
    {
        $sites = Site::query()
            ->whereNotNull('paired_at')
            ->whereNotNull('backup_schedule')
            ->get()
            ->filter(fn (Site $site) => (bool) (($site->backup_schedule ?? [])['enabled'] ?? false));

        $started = 0;
        foreach ($sites as $site) {
            $schedule = $site->backup_schedule ?? [];
            $time = (string) ($schedule['time'] ?? '02:30');
            [$hour, $minute] = array_map('intval', array_pad(explode(':', $time), 2, 0));

            // Stagger: deterministischer Versatz von 0-19 Minuten pro Site
            $offset = crc32($site->id) % 20;
            $due = now()->startOfDay()->addHours($hour)->addMinutes($minute + $offset);

            if (now()->lt($due)) {
                continue;
            }
            if ($site->backup_last_scheduled_at && $site->backup_last_scheduled_at->gte($due)) {
                continue; // heute bereits gestartet
            }
            if ($site->status !== 'online') {
                Log::info('Scheduled backup skipped, site not online', ['site' => $site->id]);

                continue;
            }
            $hasActiveBackup = $site->jobs()
                ->whereIn('status', ['pending', 'running'])
                ->whereIn('command', ['backup_full', 'backup_incremental'])
                ->exists();
            if ($hasActiveBackup) {
                continue;
            }

            $command = $this->chooseType($site, $schedule);
            if ($command === null) {
                continue;
            }

            try {
                $dispatcher->dispatch($site, $command, ['reason' => 'scheduled']);
                $site->update(['backup_last_scheduled_at' => now()]);
                $started++;
                $this->info("{$site->name}: {$command} gestartet");
            } catch (\Throwable $e) {
                Log::warning('Scheduled backup dispatch failed', ['site' => $site->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("{$started} geplante(s) Backup(s) gestartet.");

        return self::SUCCESS;
    }

    /** @param array<string,mixed> $schedule */
    private function chooseType(Site $site, array $schedule): ?string
    {
        $fullDay = (int) ($schedule['weekly_full_day'] ?? 0); // 0 = Sonntag
        $incrementalDaily = (bool) ($schedule['incremental_daily'] ?? true);

        // Ohne vorhandenes Voll-Backup auf dem Server ist inkrementell nicht sinnvoll
        $hasStoredFull = $site->serverBackups()
            ->where('type', 'full')
            ->where('status', 'stored')
            ->exists();

        if ((int) now()->dayOfWeek === $fullDay || ! $hasStoredFull) {
            return 'backup_full';
        }

        return $incrementalDaily ? 'backup_incremental' : null;
    }
}
