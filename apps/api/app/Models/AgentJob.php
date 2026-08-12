<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentJob extends Model
{
    use HasUuids;

    public const ALLOWED_COMMANDS = [
        'ping',
        'inventory',
        'update_plugin',
        'update_theme',
        'update_core',
        'run_scan',
        'self_update',
        'backup_full',
        'backup_incremental',
        'restore_backup',
        'list_backups',
        'delete_backup',
        'purge_wwc',
        'staging_create',
        'staging_destroy',
        'staging_status',
        'staging_update_plugin',
        'staging_update_theme',
        'update_batch',
        'staging_promote',
        'staging_grant_admin',
    ];

    protected $fillable = [
        'organization_id', 'site_id', 'user_id', 'command', 'payload',
        'status', 'progress', 'progress_label', 'progress_log', 'result', 'error',
        'attempts', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'progress_log' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
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
