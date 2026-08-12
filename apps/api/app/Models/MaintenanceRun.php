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

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
