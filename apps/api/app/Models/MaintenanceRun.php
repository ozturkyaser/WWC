<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id', 'site_id', 'trigger', 'status',
        'audit', 'plan', 'ai_summary', 'technician_notes',
        'dry_run_job_id', 'live_job_id', 'staging_job_id',
        'error', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'audit' => 'array',
            'plan' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Benachrichtigung, sobald ein Wartungslauf manuelles Eingreifen braucht oder fehlschlaegt
        static::updated(function (MaintenanceRun $run) {
            if (! $run->wasChanged('status') || ! in_array($run->status, ['needs_review', 'failed'], true)) {
                return;
            }
            $site = $run->site;
            if (! $site) {
                return;
            }

            $isFailed = $run->status === 'failed';
            app(\App\Services\AlertService::class)->notify(
                $site->organization,
                'maintenance_'.$run->status,
                ($isFailed ? 'KI-Wartung fehlgeschlagen: ' : 'KI-Wartung braucht Review: ').$site->name,
                array_filter([
                    "Der Wartungslauf ({$run->trigger}) fuer \"{$site->name}\" hat den Status \"{$run->status}\".",
                    $run->error ? "Fehler: {$run->error}" : null,
                    $isFailed ? null : 'Bitte im Portal pruefen und die geplanten Updates freigeben oder verwerfen.',
                ]),
                "/sites/{$site->id}",
                $isFailed ? 'error' : 'warning',
                "maintenance:{$run->id}:{$run->status}",
                $site
            );
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
