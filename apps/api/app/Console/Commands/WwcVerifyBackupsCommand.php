<?php

namespace App\Console\Commands;

use App\Jobs\BuildDevCloneJob;
use App\Models\Site;
use App\Services\DevCloneService;
use Illuminate\Console\Command;

/**
 * Woechentlicher Restore-Test (backup_verify im Tarif): Baut fuer berechtigte
 * Sites einen Dev-Clone aus dem letzten Backup. Gelingt der Bau, ist die
 * Wiederherstellbarkeit bewiesen (verified_at). Bestand vorher kein Clone,
 * wird er danach wieder abgebaut.
 */
class WwcVerifyBackupsCommand extends Command
{
    protected $signature = 'wwc:verify-backups';

    protected $description = 'Prüft Backups per Restore-Test im Dev-Clone (backup_verify)';

    public function handle(DevCloneService $clones): int
    {
        $sites = Site::query()
            ->whereNotNull('paired_at')
            ->with('project')
            ->get()
            ->filter(fn (Site $site) => $site->project?->allows('backup_verify') ?? false);

        $queued = 0;
        foreach ($sites as $site) {
            if (! $clones->latestUsableBackup($site)) {
                continue;
            }
            if (($site->dev_clone['status'] ?? '') === 'building') {
                continue;
            }

            $hadClone = ($site->dev_clone['status'] ?? '') === 'ready';
            BuildDevCloneJob::dispatch($site->id, verifyOnly: ! $hadClone);
            $queued++;
            $this->info("{$site->name}: Restore-Test eingeplant".($hadClone ? ' (Clone wird aktualisiert)' : ''));
        }

        $this->info("{$queued} Restore-Test(s) eingeplant.");

        return self::SUCCESS;
    }
}
