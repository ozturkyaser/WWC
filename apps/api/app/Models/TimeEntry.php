<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeEntry extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id', 'project_id', 'site_id', 'user_id',
        'minutes', 'description', 'billable', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'billable' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
